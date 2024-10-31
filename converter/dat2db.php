<?php
// データベース接続の設定
$db = new SQLite3('bbs_log.db');

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
    FOREIGN KEY (board_id, thread_id) REFERENCES Threads(board_id, thread_id)
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
    $thread_count = 0; // スレッド数の初期化

    // Boardsテーブルに掲示板名を挿入（存在しない場合のみ）
    $stmt = $db->prepare("INSERT OR IGNORE INTO Boards (board_id, board_name, thread_count) VALUES (:board_id, :board_name, :thread_count)");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':board_name', $board_name, SQLITE3_TEXT);
    $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER);
    $stmt->execute();

    // 各フォルダ内の全.datファイルを読み込み
    $thread_files = glob("$board_dir/*.dat");
    $thread_num = count($thread_files);

    if (empty($thread_files)) {
        echo "$board_id 内にdatファイルが存在しません。\n";
        continue;
    } else {
        echo "掲示板: $board_id を読み込み中・・・\n";
    }

    // トランザクションの開始
    $db->exec('BEGIN TRANSACTION');

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

                // データを分割し、期待する要素数が揃っているか確認
                $parts = explode('<>', $line);

                if (count($parts) >= 4) {
                    $name = $parts[0] ?? '';
                    $mail = $parts[1] ?? '';
                    $datetime_id = $parts[2] ?? '';
                    $message = $parts[3] ?? '';
                    $title = $parts[4] ?? ''; // タイトルは最初の投稿のみ存在する可能性がある
                } else {
                    // フォーマットが不正な場合はスキップ
                    continue;
                }

                // datetime_idを`date`, `time`, `id`に分割
                $id = '';
                $date = '';
                $time = '';

                // ' ID:'で分割
                $parts_datetime = explode(' ID:', $datetime_id);
                $date_time_str = $parts_datetime[0] ?? '';
                $id = $parts_datetime[1] ?? '';

                // ' 'で日付と時間を分割
                $date_time_parts = explode(' ', $date_time_str);
                $date = $date_time_parts[0] ?? '';
                $time = $date_time_parts[1] ?? '';

                // 最初の投稿からタイトルとレス数を設定
                if ($first_post) {
                    $stmt = $db->prepare("INSERT OR REPLACE INTO Threads (board_id, thread_id, title, response_count)
                                          VALUES (:board_id, :thread_id, :title, :response_count)");
                    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
                    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $stmt->bindValue(':response_count', 1, SQLITE3_INTEGER);
                    $stmt->execute();
                    $first_post = false;
                    $thread_count++; // スレッド数をカウント
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

            // Threadsテーブルのresponse_countを更新
            $stmt = $db->prepare("UPDATE Threads SET response_count = :response_count WHERE board_id = :board_id AND thread_id = :thread_id");
            $stmt->bindValue(':response_count', $post_order - 1, SQLITE3_INTEGER); // 総レス数（$post_orderは最後にインクリメントされているため）
            $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
            $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }

    // Boardsテーブルのthread_countを更新
    $stmt = $db->prepare("UPDATE Boards SET thread_count = :thread_count WHERE board_id = :board_id");
    $stmt->bindValue(':thread_count', $thread_count, SQLITE3_INTEGER); // 総スレッド数
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->execute();

    // トランザクションのコミット
    $db->exec('COMMIT');

    echo "$board_id: $thread_count/$thread_num スレッドの読み込みが完了しました。\n";
}

$db->close();
echo "データベースへの移行が完了しました。\n";
?>
