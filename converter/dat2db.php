<?php
// データベース接続の設定
$db = new SQLite3('../bbs_log.db');

// 各テーブルの作成
$db->exec("CREATE TABLE IF NOT EXISTS Boards (
    board_id TEXT PRIMARY KEY,
    board_name TEXT UNIQUE,
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
    $board_name = $board_id; // 必要に応じて掲示板名を別に設定可能

    // Boardsテーブルに掲示板名を挿入（存在しない場合のみ）
    $stmt = $db->prepare("INSERT OR IGNORE INTO Boards (board_id, board_name) VALUES (:board_id, :board_name)");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':board_name', $board_name, SQLITE3_TEXT);
    $stmt->execute();

    // 各フォルダ内の全.datファイルを読み込み
    $thread_files = glob("$board_dir/*.dat");
    $thread_num = count($thread_files);
    $thread_count = 0; // スレッド数の初期化

    if (empty($thread_files)) {
        echo "$board_id 内にdatファイルが存在しません。\n";
        continue;
    } else {
        echo "掲示板: $board_id を読み込み中・・・";
    }

    foreach ($thread_files as $thread_file) {
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

                if ($post_order == 1) {
                    $name = $parts[0];
                    $mail = $parts[1];
                    $datetime_id = $parts[2];
                    $message = $parts[3];
                    $title = $parts[4];
                } else {
                    $name = $parts[0];
                    $mail = $parts[1];
                    $datetime_id = $parts[2];
                    $message = $parts[3];
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

            $thread_count++; // スレッド数をカウント
        }
    }

    // Boardsテーブルのthread_countを更新
    $stmt = $db->prepare("UPDATE Boards SET thread_count = :thread_count WHERE board_id = :board_id");
    $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER); // 総スレッド数
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->execute();

    echo "$thread_count/$thread_num 完了\n";
}

$db->close();
echo "データベースへの移行が完了しました。\n";
?>
