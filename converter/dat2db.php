<?php 
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
    message TEXT,
    PRIMARY KEY (board_id, thread_id, post_order),
    FOREIGN KEY (board_id) REFERENCES Boards(board_id),
    FOREIGN KEY (board_id, thread_id) REFERENCES Threads(board_id, thread_id)
)");

// FTS仮想テーブルの作成（FTS5を使用）
// 投稿内容(message)、投稿者名(name)、投稿者ID(id)、スレッドタイトル(thread_title)を全文検索対象とする
$db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS Posts_fts USING fts5(
    board_id,
    thread_id,
    post_order,
    message,
    name,
    id,
    thread_title
)");

// プログレスバーを表示する関数
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

// 設定ファイルのパス（board_config.phpの新形式に対応）
// board_config.phpの例:
// return [
//     'ent' => [                     // カテゴリID（ディレクトリ名）
//         'name' => 'エンタメ',        // カテゴリ名（表示名）
//         'boards' => [              // このカテゴリに属する掲示板
//             'movies' => '映画掲示板',
//             'music'  => '音楽掲示板',
//         ],
//     ],
// ];
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
        $stmt = $db->prepare("INSERT OR REPLACE INTO Boards (board_id, board_name, category_id, category_name) VALUES (:board_id, :board_name, :category_id, :category_name)");
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
                    
                    // Postsテーブルへ投稿情報のINSERTまたは更新
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
                
                // Threadsテーブルへスレッド情報のINSERTまたは更新
                $response_count = $post_order - 1;
                $stmt = $db->prepare("INSERT OR REPLACE INTO Threads (board_id, thread_id, title, response_count)
                                      VALUES (:board_id, :thread_id, :title, :response_count)");
                $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
                $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
                $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                $stmt->bindValue(':response_count', $response_count, SQLITE3_INTEGER);
                $stmt->execute();
            }
            // プログレスバーを更新
            show_progress($thread_count, $thread_num, $board_id, $start_time);
        }
        // Boardsテーブルのthread_countを更新
        $stmt = $db->prepare("UPDATE Boards SET thread_count = :thread_count WHERE board_id = :board_id");
        $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER);
        $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
        $stmt->execute();
    }
    $db->exec("COMMIT");
}

// FTSテーブルの再構築
// 重複防止のため、一度初期化してからPostsテーブルとThreadsテーブルをJOINしてデータを反映します。
echo "\nFTSインデックスを作成中・・・\n";
$db->exec("DELETE FROM Posts_fts");
$db->exec("INSERT INTO Posts_fts (board_id, thread_id, post_order, message, name, id, thread_title)
           SELECT p.board_id, p.thread_id, p.post_order, p.message, p.name, p.id, t.title
           FROM Posts p
           LEFT JOIN Threads t ON p.board_id = t.board_id AND p.thread_id = t.thread_id");

$db->close();
echo "データベースへの移行とFTSインデックスの作成が完了しました。\n";
?>
