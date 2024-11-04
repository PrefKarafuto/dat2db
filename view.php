<?php
// ヘッダーの設定（出力エンコーディングをShift-JISに統一）
header('Content-Type: text/html; charset=Shift-JIS');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// エラーレポートの設定（本番環境では display_errors を '0' に設定）
error_reporting(E_ALL);
ini_set('display_errors', '0');

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
$parsed_url = parse_url($_SERVER['REQUEST_URI']);
$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
$path_segments = parseUrlPath($_SERVER['REQUEST_URI'], $base_url);

// リクエストの種類を判定して処理を振り分け
if (isDataRequest($path_segments)) {
    handleDataRequest($db, $path_segments);
} else {
    handleWebRequest($db, $base_url, $path_segments);
}

$db->close();

// エラー出力と終了
function exitWithError($message) {
    // エンコーディングの変換
    $output = mb_convert_encoding($message . "\n", 'SJIS', 'UTF-8');
    // Content-Typeの設定
    if (strpos($message, '<html') !== false) {
        header('Content-Type: text/html; charset=Shift-JIS');
    } else {
        header('Content-Type: text/plain; charset=Shift-JIS');
    }
    echo $output;
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

// データリクエストかどうかを判定
function isDataRequest($path_segments) {
    if (empty($path_segments)) return false;
    if ($path_segments[0] === 'test' && isset($path_segments[1]) && strtolower($path_segments[1]) === 'bbs.cgi') return true;
    if (isset($path_segments[1]) && in_array(strtolower($path_segments[1]), ['head.txt', 'subject.txt', 'setting.txt', 'dat'])) return true;
    return false;
}

// データリクエストの処理
function handleDataRequest($db, $path_segments) {
    if ($path_segments[0] === 'test' && isset($path_segments[1]) && strtolower($path_segments[1]) === 'bbs.cgi') {
        handle_bbs_cgi();
        exit;
    }

    $board_id = $path_segments[0];
    if (!isValidBoardId($board_id)) {
        exitWithError("無効なboard_idです。");
    }

    $board_check_stmt = $db->prepare("SELECT 1 FROM Boards WHERE board_id = :board_id");
    $board_check_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $board_check_result = $board_check_stmt->execute();
    if (!$board_check_result || !$board_check_result->fetchArray(SQLITE3_ASSOC)) {
        exitWithError("指定された掲示板が見つかりません: {$board_id}");
    }

    if (!isset($path_segments[1])) {
        exitWithError("不正なリクエストです。");
    }
    $request_file = $path_segments[1];

    switch (strtolower($request_file)) {
        case 'head.txt':
            handle_head_txt($db, $board_id);
            break;
        case 'subject.txt':
            handle_subject_txt($db, $board_id);
            break;
        case 'setting.txt':
            handle_setting_txt($db, $board_id);
            break;
        case 'dat':
            if (isset($path_segments[2])) {
                handle_dat_file($db, $board_id, $path_segments[2]);
            } else {
                exitWithError("不正なリクエストです。");
            }
            break;
        default:
            exitWithError("不正なリクエストです。");
            break;
    }
}

// ウェブリクエストの処理
function handleWebRequest($db, $base_url, $path_segments) {
    $path_segments = parseUrlPath($_SERVER['REQUEST_URI'], $base_url);

    if (empty($path_segments)) {
        displayBoardList($db, $base_url);
    } elseif (count($path_segments) === 1) {
        $board_id = $path_segments[0];
        if ($board_id === 'bbsmenu.html') {
            displayBBSmenuHtml($db, $base_url);
        } else {
            if (!isValidBoardId($board_id)) {
                exitWithError("無効なboard_idです。");
            } else {
                displayThreadList($db, $board_id, $base_url);
            }
        }
    } elseif (count($path_segments) === 2) {
        $board_id = $path_segments[0];
        $thread_id = $path_segments[1];
        if (!isValidBoardId($board_id) || !isValidThreadId($thread_id)) {
            exitWithError("無効なURLです。");
        }
        displayAllResponses($db, $board_id, $thread_id, $base_url);
    } elseif (count($path_segments) === 3) {
        $board_id = $path_segments[0];
        $thread_id = $path_segments[1];
        $response_format = $path_segments[2];
        if (!isValidBoardId($board_id) || !isValidThreadId($thread_id)) {
            exitWithError("無効なURLです。");
        }
        displaySelectedResponses($db, $board_id, $thread_id, $response_format, $base_url);
    } else {
        exitWithError("無効なURL形式です。");
    }
}

// 掲示板一覧の表示
function displayBoardList($db, $base_url) {
    $result = $db->query("SELECT board_id, board_name FROM Boards");
    if (!$result) exitWithError("データベースエラーが発生しました。");

    ob_start();
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='Shift_JIS'><title>掲示板一覧</title>";
    echo "<style>/* スタイルシートは省略 */</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>掲示板一覧</h1>";
    echo "<p><a href=\"search.php\">検索</a></p>";
    echo "<p><a href=\"{$base_url}/bbsmenu.html\">専ブラ用BBSMENU</a></p>";
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $board_id = htmlspecialchars($row['board_id'], ENT_QUOTES, 'UTF-8');
        $board_name = htmlspecialchars($row['board_name'], ENT_QUOTES, 'UTF-8');
        echo "<li><a href='{$base_url}/{$board_id}/'>{$board_name}</a></li>";
    }
    echo "</ul>";
    echo "</div>";
    echo "</body>";
    echo "</html>";
    $output = ob_get_clean();
    $output_sjis = mb_convert_encoding($output, 'SJIS', 'UTF-8');
    echo $output_sjis;
}

