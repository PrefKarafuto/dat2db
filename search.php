<?php
// search.php

// 出力バッファリングを開始（全ての出力をバッファに保存）
ob_start();

// データベース接続の設定
$db = new SQLite3('bbs_log.db');

// エラーハンドリング
if (!$db) {
    die("データベースに接続できませんでした。");
}

// 関数の定義

/**
 * 文字列をエスケープする関数（XSS対策）
 */
function escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Shift-JISからUTF-8に変換する関数
 */
function sjis_to_utf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'SJIS');
}

/**
 * UTF-8からShift-JISに変換する関数
 */
function utf8_to_sjis($str) {
    return mb_convert_encoding($str, 'SJIS', 'UTF-8');
}

// 変数の初期化と取得（Shift-JISからUTF-8に変換）
$search_type = isset($_GET['search_type']) ? sjis_to_utf8($_GET['search_type']) : 'title';
$search_query = isset($_GET['search_query']) ? trim(sjis_to_utf8($_GET['search_query'])) : '';
$category = isset($_GET['category']) ? sjis_to_utf8($_GET['category']) : '';
$board = isset($_GET['board']) ? sjis_to_utf8($_GET['board']) : '';
$sort_order = isset($_GET['sort_order']) ? sjis_to_utf8($_GET['sort_order']) : 'latest';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// 検索範囲の決定
$scope = 'all'; // 初期値
if (!empty($board)) {
    $scope = 'board';
} elseif (!empty($category)) {
    $scope = 'category';
}

// フォームからの送信がない場合は空で表示
$results = [];
$total_results = 0;

// 検索が実行された場合
if ($search_query !== '') {
    // 検索クエリのバリデーション
    // 最大文字数を100文字に制限
    if (mb_strlen($search_query, 'UTF-8') > 100) {
        die("<p>検索クエリが長すぎます。100文字以内にしてください。</p>");
    }

    // 基本となるSQLクエリの構築
    $sql = "SELECT Threads.board_id, Threads.thread_id, Threads.title, Threads.response_count, 
                   Boards.category_name, Boards.board_name
            FROM Threads
            JOIN Posts ON Threads.board_id = Posts.board_id AND Threads.thread_id = Posts.thread_id
            JOIN Boards ON Threads.board_id = Boards.board_id";

    // 検索タイプに応じた条件追加
    $conditions = [];
    $params = [];

    if ($search_type === 'full') {
        $conditions[] = "(Posts.name LIKE :query OR Posts.mail LIKE :query OR Posts.id LIKE :query OR Posts.message LIKE :query)";
    } elseif ($search_type === 'message') {
        $conditions[] = "Posts.message LIKE :query";
    } elseif ($search_type === 'title') {
        $conditions[] = "Threads.title LIKE :query";
    } elseif ($search_type === 'id') {
        $conditions[] = "Posts.id LIKE :query";
    }    
    $params[':query'] = '%' . $search_query . '%';

    // スコープに応じた条件追加
    if ($scope === 'board') {
        $conditions[] = "Threads.board_id = :board";
        $params[':board'] = $board;
    } elseif ($scope === 'category') {
        $conditions[] = "Boards.category_name = :category";
        $params[':category'] = $category;
    }

    // WHERE句の追加
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    // GROUP BY
    $sql .= " GROUP BY Threads.board_id, Threads.thread_id";

    // ソート順の指定
    if ($sort_order === 'latest') {
        $sql .= " ORDER BY Threads.thread_id DESC"; // thread_idを最新順に（Unixタイムスタンプ）
    } elseif ($sort_order === 'responses') {
        $sql .= " ORDER BY Threads.response_count DESC";
    } elseif ($sort_order === 'oldest') {
        $sql .= " ORDER BY Threads.thread_id ASC"; // thread_idを古い順に
    }

    // 全体のカウントを取得
    $count_sql = "SELECT COUNT(*) as count FROM (
                    $sql
                  ) as subquery";
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
            $count_stmt->bindValue($key, $value, SQLITE3_TEXT);
    }
    $count_result = $count_stmt->execute();
    if ($count_row = $count_result->fetchArray(SQLITE3_ASSOC)) {
        $total_results = $count_row['count'];
    }

    // ページネーションのためにLIMITとOFFSETを追加
    $sql .= " LIMIT :limit OFFSET :offset";

    // クエリの準備
    $stmt = $db->prepare($sql);

    // パラメータのバインド
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, SQLITE3_TEXT);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    // クエリの実行
    $result = $stmt->execute();
    if (!$result) {
        die("<p>検索クエリの実行に失敗しました。</p>");
    }

    // 結果の取得（created_atを追加）
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // thread_idがUnixタイムスタンプであることを考慮して、適切に変換
        $timestamp = intval($row['thread_id']);
        $row['created_at'] = date("Y-m-d H:i:s", $timestamp);
        $results[] = $row;
    }
}

