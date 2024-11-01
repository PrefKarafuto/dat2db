<?php
// データベース接続の設定
$db = new SQLite3('../bbs_log.db');

// 各テーブルの作成
$db->exec("CREATE TABLE IF NOT EXISTS Boards (
    board_id TEXT PRIMARY KEY,
    board_name TEXT,
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
    FOREIGN KEY (thread_id) REFERENCES Threads(thread_id)
)");

// プログレスバーを表示する関数
function show_progress($done, $total, $board_id, &$start_time, $size = 30) {
    if ($done > $total) return;

    if ($start_time === null) $start_time = time();
    $now = time();

    $perc = (double)($done / $total);

    $bar = floor($perc * $size);

    $status_bar = "\r掲示板: $board_id を読み込み中・・・[";
    $status_bar .= str_repeat("=", $bar);
    if ($bar < $size) {
        $status_bar .= ">";
        $status_bar .= str_repeat(" ", $size - $bar);
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

// 設定ファイルのパス
$config_file = 'board_config.php';

// 設定ファイルの存在確認
if (!file_exists($config_file)) {
    exit("エラー: 設定ファイル '{$config_file}' が存在しません。設定ファイルを作成してください。\n");
}

// 設定ファイルを読み込み
$board_config = include $config_file;

// 設定が正しく読み込まれたか確認
if (!is_array($board_config)) {
    exit("エラー: 設定ファイルの形式が正しくありません。\n");
}

// 全フォルダと全ファイルを読み込み
$base_dir = './dats/';  // datsフォルダ
$board_dirs = glob($base_dir . '*', GLOB_ONLYDIR); // dats内の全フォルダ（各掲示板）を取得

// フォルダが存在しない場合、メッセージを表示して終了
if (empty($board_dirs)) {
    echo "掲示板フォルダが存在しません。\n";
    exit;
}

foreach ($board_dirs as $board_dir) {
    $board_id = basename($board_dir); // フォルダ名をboard_idとする

    // 'dat'および'test'フォルダをスキップ
    if ($board_id === 'dat' || $board_id === 'test') {
        echo "$board_id フォルダはパスされます。\n";
        continue;
    }

    // category_nameとboard_nameを取得
    $found = false;
    foreach ($board_config as $category_name => $boards) {
        if (array_key_exists($board_id, $boards)) {
            $board_name = $boards[$board_id];
            $found = true;
            break;
        }
    }

    if (!$found) {
        // board_idが設定ファイルに存在しない場合
        $board_name = $board_id;
        $category_name = '一般';
    }    

    // Boardsテーブルに掲示板名とカテゴリ名を挿入または更新
    $stmt = $db->prepare("INSERT OR REPLACE INTO Boards (board_id, board_name, category_name) VALUES (:board_id, :board_name, :category_name)");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':board_name', $board_name, SQLITE3_TEXT);
    $stmt->bindValue(':category_name', $category_name, SQLITE3_TEXT);
    $stmt->execute();

    // 各フォルダ内の全.datファイルを読み込み
    $thread_files = glob("$board_dir/*.dat");
    $thread_num = count($thread_files);
    $thread_count = 0; // スレッド数の初期化

    if (empty($thread_files)) {
        echo "$board_id 内にdatファイルが存在しません。\n";
        continue;
    }

    // プログレスバーの開始
    $start_time = null;

    foreach ($thread_files as $thread_file) {
        $thread_count++;

        $thread_id = basename($thread_file, '.dat'); // ファイル名からスレッドID（UNIXタイム）を取得
        $post_order = 1;

        // ファイルを開いて各行を読み込み
        if (file_exists($thread_file)) {
            $file = fopen($thread_file, 'r');

            // スレッドタイトルを初期化
            $title = '';

            while (($line = fgets($file)) !== false) {
                // Shift-JISからUTF-8に変換
                $line = mb_convert_encoding(trim($line), 'UTF-8', 'SJIS');

                // データを分割し、期待する要素数が揃っているか確認
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

                // datetime_idを`date`, `time`, `id`に分割
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

                // Postsテーブルに投稿を挿入（既存の場合は上書き）
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

            // Threadsテーブルにスレッド情報を挿入または更新
            $response_count = $post_order - 1; // 総レス数

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
    $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER); // 総スレッド数
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->execute();
}

$db->close();
echo "データベースへの移行が完了しました。\n";
?>
