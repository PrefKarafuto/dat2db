<?php
// データベース接続の設定
$db = new SQLite3('bbs_log.db');

// 各テーブルの作成
$db->exec("CREATE TABLE IF NOT EXISTS Boards (
    board_id TEXT PRIMARY KEY,
    board_name TEXT UNIQUE
)");

$db->exec("CREATE TABLE IF NOT EXISTS Titles (
    board_id TEXT,
    thread_id INTEGER PRIMARY KEY,
    title TEXT,
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
    FOREIGN KEY (board_id) REFERENCES Boards(board_id)
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
    $process_count = 0;

    if (empty ($thread_files)) {
        echo "$board_id 内にdatファイルが存在しません。\n";
        continue;
    } else{
        echo "掲示版: $board_id を読み込み中・・・";
    }

    foreach ($thread_files as $thread_file) {
        $thread_id = basename($thread_file, '.dat'); // ファイル名からスレッドID（UNIXタイム）を取得
        $post_order = 1;
        $first_post = true;

        // ファイルを開いて各行を読み込み
        if (file_exists($thread_file)) {
            $file = fopen($thread_file, 'r');
            
            while (($line = fgets($file)) !== false) {
                // Shift-JISからUTF-8に変換
                $line = mb_convert_encoding(trim($line), 'UTF-8', 'SJIS');
                
                list($name, $mail, $datetime_id, $message, $title) = explode('<>', $line);

                // datetime_idを`date`, `time`, `id`に分割
                list($date_time, $id) = explode(' ID:', $datetime_id);
                list($date, $time) = explode(' ', $date_time);

                // 最初の投稿からタイトルを保存
                if ($first_post) {
                    $stmt = $db->prepare("INSERT INTO Titles (board_id, thread_id, title)
                                          VALUES (:board_id, :thread_id, :title)");
                    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
                    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $stmt->execute();
                    $first_post = false;
                }

                // データベースに投稿を挿入
                $stmt = $db->prepare("INSERT INTO Posts (board_id, thread_id, post_order, name, mail, date, time, id, message)
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
            $process_count++;
        }
    }
    echo "$process_count/$thread_num 完了\n";
}

$db->close();
echo "データベースへの移行が完了しました。\n";