// カテゴリと掲示板の取得
$categories = [];
$category_sql = "SELECT DISTINCT category_name FROM Boards ORDER BY category_name ASC";
$category_result = $db->query($category_sql);
while ($row = $category_result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row['category_name'];
}

$boards = [];
if ($scope === 'category') {
    if (!empty($category)) {
        // カテゴリが選択されている場合、そのカテゴリ内の掲示板を取得
        $board_sql = "SELECT board_id, board_name FROM Boards WHERE category_name = :category ORDER BY board_name ASC";
        $board_stmt = $db->prepare($board_sql);
        $board_stmt->bindValue(':category', $category, SQLITE3_TEXT);
        $board_result = $board_stmt->execute();
        while ($row = $board_result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $row;
        }
    }
} else {
    // カテゴリが選択されていない場合、全ての掲示板を取得
    $board_sql = "SELECT board_id, board_name FROM Boards ORDER BY board_name ASC";
    $board_result = $db->query($board_sql);
    while ($row = $board_result->fetchArray(SQLITE3_ASSOC)) {
        $boards[] = $row;
    }
}

// 総ページ数の計算
$total_pages = ceil($total_results / $limit);

// 結果のグループ化（必要なければ削除可能）
$grouped_results = [
    'category' => [],
    'board' => []
];