// スレッド一覧の表示
function displayThreadList($db, $board_id, $base_url) {
    $params = sanitizeInput($_GET);
    list($limit, $offset, $page) = getPaginationParams($params);

    $stmt = $db->prepare("SELECT COUNT(*) as total_threads FROM Threads WHERE board_id = :board_id");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $total_threads = $row['total_threads'];
    $total_pages = ceil($total_threads / $limit);
    if (!$total_threads) exitWithError("そのような掲示板はありません。");

    $stmt = $db->prepare("SELECT thread_id, title, response_count FROM Threads WHERE board_id = :board_id ORDER BY thread_id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) exitWithError("データベースエラーが発生しました。");

    ob_start();
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='Shift_JIS'><title>スレッド一覧</title>";
    echo "<style>/* スタイルシートは省略 */</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>" . htmlspecialchars($board_id, ENT_QUOTES, 'UTF-8') . "のスレッド一覧 ($total_threads)</h1>";
    echo "<p><a href='{$base_url}'>← 掲示板一覧に戻る</a></p>";

    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", []);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }

    echo "<ul>";
    $thread_order = ($page - 1) * $limit;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $thread_id = htmlspecialchars($row['thread_id'], ENT_QUOTES, 'UTF-8');
        $title = $row['title'];
        $res_count = $row['response_count'];
        $thread_order++;
        echo "<li>{$thread_order}: <a href='{$base_url}/{$board_id}/{$thread_id}/'>{$title} ({$res_count})</a></li>";
    }
    echo "</ul>";

    if ($total_pages > 1) {
        $pagination_links = generatePaginationLinks($page, $total_pages, "{$base_url}/{$board_id}/", []);
        echo "<div class='pagination'>{$pagination_links}</div>";
    }
    echo "</div>";
    echo "</body>";
    echo "</html>";
    $output = ob_get_clean();
    $output_sjis = mb_convert_encoding($output, 'SJIS', 'UTF-8');
    echo $output_sjis;
}

