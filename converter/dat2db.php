<?php 
// PHP拡張のMeCabモジュールが利用可能かチェック
if (!class_exists('MeCab_Tagger')) {
    echo "警告: PHP用MeCab拡張モジュールが利用できません。カスタムトークナイザーを使用します。\n";
    $use_default_tokenizer = true;
} else {
    $use_default_tokenizer = false;
}

/**
 * テキストを分かち書きする関数
 *
 * MeCab拡張モジュールが利用できる場合はMeCabを使用し、
 * 利用できない場合は以下のカスタム実装により、  
 * 空白文字・句読点で区切るunicode61に加え、  
 * 漢字、ひらがな、カタカナ、全角英字・全角数字、半角英字、半角数字、ASCII記号などの区切りでトークン分割する
 *
 * @param string $text 解析対象のテキスト
 * @return string 分かち書き済みテキスト（各トークンはスペース区切り）
 */
function tokenizeText($text) {
    global $use_default_tokenizer;
    if ($use_default_tokenizer) {
        // カスタムトークナイザー実装
        // まず前後の空白を除去
        $text = trim($text);
        // 以下の正規表現は、各文字種ごとの連続部分をひとまとまりのトークンとする
        // ・\p{Han}      : 漢字
        // ・\p{Hiragana} : ひらがな
        // ・\p{Katakana} : カタカナ
        // ・[Ａ-Ｚａ-ｚ]+: 全角英字（※全角小文字・大文字）
        // ・[０-９]+    : 全角数字
        // ・[A-Za-z]+   : 半角英字
        // ・[0-9]+      : 半角数字
        // ・[\p{P}\p{S}]+ : Unicode上の句読点・記号（ASCII含む）
        $pattern = '/
            (\p{Han}+)         |   # 漢字
            (\p{Hiragana}+)    |   # ひらがな
            (\p{Katakana}+)    |   # カタカナ
            ([Ａ-Ｚａ-ｚ]+)     |   # 全角英字
            ([０-９]+)         |   # 全角数字
            ([A-Za-z]+)        |   # 半角英字
            ([0-9]+)           |   # 半角数字
            ([\p{P}\p{S}]+)        # 句読点・記号（ASCII含む）
        /xu';
        preg_match_all($pattern, $text, $matches);
        $tokens = $matches[0];
        return implode(' ', $tokens);
    } else {
        // MeCabが利用可能な場合はMeCabを使用
        $tagger = new MeCab_Tagger();
        $node = $tagger->parseToNode($text);
        $tokens = [];
        // BOS/EOSノードは除外
        for (; $node; $node = $node->getNext()) {
            if ($node->getStat() == MeCab::MECAB_BOS_NODE || $node->getStat() == MeCab::MECAB_EOS_NODE) {
                continue;
            }
            $tokens[] = $node->getSurface();
        }
        return implode(' ', $tokens);
    }
}

/* 以下は従来のデータベース接続、テーブル作成、FTSインデックス作成等の処理 */

// データベース接続の設定
$db = new SQLite3('../bbs_log.db');

