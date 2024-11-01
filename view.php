<?php
// ヘッダーの設定
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// エラーレポートの設定（デバッグ時のみ有効にする）
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 本番環境では'0'に設定

// データベースファイルの確認と接続
$db_file = 'bbs_log.db';
if (!file_exists($db_file)) {
    exitWithError("エラー: データベースファイルが存在しません。");
}
$db = new SQLite3($db_file);
if (!$db) {
    exitWithError("データベースに接続できません。");
}

// ベースURLの取得
$base_url = rtrim($_SERVER['SCRIPT_NAME'], '/');

// URLパスの解析
$path = parseUrlPath($_SERVER['REQUEST_URI'], $base_url);

// ルーティングの処理
if (empty($path)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!empty($_GET['title']) || !empty($_GET['query']) || !empty($_GET['date']) || !empty($_GET['id']))) {
        $sanitized_params = sanitizeInput($_GET);
        if (!empty($sanitized_params['title'])) {
            // スレッドタイトル検索
            searchAllThreads($db, $sanitized_params, $base_url);
        } else {
            // 全体検索
            searchAllPosts($db, $sanitized_params, $base_url);
        }
    }else {
        // 掲示板一覧の表示
        displayBoardList($db, $base_url);
    }
} elseif (count($path) === 1) {
    $board_id = $path[0];
    if($board_id === 'bbsmenu.html') {
        // bbsmenuを表示
        displayBBSmenuHtml($db, $base_url);
    }else{
        if (!isValidBoardId($board_id)) {
            exitWithError("無効なboard_idです。");
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!empty($_GET['title']) || !empty($_GET['query']) || !empty($_GET['date']) || !empty($_GET['id']))) {
            $sanitized_params = sanitizeInput($_GET);
            if (!empty($sanitized_params['title'])) {
                // 掲示板内のスレッドタイトル検索
                searchBoardThreads($db, $board_id, $sanitized_params, $base_url);
            } else {
                // 掲示板内検索
                searchBoardPosts($db, $board_id, $sanitized_params, $base_url);
            }
        } else {
            // スレッド一覧の表示
            displayThreadList($db, $board_id, $base_url);
        }
    }
    
} elseif (count($path) === 2) {
    $board_id = $path[0];
    $thread_id = $path[1];

    if (!isValidBoardId($board_id) || !isValidThreadId($thread_id)) {
        exitWithError("無効なURLです。");
    }

    // 全レス表示
    displayAllResponses($db, $board_id, $thread_id, $base_url);
} elseif (count($path) === 3) {
    $board_id = $path[0];
    $thread_id = $path[1];
    $response_format = $path[2];

    if (!isValidBoardId($board_id) || !isValidThreadId($thread_id)) {
        exitWithError("無効なURLです。");
    }

    // 指定レスの表示
    displaySelectedResponses($db, $board_id, $thread_id, $response_format, $base_url);
} else {
    exitWithError("無効なURL形式です。");
}

$db->close();

// 関数定義

// エラー出力と終了
function exitWithError($message) {
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>エラー</title></head>";
    echo "<body>";
    echo "<h1>エラー</h1>";
    echo "<p>{$message}</p>";
    echo "</body>";
    echo "</html>";
    exit;
}

// URLパスを解析する関数
function parseUrlPath($request_uri, $base_url) {
    $parsed_url = parse_url($request_uri);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    // $base_urlの長さを取得
    $base_url_length = strlen($base_url);
    // $pathから$base_url部分を削除
    $path = substr($path, $base_url_length);
    $path = trim($path, '/');
    $path_parts = explode('/', $path);
    // 空の要素を取り除く
    $path_parts = array_filter($path_parts, function($value) { return $value !== ''; });
    return array_values($path_parts);
}