// BBSMENUの表示
function displayBBSmenuHtml($db, $base_url) {
    $result = $db->query("SELECT board_id, board_name, category_name FROM Boards ORDER BY category_name ASC, board_id ASC");
    if (!$result) exitWithError("データベースエラーが発生しました。");

    $categories = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $category_name = $row['category_name'];
        if (!isset($categories[$category_name])) {
            $categories[$category_name] = [];
        }
        $categories[$category_name][] = $row;
    }

    ob_start();
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<HEAD><META http-equiv=\"Content-Type\" content=\"text/html; charset=Shift_JIS\">";
    echo "<TITLE>BBS MENU</TITLE>";
    echo "<BASE TARGET=\"_blank\"></HEAD>";
    echo "<BODY TEXT=\"#CC3300\" BGCOLOR=\"#FFFFFF\" link=\"#0000FF\" alink=\"#ff0000\" vlink=\"#660099\">";
    echo "<B>BBS MENU</B><BR>";
    echo "<BR>専用ブラウザ用<FONT size=2>";

    foreach ($categories as $category_name => $boards) {
        $category_name_escaped = htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8');
        echo "<BR><BR><B>{$category_name_escaped}</B><BR>";

        foreach ($boards as $board) {
            $board_id = htmlspecialchars($board['board_id'], ENT_QUOTES, 'UTF-8');
            $board_name = $board['board_name']; // エスケープしない
            $board_url = htmlspecialchars($base_url . '/' . $board_id . '/', ENT_QUOTES, 'UTF-8');

            echo "<A HREF=\"{$board_url}\">{$board_name}</A><BR>";
        }
    }

    echo "<BR><BR><BR>";
    echo "</FONT>";
    echo "</BODY>";
    echo "</HTML>";

    $html_output = ob_get_clean();
    $html_output_sjis = mb_convert_encoding($html_output, 'Shift_JIS', 'UTF-8');
    echo $html_output_sjis;
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

    ob_start();
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='Shift_JIS'><title>$title</title>";
    echo "<style>/* スタイルシートは省略 */</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>$title ($res_count)</h1>";
    echo "<a href='{$base_url}'>←← 掲示板一覧に戻る</a> <a href='./'>← スレッド一覧に戻る</a>";

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
    $output = ob_get_clean();
    $output_sjis = mb_convert_encoding($output, 'Shift_JIS', 'UTF-8');
    echo $output_sjis;
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

    ob_start();
    echo "<!DOCTYPE html>";
    echo "<html lang='ja'>";
    echo "<head><meta charset='Shift_JIS'><title>$title</title>";
    echo "<style>/* スタイルシートは省略 */</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='container'>";
    echo "<h1>$title ($res_count)</h1>";
    echo "<a href='{$base_url}'>←← 掲示板一覧に戻る</a> <a href='./'>← スレッド一覧に戻る</a>";

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
    $output = ob_get_clean();
    $output_sjis = mb_convert_encoding($output, 'Shift_JIS', 'UTF-8');
    echo $output_sjis;
}

// レスポンス指定形式をパースする関数
function parseResponseFormat($format, $db, $board_id, $thread_id) {
    $post_orders = [];

    if (preg_match('/^(\d+)$/', $format, $matches)) {
        $post_order = (int)$matches[1];
        if ($post_order > 0) {
            $post_orders[] = $post_order;
        }
    } elseif (preg_match('/^l(\d+)$/', $format, $matches)) {
        $last_count = (int)$matches[1];
        if ($last_count > 0) {
            $post_orders = getLastPostOrders($db, $board_id, $thread_id, $last_count);
        }
    } elseif (preg_match('/^(\d+)-(\d+)$/', $format, $matches)) {
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        if ($start > 0 && $end >= $start) {
            for ($i = $start; $i <= $end; $i++) {
                $post_orders[] = $i;
            }
        }
    } elseif (preg_match('/^(\d+(,\d+)*)$/', $format)) {
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

    $post_orders = array_reverse($post_orders);

    return $post_orders;
}

// レスを表示する関数
function displayResponse($row, $base_url = '', $board_id = '', $thread_id = '') {
    $post_url = $base_url . '/' . $board_id . '/' . $thread_id . '/' . $row['post_order'];
    echo "<div class='response'>";
    echo "<p><strong><a name=".$row['post_order'] . " href=".$post_url .">" . $row['post_order'] . "</a> - " . $row['name'] . "</b></strong>";
    if (!empty($row['mail'])) {
        echo " (<a href='mailto:" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . "</a>)";
    }
    echo " " . htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') . " " . htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8');
    if (!empty($row['id'])) {
        echo " ID:" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8');
    }
    echo "</p>";

    $message = $row['message'];

    $message = linkifyReplies($message, $base_url, $board_id, $thread_id);

    $message = linkifyUrls($message);

    $message = nl2br($message);

    echo "<p>" . $message . "</p>";
    echo "</div>";
}