// 各テーブルの作成
$db->exec("CREATE TABLE IF NOT EXISTS Boards (
    board_id TEXT PRIMARY KEY,
    board_name TEXT,
    category_id TEXT,
    category_name TEXT,
    thread_count INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS Threads (
    board_id TEXT,
    thread_id INTEGER,
    title TEXT,
    response_count INTEGER DEFAULT 0,
    PRIMARY KEY (board_id, thread_id),
    FOREIGN KEY (board_id) REFERENCES Boards(board_id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS Posts (
    board_id TEXT,
    thread_id INTEGER,
    post_order INTEGER,
    name TEXT,
    mail TEXT,
    date TEXT,
    time TEXT,
    id TEXT,
    message TEXT,  -- ユーザー表示用の元のメッセージ
    PRIMARY KEY (board_id, thread_id, post_order),
    FOREIGN KEY (board_id) REFERENCES Boards(board_id),
    FOREIGN KEY (board_id, thread_id) REFERENCES Threads(board_id, thread_id)
)");

// FTS仮想テーブルの作成（FTS5を使用）
// 形態素解析済みのテキストを登録し、全文検索用とする
$db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS Posts_fts USING fts5(
    board_id,
    thread_id,
    post_order,
    message,
    name,
    id,
    thread_title
)");

// プログレスバーを表示する関数（掲示板読み込み用）
function show_progress($done, $total, $board_id, &$start_time, $size = 30) {
    if ($done > $total) return;
    if ($start_time === null) $start_time = time();

    $perc = (double)($done / $total);
    $bar = floor($perc * $size);

    $status_bar = "\r掲示板: $board_id を読み込み中・・・[";
    $status_bar .= str_repeat("=", $bar);
    if ($bar < $size) {
        $status_bar .= ">" . str_repeat(" ", $size - $bar);
    } else {
        $status_bar .= "=";
    }

    $disp = number_format($perc * 100, 0);
    $status_bar .= "] $disp%  $done/$total";

    if ($done == $total) {
        $status_bar .= " 完了\n";
    }
    echo "$status_bar  ";
    flush();
}

// プログレスバーを表示する関数（FTSインデックス作成用）
function show_progress_fts($done, $total, &$start_time, $size = 30) {
    if ($done > $total) return;
    if ($start_time === null) $start_time = time();

    $perc = (double)($done / $total);
    $bar = floor($perc * $size);

    $status_bar = "\rFTSインデックス作成中・・・[";
    $status_bar .= str_repeat("=", $bar);
    if ($bar < $size) {
        $status_bar .= ">" . str_repeat(" ", $size - $bar);
    } else {
        $status_bar .= "=";
    }

    $disp = number_format($perc * 100, 0);
    $status_bar .= "] $disp%  $done/$total";

    if ($done == $total) {
        $status_bar .= " 完了\n";
    }
    echo "$status_bar  ";
    flush();
}

// 設定ファイルのパス（board_config.phpの新形式に対応）
$config_file = 'board_config.php';
if (!file_exists($config_file)) {
    exit("エラー: 設定ファイル '{$config_file}' が存在しません。設定ファイルを作成してください。\n");
}
$board_config = include $config_file;
if (!is_array($board_config)) {
    exit("エラー: 設定ファイルの形式が正しくありません。\n");
}

// categoriesフォルダ内の各カテゴリフォルダを取得
$base_dir = './categories/';
$category_dirs = glob($base_dir . '*', GLOB_ONLYDIR);
if (empty($category_dirs)) {
    echo "カテゴリフォルダが存在しません。\n";
    exit;
}

// 各カテゴリごとに処理（トランザクションを利用）
foreach ($category_dirs as $category_dir) {
    $category_id = basename($category_dir);
    echo "カテゴリ: $category_id を処理中...\n";
    
    // カテゴリ内の掲示板フォルダを取得
    $board_dirs = glob($category_dir . '/*', GLOB_ONLYDIR);
    if (empty($board_dirs)) {
        echo "$category_id 内に掲示板フォルダが存在しません。\n";
        continue;
    }
    
    // トランザクション開始（カテゴリ単位）
    $db->exec("BEGIN TRANSACTION");
    
    foreach ($board_dirs as $board_dir) {
        $board_id = basename($board_dir);
        
        // 'dat'および'test'フォルダはスキップ
        if ($board_id === 'dat' || $board_id === 'test') {
            echo "$board_id フォルダはパスされます。\n";
            continue;
        }
        
        // board_configから掲示板名とカテゴリ名の取得
        if (isset($board_config[$category_id])) {
            $cfg = $board_config[$category_id];
            $category_name = $cfg['name'] ?? $category_id;
            $boards = $cfg['boards'] ?? [];
            $board_name = $boards[$board_id] ?? $board_id;
        } else {
            $board_name = $board_id;
            $category_name = '一般';
        }
        
        // Boardsテーブルに情報を挿入または更新
        $stmt = $db->prepare("INSERT OR REPLACE INTO Boards (board_id, board_name, category_id, category_name)
                              VALUES (:board_id, :board_name, :category_id, :category_name)");
        $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
        $stmt->bindValue(':board_name', $board_name, SQLITE3_TEXT);
        $stmt->bindValue(':category_id', $category_id, SQLITE3_TEXT);
        $stmt->bindValue(':category_name', $category_name, SQLITE3_TEXT);
        $stmt->execute();
        
        // 各掲示板フォルダ内の全.datファイルを取得
        $thread_files = glob("$board_dir/*.dat");
        $thread_num = count($thread_files);
        $thread_count = 0;
        
        if (empty($thread_files)) {
            echo "$board_id 内にdatファイルが存在しません。\n";
            continue;
        }
        
        $start_time = null;
        foreach ($thread_files as $thread_file) {
            $thread_count++;
            $thread_id = basename($thread_file, '.dat');
            $post_order = 1;
            
            if (file_exists($thread_file)) {
                $file = fopen($thread_file, 'r');
                $title = '';
                
                while (($line = fgets($file)) !== false) {
                    // Shift-JISからUTF-8へ変換
                    $line = mb_convert_encoding(trim($line), 'UTF-8', 'SJIS');
                    $parts = explode('<>', $line);
                    
                    if ($post_order === 1) {
                        $name = $parts[0] ?? '';
                        $mail = $parts[1] ?? '';
                        $datetime_id = $parts[2] ?? '';
                        $message = $parts[3] ?? '';
                        $title = $parts[4] ?? '';
                    } else {
                        $name = $parts[0] ?? '';
                        $mail = $parts[1] ?? '';
                        $datetime_id = $parts[2] ?? '';
                        $message = $parts[3] ?? '';
                    }
                    
                    // 日時とIDの分解
                    $parts_dt = explode(' ID:', $datetime_id);
                    if (count($parts_dt) === 2) {
                        $date_time = $parts_dt[0];
                        $id = $parts_dt[1];
                        $date_time_parts = explode(' ', $date_time);
                        if (count($date_time_parts) >= 2) {
                            $date = $date_time_parts[0];
                            $time = $date_time_parts[1];
                        } else {
                            $date = $date_time;
                            $time = '';
                        }
                    } else {
                        $date = $datetime_id;
                        $time = '';
                        $id = '';
                    }
                    
                    // Postsテーブルへ投稿情報を挿入（表示用は元のメッセージそのまま）
                    $stmt = $db->prepare("INSERT OR REPLACE INTO Posts (board_id, thread_id, post_order, name, mail, date, time, id, message)
                                          VALUES (:board_id, :thread_id, :post_order, :name, :mail, :date, :time, :id, :message)");
                    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
                    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':post_order', $post_order, SQLITE3_INTEGER);
                    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt->bindValue(':mail', $mail, SQLITE3_TEXT);
                    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
                    $stmt->bindValue(':time', $time, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
                    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
                    $stmt->execute();
                    
                    $post_order++;
                }
                fclose($file);
                
                // Threadsテーブルへスレッド情報を挿入または更新
                $response_count = $post_order - 1;
                $stmt = $db->prepare("INSERT OR REPLACE INTO Threads (board_id, thread_id, title, response_count)
                                      VALUES (:board_id, :thread_id, :title, :response_count)");
                $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
                $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
                $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                $stmt->bindValue(':response_count', $response_count, SQLITE3_INTEGER);
                $stmt->execute();
            }
            // プログレスバー更新（掲示板内のdatファイル読み込み進捗）
            show_progress($thread_count, $thread_num, $board_id, $start_time);
        }
        // Boardsテーブルのthread_count更新
        $stmt = $db->prepare("UPDATE Boards SET thread_count = :thread_count WHERE board_id = :board_id");
        $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER);
        $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
        $stmt->execute();
    }
    $db->exec("COMMIT");
}

// FTSテーブルの再構築
echo "\nFTSインデックス初期化中・・・";
$db->exec("DELETE FROM Posts_fts");
echo "完了\n";

// FTS用インデックス作成：Postsテーブルから各投稿を取得し、トークナイズしてFTSテーブルへ挿入
$result = $db->query("SELECT p.board_id, p.thread_id, p.post_order, p.message, p.name, p.id, t.title AS thread_title
                       FROM Posts p
                       LEFT JOIN Threads t ON p.board_id = t.board_id AND p.thread_id = t.thread_id");

// 全件数取得（進捗表示用）
$totalResult = $db->query("SELECT COUNT(*) as count FROM Posts p LEFT JOIN Threads t ON p.board_id = t.board_id AND p.thread_id = t.thread_id");
$totalRow = $totalResult->fetchArray(SQLITE3_ASSOC);
$total = $totalRow['count'];

$insertStmt = $db->prepare("INSERT INTO Posts_fts (board_id, thread_id, post_order, message, name, id, thread_title)
                            VALUES (:board_id, :thread_id, :post_order, :message, :name, :id, :thread_title)");

$fts_done = 0;
$fts_start_time = null;

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // message, name, thread_titleをそれぞれカスタムトークナイザーで分割
    $tokenized_message = tokenizeText($row['message']);
    $tokenized_name = tokenizeText($row['name']);
    $tokenized_thread_title = tokenizeText($row['thread_title']);
    
    $insertStmt->bindValue(':board_id', $row['board_id'], SQLITE3_TEXT);
    $insertStmt->bindValue(':thread_id', $row['thread_id'], SQLITE3_INTEGER);
    $insertStmt->bindValue(':post_order', $row['post_order'], SQLITE3_INTEGER);
    $insertStmt->bindValue(':message', $tokenized_message, SQLITE3_TEXT);
    $insertStmt->bindValue(':name', $tokenized_name, SQLITE3_TEXT);
    $insertStmt->bindValue(':id', $row['id'], SQLITE3_TEXT);
    $insertStmt->bindValue(':thread_title', $tokenized_thread_title, SQLITE3_TEXT);
    $insertStmt->execute();
    
    $fts_done++;
    show_progress_fts($fts_done, $total, $fts_start_time);
}

echo "\nデータベースへの移行とFTSインデックスの作成が完了しました。\n";
$db->close();
?>
