<?php
// search.php

// 出力バッファリング開始
ob_start();

// データベース接続の設定
$db = new SQLite3('bbs_log.db');
if (!$db) {
    die("データベースに接続できませんでした。");
}

// エスケープ用関数（XSS対策）
function escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Shift-JIS⇔UTF-8変換用関数
function sjis_to_utf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'SJIS');
}
function utf8_to_sjis($str) {
    return mb_convert_encoding($str, 'SJIS', 'UTF-8');
}

// GETパラメータ（Shift-JISからUTF-8に変換）
$search_type = isset($_GET['search_type']) ? sjis_to_utf8($_GET['search_type']) : 'title';
$search_query = isset($_GET['search_query']) ? trim(sjis_to_utf8($_GET['search_query'])) : '';
$category    = isset($_GET['category'])    ? sjis_to_utf8($_GET['category'])    : '';
$board       = isset($_GET['board'])       ? sjis_to_utf8($_GET['board'])       : '';
$sort_order  = isset($_GET['sort_order'])  ? sjis_to_utf8($_GET['sort_order'])  : 'latest';
$page        = isset($_GET['page'])        ? max(1, intval($_GET['page']))      : 1;
$year        = isset($_GET['year'])        ? intval($_GET['year'])              : 0;  // 追加：検索する年（整数）
$limit       = 20;
$offset      = ($page - 1) * $limit;

// 検索範囲の決定（board 優先、次に category、未指定なら全体）
$scope = 'all';
if (!empty($board)) {
    $scope = 'board';
} elseif (!empty($category)) {
    $scope = 'category';
}

// 全掲示板情報を1回のクエリで取得（カテゴリ名付き）
$allBoards = [];
$board_sql = "SELECT board_id, board_name, category_name FROM Boards ORDER BY board_name ASC";
$board_result = $db->query($board_sql);
while ($row = $board_result->fetchArray(SQLITE3_ASSOC)) {
    $allBoards[] = $row;
}

// 検索実行前の初期化
$results = [];
$total_results = 0;
$total_pages = 0; // 初期化（検索未実施の場合にも定義される）

if ($search_query !== '') {
    // 検索クエリの長さチェック（最大100文字）
    if (mb_strlen($search_query, 'UTF-8') > 100) {
        die("<p>検索クエリが長すぎます。100文字以内にしてください。</p>");
    }
    
    // FTS用検索クエリの作成（各検索タイプに応じる）
    switch ($search_type) {
        case 'message':
            $fts_query = 'message:' . $search_query;
            break;
        case 'id':
            $fts_query = 'id:' . $search_query;
            break;
        case 'title':
            $fts_query = 'thread_title:' . $search_query;
            break;
        case 'full':
        default:
            $fts_query = 'thread_title:' . $search_query . ' OR message:' . $search_query . ' OR name:' . $search_query . ' OR id:' . $search_query;
            break;
    }
    
    // 基本SQL（FTS仮想テーブル Posts_fts を利用）
    $sql = "SELECT Threads.board_id, Threads.thread_id, Threads.title, Threads.response_count, 
                   Boards.category_name, Boards.board_name
            FROM Posts_fts
            JOIN Threads ON Posts_fts.board_id = Threads.board_id AND Posts_fts.thread_id = Threads.thread_id
            JOIN Boards ON Threads.board_id = Boards.board_id
            WHERE Posts_fts MATCH :fts_query";
    $params = [];
    $params[':fts_query'] = $fts_query;
    
    if ($scope === 'board') {
        $sql .= " AND Threads.board_id = :board";
        $params[':board'] = $board;
    } elseif ($scope === 'category') {
        $sql .= " AND Boards.category_name = :category";
        $params[':category'] = $category;
    }
    
    // 追加：年指定（スレッド建て日時＝thread_idがUnixタイムスタンプ）
    if ($year > 0) {
        // 指定年の1月1日～翌年1月1日未満のタイムスタンプを取得
        $start_ts = strtotime("$year-01-01");
        $end_ts = strtotime(($year + 1) . "-01-01");
        $sql .= " AND Threads.thread_id >= :start_ts AND Threads.thread_id < :end_ts";
        $params[':start_ts'] = $start_ts;
        $params[':end_ts']   = $end_ts;
    }
    
    $sql .= " GROUP BY Threads.board_id, Threads.thread_id";
    
    if ($sort_order === 'latest') {
        $sql .= " ORDER BY Threads.thread_id DESC";
    } elseif ($sort_order === 'responses') {
        $sql .= " ORDER BY Threads.response_count DESC";
    } elseif ($sort_order === 'oldest') {
        $sql .= " ORDER BY Threads.thread_id ASC";
    }
    
    // 総件数の取得（サブクエリでグループ化後の件数をカウント）
    $count_sql = "SELECT COUNT(*) as count FROM ($sql) as subquery";
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value, SQLITE3_TEXT);
    }
    $count_result = $count_stmt->execute();
    if ($count_row = $count_result->fetchArray(SQLITE3_ASSOC)) {
        $total_results = $count_row['count'];
    }
    
    $total_pages = ceil($total_results / $limit);
    
    $sql .= " LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    if (!$result) {
        die("<p>検索クエリの実行に失敗しました。</p>");
    }
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // thread_id が Unix タイムスタンプと仮定して日時に変換
        $timestamp = intval($row['thread_id']);
        $row['created_at'] = date("Y-m-d H:i:s", $timestamp);
        $results[] = $row;
    }
}