// 入力をサニタイズする関数
function sanitizeInput($input) {
    $sanitized = [];
    $allowed_keys = ['query', 'title', 'date', 'id', 'page'];
    foreach ($allowed_keys as $key) {
        if (isset($input[$key])) {
            $value = trim($input[$key]);
            // 各種バリデーション
            if ($key === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                continue;
            }
            if ($key === 'id' && !preg_match('/^[a-zA-Z0-9_\.\-\/\+]+$/', $value)) {
                continue;
            }
            if ($key === 'page' && !preg_match('/^\d+$/', $value)) {
                continue;
            }
            // 空文字列の場合は設定しない
            if ($value === '') {
                continue;
            }
            $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }
    return $sanitized;
}

// board_idのバリデーション
function isValidBoardId($board_id) {
    return preg_match('/^[a-zA-Z0-9_\-]+$/', $board_id);
}

// thread_idのバリデーション
function isValidThreadId($thread_id) {
    return preg_match('/^\d+$/', $thread_id);
}

// ページネーションのためのパラメータ設定
function getPaginationParams($params) {
    $page = isset($params['page']) ? (int)$params['page'] : 1;
    if ($page < 1) $page = 1;
    $limit = 20; // 1ページあたりの表示件数
    $offset = ($page - 1) * $limit;
    return [$limit, $offset, $page];
}

// ページネーションのリンク生成
function generatePaginationLinks($current_page, $total_pages, $base_url, $query_params = []) {
    $links = '';

    // 'page'キーを削除して重複を防ぐ
    unset($query_params['page']);

    // 最初のページへのリンク '<<'
    if ($current_page > 1) {
        $params = $query_params;
        $params['page'] = 1;
        $query_string = http_build_query($params);
        $links .= "<a href='{$base_url}?{$query_string}'>&lt;&lt;</a> ";

        // 前のページへのリンク '<'
        $prev_page = $current_page - 1;
        $params['page'] = $prev_page;
        $query_string = http_build_query($params);
        $links .= "<a href='{$base_url}?{$query_string}'>&lt;</a> ";
    } else {
        // 最初のページの場合はリンクなし
        $links .= "&lt;&lt; &lt; ";
    }

    // 現在のページ番号
    $links .= "{$current_page} / {$total_pages}";

    // 次のページへのリンク '>'
    if ($current_page < $total_pages) {
        $params = $query_params;
        $next_page = $current_page + 1;
        $params['page'] = $next_page;
        $query_string = http_build_query($params);
        $links .= " <a href='{$base_url}?{$query_string}'>&gt;</a>";

        // 最後のページへのリンク '>>'
        $params['page'] = $total_pages;
        $query_string = http_build_query($params);
        $links .= " <a href='{$base_url}?{$query_string}'>&gt;&gt;</a>";
    } else {
        // 最後のページの場合はリンクなし
        $links .= " &gt; &gt;&gt;";
    }

    return $links;
}

// BBSMENUの表示
function displayBBSmenuHtml($db, $base_url) {
    // データベースから掲示板の情報を取得
    $result = $db->query("SELECT board_id, board_name, category_name FROM Boards ORDER BY category_name ASC, board_id ASC");
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    // カテゴリーごとに掲示板を格納する配列
    $categories = [];

    // データをカテゴリーごとにグループ化
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $category_name = $row['category_name'];
        if (!isset($categories[$category_name])) {
            $categories[$category_name] = [];
        }
        $categories[$category_name][] = $row;
    }

    // 出力のエンコーディングをShift-JISに設定
    header('Content-Type: text/html; charset=Shift_JIS');

    // 出力を格納する変数
    $html_output = "";

    $html_output .= "<!DOCTYPE html>";
    $html_output .= "<html lang='ja'>";
    $html_output .= "<HEAD><META http-equiv=\"Content-Type\" content=\"text/html; charset=Shift_JIS\">";
    $html_output .= "<TITLE>BBS MENU</TITLE>";
    $html_output .= "<BASE TARGET=\"_blank\"></HEAD>";
    $html_output .= "<BODY TEXT=\"#CC3300\" BGCOLOR=\"#FFFFFF\" link=\"#0000FF\" alink=\"#ff0000\" vlink=\"#660099\">";
    $html_output .= "<B>BBS MENU</B><BR>";
    $html_output .= "<BR>専用ブラウザ用<FONT size=2>";

    foreach ($categories as $category_name => $boards) {
        // カテゴリー名を表示
        $category_name_escaped = htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8');
        $html_output .= "<BR><BR><B>{$category_name_escaped}</B><BR>";

        // 掲示板を表示
        foreach ($boards as $board) {
            $board_id = htmlspecialchars($board['board_id'], ENT_QUOTES, 'UTF-8');
            // タイトルはエスケープしない（元々エスケープされていない場合）
            $board_name = $board['board_name'];
            $board_url = htmlspecialchars($base_url . '/../dat.php/' . $board_id . '/', ENT_QUOTES, 'UTF-8');

            $html_output .= "<A HREF=\"{$board_url}\">{$board_name}</A><BR>";
        }
    }

    $html_output .= "<BR><BR><BR>";
    $html_output .= "</FONT>";
    $html_output .= "</BODY>";
    $html_output .= "</HTML>";

    // UTF-8からShift-JISに変換
    $html_output_sjis = mb_convert_encoding($html_output, 'Shift_JIS', 'UTF-8');

    // 出力
    echo $html_output_sjis;
}