// 未リンクのURLをリンク化する関数
function linkifyUrls($text) {
    // URLのパターンを定義
    $urlPattern = '/\b((https?:\/\/)|(www\.))([^\s<>"\']+)/i';

    // コールバック関数でURLを<a>タグに変換
    $linkedText = preg_replace_callback($urlPattern, function($matches) {
        $url = $matches[0];

        // 'www.'で始まる場合は'http://'を追加
        if (!preg_match('/^https?:\/\//i', $url)) {
            $href = 'http://' . $url;
        } else {
            $href = $url;
        }

        // htmlspecialcharsでエスケープ
        $escapedHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<a href="' . $escapedHref . '">' . $escapedUrl . '</a>';
    }, $text);

    return $linkedText;
}


// 引用レスをリンク化する関数
function linkifyReplies($text, $base_url, $board_id, $thread_id) {
    $reply_pattern = '/&gt;&gt;(\d+(-\d+)?)/';

    $text = preg_replace_callback($reply_pattern, function($matches) use ($base_url, $board_id, $thread_id) {
        $reply_number = $matches[1];
        $link = $base_url . '/' . $board_id . '/' . $thread_id . '/' . $reply_number;
        return '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">&gt;&gt;' . htmlspecialchars($reply_number, ENT_QUOTES, 'UTF-8') . '</a>';
    }, $text);

    return $text;
}

// ページネーションリンク生成
function generatePaginationLinks($current_page, $total_pages, $base_url, $query_params = []) {
    $links = '';
    unset($query_params['page']);

    if ($current_page > 1) {
        $params = $query_params;
        $params['page'] = 1;
        $query_string = http_build_query($params);
        $links .= "<a href='{$base_url}?{$query_string}'>&lt;&lt;</a> ";

        $prev_page = $current_page - 1;
        $params['page'] = $prev_page;
        $query_string = http_build_query($params);
        $links .= "<a href='{$base_url}?{$query_string}'>&lt;</a> ";
    } else {
        $links .= "&lt;&lt; &lt; ";
    }

    $links .= "{$current_page} / {$total_pages}";

    if ($current_page < $total_pages) {
        $params = $query_params;
        $next_page = $current_page + 1;
        $params['page'] = $next_page;
        $query_string = http_build_query($params);
        $links .= " <a href='{$base_url}?{$query_string}'>&gt;</a>";

        $params['page'] = $total_pages;
        $query_string = http_build_query($params);
        $links .= " <a href='{$base_url}?{$query_string}'>&gt;&gt;</a>";
    } else {
        $links .= " &gt; &gt;&gt;";
    }

    return $links;
}

// 共通関数

