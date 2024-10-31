<?php
// ヘッダーの設定
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// エラーレポートの設定（デバッグ時のみ有効にする）
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 本番環境では'0'に設定

// データベースファイルの確認と接続
$db_file = './converter/bbs_log.db';
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
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['query']) || isset($_GET['date']) || isset($_GET['id']))) {
        // 全体検索
        $sanitized_params = sanitizeInput($_GET);
        searchAllPosts($db, $sanitized_params, $base_url);
    } else {
        // 掲示板一覧の表示
        displayBoardList($db, $base_url);
    }
} elseif (count($path) === 1) {
    $board_id = $path[0];
    if (!isValidBoardId($board_id)) {
        exitWithError("無効なboard_idです。");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['query']) || isset($_GET['date']) || isset($_GET['id']))) {
        // 掲示板内検索
        $sanitized_params = sanitizeInput($_GET);
        searchBoardPosts($db, $board_id, $sanitized_params, $base_url);
    } else {
        // スレッド一覧の表示
        displayThreadList($db, $board_id, $base_url);
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
    $allowed_keys = ['query', 'date', 'id', 'page'];
    foreach ($allowed_keys as $key) {
        if (isset($input[$key])) {
            $value = trim($input[$key]);
            // 各種バリデーション
            if ($key === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                continue;
            }
            if ($key === 'id' && !preg_match('/^[a-zA-Z0-9]+$/', $value)) {
                continue;
            }
            if ($key === 'page' && !preg_match('/^\d+$/', $value)) {
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

    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $params = $query_params;
        $params['page'] = $prev_page;
        $query_string = http_build_query($params);
        $links .= "<a href='{$base_url}?{$query_string}'>前のページ</a> ";
    }

    $links .= "ページ {$current_page} / {$total_pages}";

    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $params = $query_params;
        $params['page'] = $next_page;
        $query_string = http_build_query($params);
        $links .= " <a href='{$base_url}?{$query_string}'>次のページ</a>";
    }

    return $links;
}


// 掲示板一覧の表示
function displayBoardList($db, $base_url) {
    $result = $db->query("SELECT board_id, board_name FROM Boards");
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>掲示板一覧</title></head>";
    echo "<body>";
    echo "<h1>掲示板一覧</h1>";
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $board_id = htmlspecialchars($row['board_id'], ENT_QUOTES, 'UTF-8');
        $board_name = htmlspecialchars($row['board_name'], ENT_QUOTES, 'UTF-8');
        echo "<li><a href='{$base_url}/{$board_id}'>{$board_name}</a></li>";
    }
    echo "</ul>";

    echo "<h2>全文検索・日付検索・ID検索</h2>";
    echo "<form method='get' action='{$base_url}/'>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";

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

    $stmt = $db->prepare("SELECT thread_id, title FROM Threads WHERE board_id = :board_id ORDER BY thread_id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>スレッド一覧</title></head>";
    echo "<body>";
    echo "<h1>" . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "のスレッド一覧</h1>";
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $thread_id = htmlspecialchars($row['thread_id'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
        echo "<li><a href='{$base_url}/{$board_id}/{$thread_id}/'>{$title}</a></li>";
    }
    echo "</ul>";

    // ページネーションのリンクを表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", []);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "<h2>全文検索・日付検索・ID検索</h2>";
    echo "<form method='get' action='{$base_url}/{$board_id}/'>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";

    echo "</body>";
    echo "</html>";
}

// 全体検索
function searchAllPosts($db, $params, $base_url) {
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
    $query .= " ORDER BY date DESC, time DESC LIMIT :limit OFFSET :offset";
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

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>全体検索結果</title></head>";
    echo "<body>";
    echo "<h1>全体検索結果</h1>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row, $base_url, $row['board_id'], $row['thread_id']);
    }

    if (!$hasResult) {
        echo "<p>該当する投稿はありませんでした。</p>";
    }

    // ページネーションのリンクを表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</body>";
    echo "</html>";
}

// 掲示板内検索
function searchBoardPosts($db, $board_id, $params, $base_url) {
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
    $query .= " ORDER BY date DESC, time DESC LIMIT :limit OFFSET :offset";
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

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>検索結果</title></head>";
    echo "<body>";
    echo "<h1>検索結果: " . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "</h1>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row, $base_url, $board_id, $row['thread_id']);
    }

    if (!$hasResult) {
        echo "<p>該当する投稿はありませんでした。</p>";
    }

    // ページネーションのリンクを表示
    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", $params);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "</body>";
    echo "</html>";
}

// スレッド内の全レス表示（ページネーションなし）
function displayAllResponses($db, $board_id, $thread_id, $base_url) {
    $stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order ASC");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        exitWithError("データベースエラーが発生しました。");
    }

    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='UTF-8'><title>スレッド表示</title></head>";
    echo "<body>";
    echo "<h1>スレッド: " . htmlspecialchars($thread_id, ENT_QUOTES, 'UTF-8') . "</h1>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row);
    }

    if (!$hasResult) {
        echo "<p>このスレッドには投稿がありません。</p>";
    }

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
    echo "<head><meta charset='UTF-8'><title>レス表示</title></head>";
    echo "<body>";
    echo "<h1>スレッド: " . htmlspecialchars($thread_id, ENT_QUOTES, 'UTF-8') . " の指定レス表示</h1>";

    $hasResult = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $hasResult = true;
        displayResponse($row);
    }

    if (!$hasResult) {
        echo "<p>該当するレスがありませんでした。</p>";
    }

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
    echo "<p><strong>" . htmlspecialchars($row['post_order'], ENT_QUOTES, 'UTF-8') . " - " . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
    if (!empty($row['mail'])) {
        echo " (<a href='mailto:" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "</a>)";
    }
    echo "</strong></p>";
    echo "<p>" . htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') . " " . htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8');
    if (!empty($row['id'])) {
        echo " ID:" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8');
    }
    echo "</p>";
    echo "<p>" . nl2br(htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8')) . "</p>";
    echo "</div>";
}
?>
