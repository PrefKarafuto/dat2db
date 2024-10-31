<?php
// データベースファイルの確認
$db_file = './converter/bbs_log.db';
if (!file_exists($db_file)) {
    echo "エラー: データベースファイル '{$db_file}' が存在しません。\n";
    exit;
}

// データベース接続
$db = new SQLite3($db_file);

// URLパラメータ解析
$requestUri = $_SERVER['REQUEST_URI'];
$path = explode('/', trim($requestUri, '/'));

switch (count($path)) {
    case 1:
        if (isset($_GET['query']) || isset($_GET['date']) || isset($_GET['id'])) {
            $sanitized_params = sanitizeInput($_GET);
            searchAllPosts($db, $sanitized_params);
        } else {
            displayBoardList($db);
        }
        break;
    
    case 2:
        $board_id = htmlspecialchars($path[1], ENT_QUOTES, 'UTF-8'); 
        if (!preg_match('/^[a-zA-Z0-9]+$/', $board_id)) {
            echo "無効なboard_idです。";
            exit;
        }

        if (isset($_GET['query']) || isset($_GET['date']) || isset($_GET['id'])) {
            $sanitized_params = sanitizeInput($_GET);
            searchBoardPosts($db, $board_id, $sanitized_params);
        } else {
            displayThreadList($db, $board_id);
        }
        break;

    case 3:
        $board_id = htmlspecialchars($path[1], ENT_QUOTES, 'UTF-8');
        $thread_id = htmlspecialchars($path[2], ENT_QUOTES, 'UTF-8');

        if (!preg_match('/^[a-zA-Z0-9]+$/', $board_id)) {
            echo "無効なboard_idです。";
            exit;
        }
        if (!preg_match('/^\d{10}$/', $thread_id)) {
            echo "無効なthread_idです。";
            exit;
        }

        displayAllResponses($db, $board_id, $thread_id);
        break;

    case 4:
        $board_id = htmlspecialchars($path[1], ENT_QUOTES, 'UTF-8');
        $thread_id = htmlspecialchars($path[2], ENT_QUOTES, 'UTF-8');
        $response_format = htmlspecialchars($path[3], ENT_QUOTES, 'UTF-8');

        if (!preg_match('/^[a-zA-Z0-9]+$/', $board_id)) {
            echo "無効なboard_idです。";
            exit;
        }
        if (!preg_match('/^\d{10}$/', $thread_id)) {
            echo "無効なthread_idです。";
            exit;
        }

        displaySelectedResponses($db, $board_id, $thread_id, $response_format);
        break;

    default:
        echo "無効なURL形式です。";
}

$db->close();

// サニタイズ関数
function sanitizeInput($input) {
    $sanitized = [];
    foreach ($input as $key => $value) {
        $sanitized[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }
    return $sanitized;
}

// 掲示板一覧の表示
function displayBoardList($db) {
    echo "<h1>掲示板一覧</h1>";
    $result = $db->query("SELECT board_id, board_name FROM Boards");
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "<li><a href='/view.php/" . htmlspecialchars($row['board_id'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($row['board_name'], ENT_QUOTES, 'UTF-8') . "</a></li>";
    }
    echo "</ul>";
    echo "<h2>全文検索・日付検索・ID検索</h2>";
    echo "<form method='get' action='/view.php'>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";
}

// 掲示板のスレッド一覧を表示
function displayThreadList($db, $board_id) {
    echo "<h1>{$board_id}のスレッド一覧</h1>";
    $stmt = $db->prepare("SELECT thread_id, title FROM Titles WHERE board_id = :board_id");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    echo "<ul>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "<li><a href='/view.php/{$board_id}/{$row['thread_id']}'>" . htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') . "</a></li>";
    }
    echo "</ul>";
    echo "<h2>全文検索・日付検索・ID検索</h2>";
    echo "<form method='get' action='/view.php/{$board_id}'>";
    echo "<input type='text' name='query' placeholder='全文検索'><br>";
    echo "<input type='date' name='date' placeholder='日付検索'><br>";
    echo "<input type='text' name='id' placeholder='ID検索'><br>";
    echo "<input type='submit' value='検索'></form>";
}

// 全体検索機能
function searchAllPosts($db, $params) {
    echo "<h1>全体検索結果</h1>";
    $query = "SELECT board_id, thread_id, post_order, name, mail, date, time, id, message FROM Posts WHERE 1=1";
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
        $query .= " AND " . implode(" AND ", $conditions);
    }
    $stmt = $db->prepare($query);
    if (!empty($params['query'])) {
        $stmt->bindValue(':query', "%{$params['query']}%", SQLITE3_TEXT);
    }
    if (!empty($params['date'])) {
        $stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
    }
    if (!empty($params['id'])) {
        $stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        displayResponse($row);
    }
}