// 掲示板一覧の表示
function displayBoardList($db, $base_url) {
    $result = $db->query("SELECT board_id, board_name FROM Boards");
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>掲示板一覧</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "ul { list-style-type: none; padding: 0; }";
    echo "li { margin: 5px 0; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo "form { margin-top: 20px; }";
    echo "input[type='text'], input[type='date'] { width: 100%; padding: 8px; margin: 5px 0; }";
    echo "input[type='submit'] { padding: 10px 20px; background-color: #333; color: #fff; border: none; cursor: pointer; }";
    echo "input[type='submit']:hover { background-color: #555; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>掲示板一覧</h1>";
    echo "<p><a href=\"./bbsmenu.html\">専ブラ用BBSMENU</a></p>";
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $board_id = htmlspecialchars($row['board_id'], ENT_QUOTES, 'UTF-8');
        $board_name = htmlspecialchars($row['board_name'], ENT_QUOTES, 'UTF-8');
        echo "<li><a href='{$base_url}/{$board_id}/'>{$board_name}</a> [<a href='{$base_url}/../dat.php/{$board_id}/'>専ブラ登録用URL</a>]</li>";
    }
    echo "</ul>";

    echo "<h2>検索</h2>";
    echo "<form method='get' action='{$base_url}/'>";
    echo "<input type='text' name='title' placeholder='スレッドタイトル検索'><br>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// スレッド一覧の表示