// カテゴリ一覧は $allBoards から抽出
$categories = [];
foreach ($allBoards as $b) {
    if (!in_array($b['category_name'], $categories)) {
        $categories[] = $b['category_name'];
    }
}
sort($categories);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="Shift_JIS">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベース検索</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>データベース検索</h1>
        <p><a href="view.php">掲示板一覧</a></p>
        <form method="GET" action="search.php" class="search-form">
            <!-- 1行目：検索ワード -->
            <div class="form-row">
                <div class="form-group" style="flex: 1 1 60%;">
                    <input type="text" name="search_query" id="search_query" value="<?php echo escape_html($search_query); ?>" placeholder="検索ワード" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="search-button">検索</button>
                </div>
            </div>
            <!-- 2行目：カテゴリ、掲示板、検索タイプ -->
            <div class="form-row">
                <div class="form-group">
                    <select name="category" id="category">
                        <option value="">カテゴリを選択</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo escape_html($cat); ?>" <?php if ($category === $cat) echo 'selected'; ?>>
                                <?php echo escape_html($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <!-- data-initial属性にGETパラメータのboard値を保持 -->
                    <select name="board" id="board" data-initial="<?php echo escape_html($board); ?>">
                        <option value="">掲示板を選択</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="search_type" id="search_type">
                        <option value="title" <?php if ($search_type === 'title') echo 'selected'; ?>>スレタイ検索</option>
                        <option value="message" <?php if ($search_type === 'message') echo 'selected'; ?>>本文検索</option>
                        <option value="id" <?php if ($search_type === 'id') echo 'selected'; ?>>ID検索</option>
                        <option value="full" <?php if ($search_type === 'full') echo 'selected'; ?>>フル検索</option>
                    </select>
                </div>
                <div class="form-group">
                    <select name="year" id="year">
                        <option value="">年指定なし</option>
                        <?php 
                        $currentYear = date("Y");
                        for ($y = $currentYear; $y >= 2000; $y--): 
                        ?>
                            <option value="<?php echo $y; ?>" <?php if ($year == $y) echo 'selected'; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </form>
        <!-- 全掲示板情報を JSON 化して隠し要素で出力 -->
        <div id="allBoardsData" style="display:none;"><?php echo json_encode($allBoards, JSON_UNESCAPED_UNICODE); ?></div>
        
        <?php if ($search_query !== ''): ?>
            <div class="results">
                <h2>検索結果: <?php echo escape_html($total_results); ?> 件</h2>
                <?php if ($total_results > 0): ?>
                    <div class="sort-options">
                        <label for="sort_order">並び順:</label>
                        <form method="GET" action="search.php" id="sort_order_form">
                            <input type="hidden" name="search_query" value="<?php echo escape_html($search_query); ?>">
                            <input type="hidden" name="search_type" value="<?php echo escape_html($search_type); ?>">
                            <input type="hidden" name="category" value="<?php echo escape_html($category); ?>">
                            <input type="hidden" name="board" value="<?php echo escape_html($board); ?>">
                            <input type="hidden" name="year" value="<?php echo escape_html($year); ?>">
                            <input type="hidden" name="page" value="1">
                            <select name="sort_order" id="sort_order" onchange="submitSortForm()">
                                <option value="latest" <?php if ($sort_order === 'latest') echo 'selected'; ?>>最新順</option>
                                <option value="responses" <?php if ($sort_order === 'responses') echo 'selected'; ?>>レス数順</option>
                                <option value="oldest" <?php if ($sort_order === 'oldest') echo 'selected'; ?>>古い順</option>
                            </select>
                        </form>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination top">
                            <div class="page-info-container">
                                <span class="page-info"><?php echo escape_html($page . '/' . $total_pages); ?></span>
                            </div>
                            <div class="page-buttons">
                                <?php
                                    $base_query = $_GET;
                                    unset($base_query['page']);
                                    $adjacents = 2;
                                    if ($total_pages <= 7) {
                                        $start_page = 1; $end_page = $total_pages;
                                    } else {
                                        if ($page <= 4) { $start_page = 1; $end_page = 5; $show_start_ellipsis = false; $show_end_ellipsis = true; }
                                        elseif ($page > $total_pages - 4) { $start_page = $total_pages - 4; $end_page = $total_pages; $show_start_ellipsis = true; $show_end_ellipsis = false; }
                                        else { $start_page = $page - 2; $end_page = $page + 2; $show_start_ellipsis = true; $show_end_ellipsis = true; }
                                    }
                                    if ($start_page > 1):
                                        $base_query['page'] = 1;
                                ?>
                                        <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>">1</a>
                                <?php endif; ?>
                                <?php if (isset($show_start_ellipsis) && $show_start_ellipsis): ?>
                                    <span class="dots">...</span>
                                <?php endif; ?>
                                <?php
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                        $base_query['page'] = $i;
                                        if ($i == $page):
                                ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>"><?php echo $i; ?></a>
                                        <?php endif;
                                    endfor;
                                ?>
                                <?php if (isset($show_end_ellipsis) && $show_end_ellipsis): ?>
                                    <span class="dots">...</span>
                                <?php endif; ?>
                                <?php if ($end_page < $total_pages):
                                        $base_query['page'] = $total_pages;
                                ?>
                                    <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>"><?php echo $total_pages; ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <table>
                        <thead>
                            <tr>
                                <th>タイトル</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td>
                                        <a href="view.php/<?php echo urlencode($row['board_id']); ?>/<?php echo urlencode($row['thread_id']); ?>">
                                            <?php echo $row['title'] . "(" . $row['response_count'] . ")"; ?>
                                        </a>
                                        <div class="info">
                                           <span class="created_at"><?php echo $row['created_at']; ?></span>
                                           <span class="board-name"><?php echo $row['board_name']; ?></span> 
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="page-info-container">
                                <span class="page-info"><?php echo escape_html($page . '/' . $total_pages); ?></span>
                            </div>
                            <div class="page-buttons">
                                <?php
                                    $base_query = $_GET;
                                    unset($base_query['page']);
                                    $adjacents = 2;
                                    if ($total_pages <= 7) {
                                        $start_page = 1; $end_page = $total_pages;
                                    } else {
                                        if ($page <= 4) { $start_page = 1; $end_page = 5; $show_start_ellipsis = false; $show_end_ellipsis = true; }
                                        elseif ($page > $total_pages - 4) { $start_page = $total_pages - 4; $end_page = $total_pages; $show_start_ellipsis = true; $show_end_ellipsis = false; }
                                        else { $start_page = $page - 2; $end_page = $page + 2; $show_start_ellipsis = true; $show_end_ellipsis = true; }
                                    }
                                    if ($start_page > 1):
                                        $base_query['page'] = 1;
                                ?>
                                        <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>">1</a>
                                <?php endif; ?>
                                <?php if (isset($show_start_ellipsis) && $show_start_ellipsis): ?>
                                    <span class="dots">...</span>
                                <?php endif; ?>
                                <?php
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                        $base_query['page'] = $i;
                                        if ($i == $page):
                                ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>"><?php echo $i; ?></a>
                                        <?php endif;
                                    endfor;
                                ?>
                                <?php if (isset($show_end_ellipsis) && $show_end_ellipsis): ?>
                                    <span class="dots">...</span>
                                <?php endif; ?>
                                <?php if ($end_page < $total_pages):
                                        $base_query['page'] = $total_pages;
                                ?>
                                    <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>"><?php echo $total_pages; ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <p>該当する結果が見つかりませんでした。</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <!-- script.js を外部読み込み -->
    <script src="script.js"></script>
</body>
</html>
<?php
$content = ob_get_clean();
$shiftjis_content = utf8_to_sjis($content);
header('Content-Type: text/html; charset=Shift_JIS');
echo $shiftjis_content;
?>