// 指定された掲示板内の投稿を検索
function searchBoardPosts($db, $board_id, $params) {
    echo "<h1>検索結果: {$board_id}</h1>";
    $query = "SELECT thread_id, post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id";
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
        $query .= " AND " . implode(" AND ", $conditions);
    }
    $stmt = $db->prepare($query);
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    if (!empty($params['query'])) {
        $stmt->bindValue(':query', "%{$params['query']}%", SQLITE3_TEXT);
    }
    if (!empty($params['date'])) {
        $stmt->bindValue(':date', $params['date'], SQLITE3_TEXT);
    }
    if (!empty($params['id'])) {
        $stmt->bindValue(':id', $params['id'], SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        displayResponse($row);
    }
}

// スレッド内の全レス表示
function displayAllResponses($db, $board_id, $thread_id) {
    echo "<h1>スレッド: {$thread_id} の全レス表示</h1>";
    $stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        displayResponse($row);
    }
}

// 指定されたレスを表示する関数
function displaySelectedResponses($db, $board_id, $thread_id, $response_format) {
    $stmt = $db->prepare("SELECT post_order, name, mail, date, time, id, message FROM Posts WHERE board_id = :board_id AND thread_id = :thread_id ORDER BY post_order");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':thread_id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $responses = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $responses[$row['post_order']] = $row;
    }

    if (preg_match('/^(\d+)$/', $response_format, $matches)) {
        $post_order = (int)$matches[1];
        if (isset($responses[$post_order])) {
            displayResponse($responses[$post_order]);
        }
    } elseif (preg_match('/^l(\d+)$/', $response_format, $matches)) {
        $last_count = (int)$matches[1];
        $last_responses = array_slice($responses, -$last_count, $last_count, true);
        foreach ($last_responses as $response) {
            displayResponse($response);
        }
    } elseif (preg_match('/^(\d+)-(\d+)$/', $response_format, $matches)) {
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        foreach ($responses as $order => $response) {
            if ($order >= $start && $order <= $end) {
                displayResponse($response);
            }
        }
    } elseif (preg_match('/^(\d+(,\d+)*)$/', $response_format, $matches)) {
        $specified_orders = array_map('intval', explode(',', $response_format));
        foreach ($specified_orders as $order) {
            if (isset($responses[$order])) {
                displayResponse($responses[$order]);
            }
        }
    } else {
        echo "無効なレス指定形式です。";
    }
}

// レスを表示するためのヘルパー関数
function displayResponse($row) {
    echo "<div class='response'>";
    echo "<p><strong>" . htmlspecialchars($row['post_order'], ENT_QUOTES, 'UTF-8') . " - " . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($row['mail'], ENT_QUOTES, 'UTF-8') . ")</strong></p>";
    echo "<p>" . htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') . " " . htmlspecialchars($row['time'], ENT_QUOTES, 'UTF-8') . " ID:" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "</p>";
    echo "<p>" . htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') . "</p>";
    echo "</div>";
}
?>