function displayThreadList($db, $board_id, $base_url) {
    // ページネーションの設定
    $params = sanitizeInput($_GET);
    list($limit, $offset, $page) = getPaginationParams($params);

    // 総スレッド数を取得
    $stmt = $db->prepare("SELECT COUNT(*) as total_threads FROM Threads WHERE board_id = :board_id");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $total_threads = $row['total_threads'];

    $total_pages = ceil($total_threads / $limit);

    $stmt = $db->prepare("SELECT thread_id, title, response_count FROM Threads WHERE board_id = :board_id ORDER BY thread_id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>スレッド一覧</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "ul { list-style-type: none; padding: 0; }";
    echo "li { margin: 5px 0; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo "form { margin-top: 20px; }";
    echo "input[type='text'], input[type='date'] { width: 100%; padding: 8px; margin: 5px 0; }";
    echo "input[type='submit'] { padding: 10px 20px; background-color: #333; color: #fff; border: none; cursor: pointer; }";
    echo "input[type='submit']:hover { background-color: #555; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>" . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "のスレッド一覧 ($total_threads)</h1>";
    echo "<p><a href='{$base_url}/'>← 掲示板一覧に戻る</a></p>";

    // ページネーションのリンクを表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", []);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "<ul>";

    $thread_order = ($page - 1) * $limit;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $thread_id = htmlspecialchars($row['thread_id'], ENT_QUOTES, 'UTF-8');
        // タイトルはエスケープしない
        $title = $row['title'];
        $res_count = $row['response_count'];
        $thread_order ++;
        echo "<li>{$thread_order}: <a href='{$base_url}/{$board_id}/{$thread_id}/'>{$title} ({$res_count})</a></li>";
    }
    echo "</ul>";

    // ページネーションのリンクを表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", []);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "<h2>検索</h2>";
    echo "<form method='get' action='{$base_url}/{$board_id}/'>";
    echo "<input type='text' name='title' placeholder='スレッドタイトル検索'><br>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// 全体のスレッドタイトル検索
function searchAllThreads($db, $params, $base_url) {
    // ページネーションの設定
    list($limit, $offset, $page) = getPaginationParams($params);

    $query = "SELECT board_id, thread_id, title, response_count FROM Threads WHERE 1=1";
    $count_query = "SELECT COUNT(*) as total_threads FROM Threads WHERE 1=1";
    $conditions = [];
    if (!empty($params['title'])) {
        $conditions[] = "title LIKE :title";
    }
    if ($conditions) {
        $condition_str = " AND " . implode(" AND ", $conditions);
        $query .= $condition_str;
        $count_query .= $condition_str;
    }
    $query .= " ORDER BY thread_id DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $count_stmt = $db->prepare($count_query);
    if (!empty($params['title'])) {
        $stmt->bindValue(':title', '%' . $params['title'] . '%', SQLITE3_TEXT);
        $count_stmt->bindValue(':title', '%' . $params['title'] . '%', SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count_result = $count_stmt->execute();

    if (!$result || !$count_result) {
        exitWithError("検索中にデータベースエラーが発生しました。");
    }

    $row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total_threads = $row['total_threads'];
    $total_pages = ceil($total_threads / $limit);

    // 結果を掲示板ごとにグループ化
    $grouped_results = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $board_id = $row['board_id'];
        if (!isset($grouped_results[$board_id])) {
            $grouped_results[$board_id] = [];
        }
        $grouped_results[$board_id][] = $row;
    }

    // 掲示板名を一度に取得して連想配列に格納
    $board_names = [];
    $board_result = $db->query("SELECT board_id, board_name FROM Boards");
    while ($board_row = $board_result->fetchArray(SQLITE3_ASSOC)) {
        $board_names[$board_row['board_id']] = $board_row['board_name'];
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>スレッドタイトル検索結果</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo ".pagination { margin-top: 20px; }";
    echo ".pagination a { margin: 0 5px; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>スレッドタイトル検索結果</h1>";

    // 検索画面に戻るリンクを追加
    echo "<p><a href='{$base_url}/'>← 戻る</a></p>";

    // ページネーションのリンクを上部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    if ($total_threads == 0) {
        echo "<p>該当するスレッドはありませんでした。</p>";
    } else {
        foreach ($grouped_results as $board_id => $threads) {
            $board_name = isset($board_names[$board_id]) ? $board_names[$board_id] : $board_id;
            echo "<h2>" . htmlspecialchars($board_name, ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . ")</h2>";
            foreach ($threads as $thread) {
                $thread_id = htmlspecialchars($thread['thread_id'], ENT_QUOTES, 'UTF-8');
                $title = htmlspecialchars($thread['title'], ENT_QUOTES, 'UTF-8');
                $res_count = $thread['response_count'];
                echo "<p><a href='{$base_url}/{$board_id}/{$thread_id}/'>{$title} ({$res_count})</a></p>";
            }
        }
    }

    // ページネーションのリンクを下部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// 掲示板内のスレッドタイトル検索
function searchBoardThreads($db, $board_id, $params, $base_url) {
    // ページネーションの設定
    list($limit, $offset, $page) = getPaginationParams($params);

    $query = "SELECT thread_id, title, response_count FROM Threads WHERE board_id = :board_id";
    $count_query = "SELECT COUNT(*) as total_threads FROM Threads WHERE board_id = :board_id";
    $conditions = [];
    if (!empty($params['title'])) {
        $conditions[] = "title LIKE :title";
    }
    if ($conditions) {
        $condition_str = " AND " . implode(" AND ", $conditions);
        $query .= $condition_str;
        $count_query .= $condition_str;
    }
    $query .= " ORDER BY thread_id DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $count_stmt = $db->prepare($count_query);
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $count_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    if (!empty($params['title'])) {
        $stmt->bindValue(':title', '%' . $params['title'] . '%', SQLITE3_TEXT);
        $count_stmt->bindValue(':title', '%' . $params['title'] . '%', SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count_result = $count_stmt->execute();

    if (!$result || !$count_result) {
        exitWithError("検索中にデータベースエラーが発生しました。");
    }

    $row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total_threads = $row['total_threads'];
    $total_pages = ceil($total_threads / $limit);

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>スレッドタイトル検索結果</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo ".pagination { margin-top: 20px; }";
    echo ".pagination a { margin: 0 5px; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>スレッドタイトル検索結果: " . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "</h1>";

    // 検索画面に戻るリンクを追加
    echo "<p><a href='{$base_url}/{$board_id}/'>← 戻る</a></p>";

    // ページネーションのリンクを上部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    if ($total_threads == 0) {
        echo "<p>該当するスレッドはありませんでした。</p>";
    } else {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $thread_id = htmlspecialchars($row['thread_id'], ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
            $res_count = $row['response_count'];
            echo "<p><a href='{$base_url}/{$board_id}/{$thread_id}/'>{$title} ({$res_count})</a></p>";
        }
    }

    // ページネーションのリンクを下部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// 全体検索
function searchAllPosts($db, $params, $base_url) {
    // 検索ワードが何も設定されていない場合、検索を行わない
    if (empty($params['query']) && empty($params['date']) && empty($params['id'])) {
        exitWithError("検索ワードが設定されていません。検索ワードを入力してください。");
    }

    // ページネーションの設定
    list($limit, $offset, $page) = getPaginationParams($params);

    $query = "SELECT board_id, thread_id, post_order, name, mail, date, time, id, message FROM Posts WHERE 1=1";
    $count_query = "SELECT COUNT(*) as total_posts FROM Posts WHERE 1=1";
    $conditions = [];
    if (!empty($params['query'])) {
        $conditions[] = "message LIKE :query";
    }
    if (!empty($params['date'])) {
        $conditions[] = "date = :date";
    }
    if (!empty($params['id'])) {
        $conditions[] = "id = :id";
    }
    if ($conditions) {
        $condition_str = " AND " . implode(" AND ", $conditions);
        $query .= $condition_str;
        $count_query .= $condition_str;
    }
    $query .= " ORDER BY board_id, thread_id, post_order LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $count_stmt = $db->prepare($count_query);
    if (!empty($params['query'])) {
        $stmt->bindValue(':query', '%' . $params['query'] . '%', SQLITE3_TEXT);
        $count_stmt->bindValue(':query', '%' . $params['query'] . '%', SQLITE3_TEXT);
    }
    if (!empty($params['date'])) {
        $stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
        $count_stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
    }
    if (!empty($params['id'])) {
        $stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
        $count_stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count_result = $count_stmt->execute();

    if (!$result || !$count_result) {
        exitWithError("検索中にデータベースエラーが発生しました。");
    }

    $row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total_posts = $row['total_posts'];
    $total_pages = ceil($total_posts / $limit);

    // 掲示板名を一度に取得して連想配列に格納
    $board_names = [];
    $board_result = $db->query("SELECT board_id, board_name FROM Boards");
    while ($board_row = $board_result->fetchArray(SQLITE3_ASSOC)) {
        $board_names[$board_row['board_id']] = $board_row['board_name'];
    }

    // 結果を掲示板ごとにグループ化
    $grouped_results = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $board_id = $row['board_id'];
        if (!isset($grouped_results[$board_id])) {
            $grouped_results[$board_id] = [];
        }
        $grouped_results[$board_id][] = $row;
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>全体検索結果</title>";
    // CSSスタイルを追加
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo ".pagination { margin-top: 20px; }";
    echo ".pagination a { margin: 0 5px; }";
    echo ".response { border-bottom: 1px solid #ccc; padding: 10px 0; }";
    echo ".board-section { margin-bottom: 40px; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>全体検索結果</h1>";

    // 検索画面に戻るリンクを追加
    echo "<p><a href='{$base_url}/'>← 検索画面に戻る</a></p>";

    // ページネーションのリンクを上部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    if ($total_posts == 0) {
        echo "<p>該当する投稿はありませんでした。</p>";
    } else {
        foreach ($grouped_results as $board_id => $posts) {
            // 掲示板名を取得
            $board_name = isset($board_names[$board_id]) ? $board_names[$board_id] : $board_id;

            echo "<div class='board-section'>";
            echo "<h2>" . htmlspecialchars($board_name, ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . ")</h2>";

            foreach ($posts as $post) {
                // 各投稿を表示
                displayResponse($post, $base_url, $board_id, $post['thread_id']);
            }
            echo "</div>";
        }
    }

    // ページネーションのリンクを下部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// 掲示板内検索
function searchBoardPosts($db, $board_id, $params, $base_url) {
    // 検索ワードが何も設定されていない場合、検索を行わない
    if (empty($params['query']) && empty($params['date']) && empty($params['id'])) {
        exitWithError("検索ワードが設定されていません。検索ワードを入力してください。");
    }

    // ページネーションの設定
    list($limit, $offset, $page) = getPaginationParams($params);

    $query = "SELECT thread_id, post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id";
    $count_query = "SELECT COUNT(*) as total_posts FROM Posts WHERE board_id = :board_id";
    $conditions = [];
    if (!empty($params['query'])) {
        $conditions[] = "message LIKE :query";
    }
    if (!empty($params['date'])) {
        $conditions[] = "date = :date";
    }
    if (!empty($params['id'])) {
        $conditions[] = "id = :id";
    }
    if ($conditions) {
        $condition_str = " AND " . implode(" AND ", $conditions);
        $query .= $condition_str;
        $count_query .= $condition_str;
    }
    $query .= " ORDER BY thread_id, post_order LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $count_stmt = $db->prepare($count_query);
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $count_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    if (!empty($params['query'])) {
        $stmt->bindValue(':query', '%' . $params['query'] . '%', SQLITE3_TEXT);
        $count_stmt->bindValue(':query', '%' . $params['query'] . '%', SQLITE3_TEXT);
    }
    if (!empty($params['date'])) {
        $stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
        $count_stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
    }
    if (!empty($params['id'])) {
        $stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
        $count_stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $count_result = $count_stmt->execute();

    if (!$result || !$count_result) {
        exitWithError("検索中にデータベースエラーが発生しました。");
    }

    $row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total_posts = $row['total_posts'];
    $total_pages = ceil($total_posts / $limit);

    // 結果をスレッドごとにグループ化
    $grouped_results = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $thread_id = $row['thread_id'];
        if (!isset($grouped_results[$thread_id])) {
            $grouped_results[$thread_id] = [];
        }
        $grouped_results[$thread_id][] = $row;
    }

    // スレッドタイトルを一度に取得して連想配列に格納
    $thread_titles = [];
    $thread_ids = array_keys($grouped_results);
    if (!empty($thread_ids)) {
        $placeholders = implode(',', array_fill(0, count($thread_ids), '?'));
        $query = "SELECT thread_id, title FROM Threads WHERE board_id = ? AND thread_id IN ($placeholders)";
        $stmt = $db->prepare($query);
        $stmt->bindValue(1, $board_id, SQLITE3_TEXT);
        foreach ($thread_ids as $index => $thread_id_value) {
            $stmt->bindValue($index + 2, $thread_id_value, SQLITE3_INTEGER);
        }
        $result_titles = $stmt->execute();
        while ($row = $result_titles->fetchArray(SQLITE3_ASSOC)) {
            $thread_titles[$row['thread_id']] = $row['title'];
        }
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>検索結果</title>";
    // CSSスタイルを追加
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }";
    echo "header, footer { background-color: #333; color: #fff; padding: 10px; }";
    echo "h1, h2 { color: #333; }";
    echo ".container { width: 80%; margin: auto; background-color: #fff; padding: 20px; }";
    echo "a { color: #0066cc; text-decoration: none; }";
    echo "a:hover { text-decoration: underline; }";
    echo ".pagination { margin-top: 20px; }";
    echo ".pagination a { margin: 0 5px; }";
    echo ".response { border-bottom: 1px solid #ccc; padding: 10px 0; }";
    echo ".thread-section { margin-bottom: 40px; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>検索結果: " . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "</h1>";

    // 検索画面に戻るリンクを追加
    echo "<p><a href='{$base_url}/{$board_id}/'>← 検索画面に戻る</a></p>";

    // ページネーションのリンクを上部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    if ($total_posts == 0) {
        echo "<p>該当する投稿はありませんでした。</p>";
    } else {
        foreach ($grouped_results as $thread_id => $posts) {
            $title = isset($thread_titles[$thread_id]) ? $thread_titles[$thread_id] : "スレッドID: $thread_id";
            echo "<div class='thread-section'>";
            echo "<h2>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>";
            foreach ($posts as $post) {
                displayResponse($post, $base_url, $board_id, $thread_id);
            }
            echo "</div>";
        }
    }

    // ページネーションのリンクを下部に表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// スレッド内の全レス表示（ページネーションなし）
function displayAllResponses($db, $board_id, $thread_id, $base_url) {
    // スレッドタイトルを取得
    $stmt_title = $db->prepare("SELECT title, response_count FROM Threads WHERE board_id = :board_id AND thread_id = :thread_id");
    $stmt_title->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt_title->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result_title = $stmt_title->execute();
    if (!$result_title) {
        exitWithError("スレッドタイトルを取得中にデータベースエラーが発生しました。");
    }
    $row = $result_title->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $res_count = $row['response_count'];
        $title = $row['title'];
    } else {
        exitWithError("指定されたスレッドが見つかりません。");
    }

    // 投稿内容を取得
    $stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order ASC");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("投稿を取得中にデータベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>$title</title>";
    echo "<style>";
    // CSSスタイルを追加
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>$title ($res_count)</h1>";
    echo "<a href='../../'>←← 掲示板一覧に戻る</a> <a href='../'>← スレッド一覧に戻る</a>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row, $base_url, $board_id, $thread_id);
    }

    if (!$hasResult) {
        echo "<p>このスレッドには投稿がありません。</p>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// 指定されたレスを表示
function displaySelectedResponses($db, $board_id, $thread_id, $response_format, $base_url) {
    // レスポンス指定形式のパース
    $post_orders = parseResponseFormat($response_format, $db, $board_id, $thread_id);

    if (empty($post_orders)) {
        exitWithError("無効なレス指定形式です。");
    }
    // スレッドタイトルを取得
    $stmt_title = $db->prepare("SELECT title, response_count FROM Threads WHERE board_id = :board_id AND thread_id = :thread_id");
    $stmt_title->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt_title->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result_title = $stmt_title->execute();
    if (!$result_title) {
        exitWithError("スレッドタイトルを取得中にデータベースエラーが発生しました。");
    }
    $row = $result_title->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $res_count = $row['response_count'];
        $title = $row['title'];
    } else {
        exitWithError("指定されたスレッドが見つかりません。");
    }

    // 必要なレスのみを取得
    $placeholders = implode(',', array_fill(0, count($post_orders), '?'));
    $query = "SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = ? AND thread_id = ? AND post_order IN ($placeholders) ORDER BY post_order ASC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(1, $board_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $thread_id, SQLITE3_INTEGER);
    foreach ($post_orders as $index => $post_order) {
        $stmt->bindValue($index + 3, $post_order, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>$title</title>";
    echo "<style>";
    // CSSスタイルを追加
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>$title ($res_count)</h1>";
    echo "<a href='../../'>←← 掲示板一覧に戻る</a> <a href='../'>← スレッド一覧に戻る</a>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row, $base_url, $board_id, $thread_id);
    }

    if (!$hasResult) {
        echo "<p>該当するレスがありませんでした。</p>";
    }

    echo "</div>";
    echo "</body>";
    echo "</html>";
}

// レスポンス指定形式をパースする関数
function parseResponseFormat($format, $db, $board_id, $thread_id) {
    $post_orders = [];

    if (preg_match('/^(\d+)$/', $format, $matches)) {
        // 単一のレス番号
        $post_order = (int)$matches[1];
        if ($post_order > 0) {
            $post_orders[] = $post_order;
        }
    } elseif (preg_match('/^l(\d+)$/', $format, $matches)) {
        // 最新のレスから指定数
        $last_count = (int)$matches[1];
        if ($last_count > 0) {
            $post_orders = getLastPostOrders($db, $board_id, $thread_id, $last_count);
        }
    } elseif (preg_match('/^(\d+)-(\d+)$/', $format, $matches)) {
        // 範囲指定
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        if ($start > 0 && $end >= $start) {
            for ($i = $start; $i <= $end; $i++) {
                $post_orders[] = $i;
            }
        }
    } elseif (preg_match('/^(\d+(,\d+)*)$/', $format)) {
        // カンマ区切り
        $numbers = explode(',', $format);
        foreach ($numbers as $num) {
            $num = (int)$num;
            if ($num > 0) {
                $post_orders[] = $num;
            }
        }
    }

    return $post_orders;
}

// 最新のレス番号を取得する関数
function getLastPostOrders($db, $board_id, $thread_id, $count) {
    $stmt = $db->prepare("SELECT post_order FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order DESC LIMIT :count");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    $post_orders = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $post_orders[] = $row['post_order'];
    }

    // 取得した投稿番号を昇順に並び替え
    $post_orders = array_reverse($post_orders);

    return $post_orders;
}

// レスを表示する関数
function displayResponse($row, $base_url = '', $board_id = '', $thread_id = '') {
    echo "<div class='response'>";
    echo "<p><strong>" . htmlspecialchars($row['post_order'], ENT_QUOTES, 'UTF-8') . " - " . $row['name'] . "</b>";
    if (!empty($row['mail'])) {
        echo " (<a href='mailto:" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "</a>)";
    }
    echo "</strong>";
    echo " " . htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') . " " . htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8');
    if (!empty($row['id'])) {
        echo " ID:" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8');
    }
    echo "</p>";

    // メッセージの処理
    $message = $row['message'];

    // 1. 引用レスをリンク化
    $message = linkifyReplies($message, $base_url, $board_id, $thread_id);

    // 2. 未リンクのURLをリンク化
    $message = linkifyUrls($message);

    // 改行を<br>に変換
    $message = nl2br($message);

    echo "<p>" . $message . "</p>";
    echo "</div>";
}

// 未リンクのURLをリンク化する関数
function linkifyUrls($html) {
    $dom = new DOMDocument();

    // エンコーディングの問題を避けるためにエラーハンドリングを抑制
    libxml_use_internal_errors(true);

    // HTMLをロード
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // XPathを使用してテキストノードを取得
    $xpath = new DOMXPath($dom);
    $textNodes = $xpath->query('//text()');

    foreach ($textNodes as $textNode) {
        $text = $textNode->nodeValue;

        // URLをリンク化
        $newText = preg_replace_callback('/(https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/i', function($matches) {
            $url = $matches[0];

            // URLがhttpまたはhttpsで始まっていない場合は補完
            if (!preg_match('/^https?:\/\//i', $url)) {
                $href = 'http://' . $url;
            } else {
                $href = $url;
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
        }, $text);

        // テキストが変更された場合のみ置換
        if ($newText !== $text) {
            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML($newText);
            $textNode->parentNode->replaceChild($fragment, $textNode);
        }
    }

    // HTMLを取得
    $html = $dom->saveHTML();

    // 不要なXML宣言を削除
    $html = preg_replace('/^<\?xml.*?\?>\s*/', '', $html);

    // エラーハンドリングを元に戻す
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    return $html;
}

// 引用レスをリンク化する関数
function linkifyReplies($text, $base_url, $board_id, $thread_id) {
    // 引用レスの正規表現パターン
    $reply_pattern = '/&gt;&gt;(\d+(-\d+)?)/';

    $text = preg_replace_callback($reply_pattern, function($matches) use ($base_url, $board_id, $thread_id) {
        $reply_number = $matches[1];
        $link = $base_url . '/' . $board_id . '/' . $thread_id . '/' . $reply_number;
        return '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">&gt;&gt;' . htmlspecialchars($reply_number, ENT_QUOTES, 'UTF-8') . '</a>';
    }, $text);

    return $text;
}

?>