// 入力をサニタイズ
function sanitizeInput($input) {
    $sanitized = [];
    $allowed_keys = ['query', 'title', 'date', 'id', 'page'];
    foreach ($allowed_keys as $key) {
        if (isset($input[$key])) {
            $value = trim($input[$key]);
            if ($key === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) continue;
            if ($key === 'id' && !preg_match('/^[a-zA-Z0-9_\.\-\/\+]+$/', $value)) continue;
            if ($key === 'page' && !preg_match('/^\d+$/', $value)) continue;
            if ($value === '') continue;
            $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }
    return $sanitized;
}

// board_idのバリデーション
function isValidBoardId($board_id) {
    return preg_match('/^(?!test$)(?!dat$)[a-zA-Z0-9_\-]+$/', $board_id);
}

// thread_idのバリデーション
function isValidThreadId($thread_id) {
    return preg_match('/^\d+$/', $thread_id);
}

// ページネーションのパラメータ取得
function getPaginationParams($params) {
    $page = isset($params['page']) ? (int)$params['page'] : 1;
    if ($page < 1) $page = 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    return [$limit, $offset, $page];
}

// 以下、専ブラ用の関数

function handle_bbs_cgi() {
    header('Content-Type: text/html; charset=Shift-JIS');
    $line = "<!DOCTYPE html>\n";
    $line .= "<html lang='ja'>\n";
    $line .= "<head>\n";
    $line .= "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=Shift_JIS\">\n";
    $line .= "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\">\n";
    $line .= "<title>ＥＲＲＯＲ！</title>\n";
    $line .= "</head>\n";
    $line .= "<!--nobanner-->\n";
    $line .= "<body>\n";
    $line .= "<!-- 2ch_X:error -->\n";
    $line .= "<div style=\"margin-bottom:2em;\">\n";
    $line .= "<font size=\"+1\" color=\"#FF0000\"><b>ＥＲＲＯＲ：読み取り専用</b></font>\n";
    $line .= "</div>\n";
    $line .= "<blockquote>\n";
    $line .= "<div>読み取り専用につき投稿できません。</div>\n";
    $line .= "</blockquote>\n";
    $line .= "</body>\n";
    $line .= "</html>\n";

    $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8') ?: mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
    echo $converted_line;
}

function handle_head_txt($db, $board_id) {
    header('Content-Type: text/plain; charset=Shift-JIS');
    $line = "この掲示板は読み取り専用です。";
    $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8') ?: mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
    echo $converted_line . "\n";
}

function handle_subject_txt($db, $board_id) {
    header('Content-Type: text/plain; charset=Shift-JIS');
    $stmt = $db->prepare("SELECT thread_id, title, response_count FROM Threads WHERE board_id = :board_id ORDER BY thread_id DESC");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) exitWithError("データベースエラーが発生しました。");

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $line = "{$row['thread_id']}.dat<>{$row['title']} ({$row['response_count']})";
        $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8') ?: mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
        echo $converted_line . "\n";
    }
}

function handle_setting_txt($db, $board_id) {
    header('Content-Type: text/plain; charset=Shift-JIS');
    $stmt = $db->prepare("SELECT board_name FROM Boards WHERE board_id = :board_id");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) exitWithError("データベースエラーが発生しました。");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) exitWithError("指定された掲示板が見つかりません: {$board_id}");
    $line = "BBS_TITLE={$row['board_name']}\nBBS_NONAME_NAME=読み取り専用\nBBS_READONLY=on";
    $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8') ?: mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
    echo $converted_line . "\n";
}

function handle_dat_file($db, $board_id, $thread_file) {
    header('Content-Type: text/plain; charset=Shift-JIS');
    $thread_id = basename($thread_file, '.dat');
    if (!isValidThreadId($thread_id)) {
        exitWithError("無効なthread_idです。");
    }
    $title_stmt = $db->prepare("SELECT title FROM Threads WHERE board_id = :board_id AND thread_id = :thread_id");
    $title_stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $title_stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $title_result = $title_stmt->execute();
    if (!$title_result) exitWithError("データベースエラーが発生しました。");
    $title_row = $title_result->fetchArray(SQLITE3_ASSOC);
    if (!$title_row) exitWithError("指定されたスレッドが見つかりません: thread_id = {$thread_id}");

    $stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) exitWithError("データベースエラーが発生しました。");

    $is_first_line = true;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $name = str_replace('<>', '', $row['name'] ?? '');
        $mail = str_replace('<>', '', $row['mail'] ?? '');
        $date_time_id = str_replace('<>', '', "{$row['date']} {$row['time']} ID:{$row['id']}");
        $message = str_replace('<>', '', $row['message'] ?? '');
        $title = $title_row['title'] ?? '';
        $line = $is_first_line ? "{$name}<>{$mail}<>{$date_time_id}<>{$message}<>{$title}" : "{$name}<>{$mail}<>{$date_time_id}<>{$message}<>";
        $is_first_line = false;
        $converted_line = @mb_convert_encoding($line, 'SJIS', 'UTF-8') ?: mb_convert_encoding('文字化けが発生しました。', 'SJIS', 'UTF-8');
        echo $converted_line . "\n";
    }
}
?>
