<?php
// DBファイル名
$db_file = './converter/bbs_log.db';

// URLからboard_idとthread_idを取得
$requestUri = $_SERVER['REQUEST_URI'];
$path = explode('/', trim($requestUri, '/'));

if (count($path) < 4 || $path[2] !== 'dat') {
    echo "不正なURL形式です。\n";
    exit;
}

$board_id = $path[1];
$thread_id = basename($path[3], '.dat');

// データベースファイルの確認
if (!file_exists($db_file)) {
    echo "エラー: データベースファイル '{$db_file}' が存在しません。\n";
    exit;
}

// データベース接続
$db = new SQLite3($db_file);

// board_idの存在確認
$board_check_stmt = $db->prepare("SELECT 1 FROM Boards WHERE board_id = :board_id");
$board_check_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$board_check_result = $board_check_stmt->execute();
if (!$board_check_result->fetchArray(SQLITE3_ASSOC) || !preg_match('/^[a-zA-Z0-9]+$/',$board_id)) {
    echo "指定された掲示板が見つかりません: {$board_id}\n";
    $db->close();
    exit;
}

// スレッドのタイトルを取得
$title_stmt = $db->prepare("SELECT title FROM Titles WHERE board_id = :board_id AND thread_id = :thread_id");
$title_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$title_stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
$title_result = $title_stmt->execute();
$title_row = $title_result->fetchArray(SQLITE3_ASSOC);

if (!$title_row || !preg_match('/^\d{10}$/',$thread_id)) {
    echo "指定されたスレッドが見つかりません: thread_id = {$thread_id}\n";
    $db->close();
    exit;
}

// データベースからスレッド情報を取得
$stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order");
$stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
$result = $stmt->execute();

// 出力をShift-JISで設定
header('Content-Type: text/plain; charset=Shift-JIS');
header("Content-Disposition: attachment; filename=\"{$thread_id}.dat\"");

// スレッドの内容をdat形式で出力
$is_first_line = true;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // 1行目のみタイトルを付加
    if ($is_first_line) {
        $line = "{$row['name']}<>{$row['mail']}<>{$row['date']} {$row['time']} ID:{$row['id']}<>{$row['message']}<>{$title_row['title']}";
        $is_first_line = false;
    } else {
        $line = "{$row['name']}<>{$row['mail']}<>{$row['date']} {$row['time']} ID:{$row['id']}<>{$row['message']}<>";
    }
    echo mb_convert_encoding($line, 'SJIS', 'UTF-8') . "\n";
}

$db->close();
