<?php
// データベースファイル名
$db_file = './converter/bbs_log.db';

// ヘッダーの設定（出力前に行う）
header('Content-Type: text/plain; charset=Shift-JIS');

// データベースファイルの確認
if (!file_exists($db_file)) {
    exitWithError("エラー: データベースファイル '{$db_file}' が存在しません。");
}

// データベース接続
$db = new SQLite3($db_file);
if (!$db) {
    exitWithError("データベースに接続できません。");
}

// URLからboard_idとthread_idを取得
$parsed_url = parse_url($_SERVER['REQUEST_URI']);
$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
$path = trim($path, '/');
$segments = explode('/', $path);

// スクリプト名の取得
$script_name = trim($_SERVER['SCRIPT_NAME'], '/');
$script_segments = explode('/', $script_name);

// パス情報の取得
$path_segments = array_slice($segments, count($script_segments) - 1);

// URL形式のチェック
if (count($path_segments) !== 3 || $path_segments[1] !== 'dat') {
    exitWithError("不正なURL形式です。");
}

$board_id = $path_segments[0];
$thread_file = $path_segments[2];
$thread_id = basename($thread_file, '.dat');

// board_idとthread_idのバリデーション
if (!isValidBoardId($board_id) || !isValidThreadId($thread_id)) {
    exitWithError("無効なboard_idまたはthread_idです。");
}

// board_idの存在確認
$board_check_stmt = $db->prepare("SELECT 1 FROM Boards WHERE board_id = :board_id");
$board_check_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$board_check_result = $board_check_stmt->execute();
if (!$board_check_result || !$board_check_result->fetchArray(SQLITE3_ASSOC)) {
    exitWithError("指定された掲示板が見つかりません: {$board_id}");
}

// スレッドのタイトルを取得
$title_stmt = $db->prepare("SELECT title FROM Threads WHERE board_id = :board_id AND thread_id = :thread_id");
$title_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$title_stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
$title_result = $title_stmt->execute();
if (!$title_result) {
    exitWithError("データベースエラーが発生しました。");
}
$title_row = $title_result->fetchArray(SQLITE3_ASSOC);
if (!$title_row) {
    exitWithError("指定されたスレッドが見つかりません: thread_id = {$thread_id}");
}

// データベースからスレッド情報を取得
$stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order");
$stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
$stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
$result = $stmt->execute();
if (!$result) {
    exitWithError("データベースエラーが発生しました。");
}

// ダウンロードファイル名の設定
$filename = "{$thread_id}.dat";
header("Content-Disposition: attachment; filename=\"{$filename}\"");

// スレッドの内容をdat形式で出力
$is_first_line = true;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // データの取得とサニタイズ
    $name = $row['name'] ?? '';
    $mail = $row['mail'] ?? '';
    $date = $row['date'] ?? '';
    $time = $row['time'] ?? '';
    $id = $row['id'] ?? '';
    $message = $row['message'] ?? '';
    $title = $title_row['title'] ?? '';

    // 文字列をエスケープ
    $name = str_replace('<>', '', $name);
    $mail = str_replace('<>', '', $mail);
    $date_time_id = str_replace('<>', '', "{$date} {$time} ID:{$id}");
    $message = str_replace('<>', '', $message);

    // 1行目のみタイトルを付加
    if ($is_first_line) {
        $line = "{$name}<>{$mail}<>{$date_time_id}<>{$message}<>{$title}";
        $is_first_line = false;
    } else {
        $line = "{$name}<>{$mail}<>{$date_time_id}<>{$message}<>";
    }

    // エンコーディングの変換とエラーハンドリング
    $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8');
    if ($converted_line === false) {
        $converted_line = mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
    }

    echo $converted_line . "\n";
}

$db->close();

// エラー出力と終了
function exitWithError($message) {
    echo mb_convert_encoding($message . "\n", 'SJIS', 'UTF-8');
    exit;
}

// board_idのバリデーション
function isValidBoardId($board_id) {
    return preg_match('/^[a-zA-Z0-9_\-]+$/', $board_id);
}

// thread_idのバリデーション
function isValidThreadId($thread_id) {
    return preg_match('/^\d+$/', $thread_id);
}
?>