foreach ($results as $row) {
    // グループ化（必要に応じて活用）
    // カテゴリごとにグループ化
    if (!isset($grouped_results['category'][$row['category_name']])) {
        $grouped_results['category'][$row['category_name']] = [];
    }
    $grouped_results['category'][$row['category_name']][] = [
        'title' => $row['title'],
        'response_count' => $row['response_count'],
        'created_at' => $row['created_at'],
        'board_id' => $row['board_id'],
        'thread_id' => $row['thread_id'],
        'board_name' => $row['board_name']
    ];

    // 掲示板ごとにグループ化
    if (!isset($grouped_results['board'][$row['board_name']])) {
        $grouped_results['board'][$row['board_name']] = [];
    }
    $grouped_results['board'][$row['board_name']][] = [
        'title' => $row['title'],
        'response_count' => $row['response_count'],
        'created_at' => $row['created_at'],
        'board_id' => $row['board_id'],
        'thread_id' => $row['thread_id'],
        'category_name' => $row['category_name']
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="Shift_JIS">
    <title>データベース検索</title>
    <link rel="stylesheet" href="styles.css">
    <script>
        // 並び順の選択が変更された際にフォームを送信する関数
        function submitSortForm() {
            document.getElementById('sort_order_form').submit();
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>データベース検索</h1>
        <p><a href="view.php">掲示板一覧</a></p>
        <form method="GET" action="search.php" class="search-form">
            <!-- 1行目: 検索ワードと検索ボタン -->
            <div class="form-row">
                <div class="form-group" style="flex: 1 1 60%;">
                    <input type="text" name="search_query" id="search_query" value="<?php echo escape_html($search_query); ?>" placeholder="検索ワード" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="search-button">検索</button>
                </div>
            </div>

            <!-- 2行目: カテゴリ、掲示板名、検索タイプ -->
            <div class="form-row">
                <div class="form-group" style="flex: 1 1 30%;">
                    <select name="category" id="category" onchange="this.form.submit()">
                        <option value="">カテゴリを選択</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo escape_html($cat); ?>" <?php if ($category === $cat) echo 'selected'; ?>>
                                <?php echo escape_html($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1 1 30%;">
                    <select name="board" id="board">
                        <option value="">掲示板を選択</option>
                        <?php foreach ($boards as $b): ?>
                            <option value="<?php echo escape_html($b['board_id']); ?>" <?php if ($board === $b['board_id']) echo 'selected'; ?>>
                                <?php echo $b['board_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1 1 30%;">
                    <select name="search_type" id="search_type">
                        <option value="title" <?php if ($search_type === 'title') echo 'selected'; ?>>スレタイ検索</option>
                        <option value="message" <?php if ($search_type === 'message') echo 'selected'; ?>>本文検索</option>
                        <option value="id" <?php if ($search_type === 'id') echo 'selected'; ?>>ID検索</option>
                    </select>
                </div>
            </div>
        </form>

        <?php if ($search_query !== ''): ?>
            <div class="results">
                <h2>検索結果: <?php echo escape_html($total_results); ?> 件</h2>
                <?php if ($total_results > 0): ?>
                    <!-- 並び順の選択 -->
                    <div class="sort-options">
                        <label for="sort_order">並び順:</label>
                        <form method="GET" action="search.php" id="sort_order_form">
                            <!-- 必要なパラメータを保持 -->
                            <input type="hidden" name="search_query" value="<?php echo escape_html($search_query); ?>">
                            <input type="hidden" name="search_type" value="<?php echo escape_html($search_type); ?>">
                            <input type="hidden" name="category" value="<?php echo escape_html($category); ?>">
                            <input type="hidden" name="board" value="<?php echo escape_html($board); ?>">
                            <input type="hidden" name="page" value="1"> <!-- 並び替え時は1ページ目にリセット -->
                            <select name="sort_order" id="sort_order" onchange="submitSortForm()">
                                <option value="latest" <?php if ($sort_order === 'latest') echo 'selected'; ?>>最新順</option>
                                <option value="responses" <?php if ($sort_order === 'responses') echo 'selected'; ?>>レス数順</option>
                                <option value="oldest" <?php if ($sort_order === 'oldest') echo 'selected'; ?>>古い順</option>
                            </select>
                        </form>
                    </div>

                    <!-- タブインターフェースを廃止 -->

                    <!-- 結果の表示 -->
                    <table>
                        <thead>
                            <tr>
                                <th>タイトル</th>
                                <th>レス数</th>
                                <th>スレッド作成日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td>
                                        <a href="view.php/<?php echo urlencode($row['board_id']); ?>/<?php echo urlencode($row['thread_id']); ?>">
                                            <?php echo $row['title']; ?>
                                        </a>
                                        <div class="board-name"><?php echo $row['board_name']; ?></div>
                                    </td>
                                    <td><?php echo escape_html($row['response_count']); ?></td>
                                    <td><?php echo escape_html($row['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- ページネーション -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                                // 既存のページネーションリンクに現在の検索条件を保持
                                $base_query = $_GET;
                                unset($base_query['page']);

                                if ($page > 1):
                                    $prev_page = $page - 1;
                                    $base_query['page'] = $prev_page;
                            ?>
                                    <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>">&laquo; 前へ</a>
                            <?php endif; ?>

                            <?php
                                // ページ番号の表示（例: 1 2 3 4 5）
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++):
                                    $base_query['page'] = $i;
                                    if ($i == $page):
                            ?>
                                        <span class="current"><?php echo escape_html($i); ?></span>
                                    <?php else: ?>
                                        <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>"><?php echo escape_html($i); ?></a>
                                    <?php endif;
                                endfor;
                            ?>

                            <?php
                                if ($page < $total_pages):
                                    $next_page = $page + 1;
                                    $base_query['page'] = $next_page;
                            ?>
                                    <a href="search.php?<?php echo escape_html(http_build_query($base_query)); ?>">次へ &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <p>該当する結果が見つかりませんでした。</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
// 出力バッファの内容を取得
$content = ob_get_clean();

// 文字列をShift-JISに変換
$shiftjis_content = utf8_to_sjis($content);

// ヘッダーをShift-JISに設定
header('Content-Type: text/html; charset=Shift_JIS');

// 出力
echo $shiftjis_content;
?>
