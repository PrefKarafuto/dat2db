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
 * ページネーション用のURLを構築する関数
 */
function buildPageUrl($page_number) {
    // 現在のクエリパラメータを取得
    $params = $_GET;
    $params['page'] = $page_number;

    // URLエンコードされたクエリ文字列を作成
    $query = http_build_query($params);

    return 'search.php?' . $query;
}

/**
 * 文字列をエスケープする関数（XSS対策）
 */
function escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Shift-JISからUTF-8に変換する関数
function convert_shiftjis_to_utf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'Shift_JIS');
}

// 変数の初期化と取得（Shift-JISからUTF-8に変換）
$search_type = isset($_GET['search_type']) ? convert_shiftjis_to_utf8($_GET['search_type']) : 'full';
$search_query = isset($_GET['search_query']) ? trim(convert_shiftjis_to_utf8($_GET['search_query'])) : '';
$category = isset($_GET['category']) ? convert_shiftjis_to_utf8($_GET['category']) : '';
$board = isset($_GET['board']) ? convert_shiftjis_to_utf8($_GET['board']) : '';
$sort_order = isset($_GET['sort_order']) ? convert_shiftjis_to_utf8($_GET['sort_order']) : 'latest';
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

    // 禁止文字のチェック（SQLインジェクション対策）
    if (preg_match('/[\'\";]|--/', $search_query)) {
        die("<p>検索クエリに不正な文字が含まれています。</p>");
    }

    // 基本となるSQLクエリの構築
    $sql = "SELECT Threads.board_id, Threads.thread_id, Threads.title, Threads.response_count, 
                   Boards.category_name, Boards.board_name,
                   MAX(Posts.date || ' ' || Posts.time) AS latest_post
            FROM Threads
            JOIN Posts ON Threads.board_id = Posts.board_id AND Threads.thread_id = Posts.thread_id
            JOIN Boards ON Threads.board_id = Boards.board_id";

    // 検索タイプに応じた条件追加
    $conditions = [];
    $params = [];

    if ($search_type === 'full') {
        $conditions[] = "(Posts.name LIKE :query OR Posts.mail LIKE :query OR Posts.id LIKE :query OR Posts.message LIKE :query)";
        $params[':query'] = '%' . $search_query . '%';
    } elseif ($search_type === 'title') {
        $conditions[] = "Threads.title LIKE :query";
        $params[':query'] = '%' . $search_query . '%';
    } elseif ($search_type === 'id') {
        // Posts.idが数値の場合は整数としてバインド
        if (ctype_digit($search_query)) {
            $conditions[] = "Posts.id = :query_exact";
            $params[':query_exact'] = intval($search_query);
        } else {
            // 数値でない場合は該当なし
            $conditions[] = "1=0"; // 常に偽
        }
    }

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
        $sql .= " ORDER BY latest_post DESC";
    } elseif ($sort_order === 'responses') {
        $sql .= " ORDER BY Threads.response_count DESC";
    } elseif ($sort_order === 'oldest') {
        $sql .= " ORDER BY latest_post ASC";
    }

    // 全体のカウントを取得
    $count_sql = "SELECT COUNT(*) as count FROM (
                    $sql
                  ) as subquery";
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $key => $value) {
        // Posts.idが整数の場合、適切にバインド
        if ($key === ':query_exact' && is_int($value)) {
            $count_stmt->bindValue($key, $value, SQLITE3_INTEGER);
        } else {
            $count_stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
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
        if ($key === ':query_exact' && is_int($value)) {
            $stmt->bindValue($key, $value, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    // クエリの実行
    $result = $stmt->execute();

    // 結果の取得
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
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
if (!empty($category)) {
    // カテゴリが選択されている場合、そのカテゴリ内の掲示板を取得
    $board_sql = "SELECT board_id, board_name FROM Boards WHERE category_name = :category ORDER BY board_name ASC";
    $board_stmt = $db->prepare($board_sql);
    $board_stmt->bindValue(':category', $category, SQLITE3_TEXT);
    $board_result = $board_stmt->execute();
    while ($row = $board_result->fetchArray(SQLITE3_ASSOC)) {
        $boards[] = $row;
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>掲示板検索</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* スタイルの調整 */
        body {
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: auto;
            background-color: #fff;
            padding: 20px 25px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            display: none; /* ラベルは非表示にしてプレースホルダーを使用 */
        }

        .form-group select, .form-group input[type="text"] {
            padding: 8px;
            width: 100%;
            max-width: 250px;
            box-sizing: border-box;
        }

        /* 検索ワード入力欄を大きく */
        #search_query {
            flex: 1 1 60%;
            max-width: none;
        }

        /* 検索ボタンを小さく */
        .search-button {
            padding: 8px 12px;
            background-color: #007BFF;
            border: none;
            color: #fff;
            cursor: pointer;
            border-radius: 4px;
            height: 40px;
            width: 100px;
        }

        .search-button:hover {
            background-color: #0056b3;
        }

        .results {
            margin-top: 30px;
        }

        .sort-options {
            margin-bottom: 15px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            align-items: center;
        }

        .sort-options label {
            font-weight: bold;
            margin-right: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th, table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        table th {
            background-color: #f8f8f8;
        }

        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        table tr:hover {
            background-color: #f1f1f1;
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
        }

        .pagination a, .pagination span {
            display: inline-block;
            margin: 0 5px;
            padding: 8px 12px;
            color: #007BFF;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .pagination a:hover {
            background-color: #e9e9e9;
        }

        .pagination .current {
            background-color: #007BFF;
            color: #fff;
            border-color: #007BFF;
        }
    </style>
    <script>
        // 並び順の選択が変更された際にフォームを送信する関数
        function submitSortForm() {
            document.getElementById('sort_order_form').submit();
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>掲示板検索</h1>
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
                    <select name="category" id="category">
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
                        <option value="full" <?php if ($search_type === 'full') echo 'selected'; ?>>全文検索</option>
                        <option value="title" <?php if ($search_type === 'title') echo 'selected'; ?>>スレッドタイトル検索</option>
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

                    <table>
                        <thead>
                            <tr>
                                <th>カテゴリ</th>
                                <th>掲示板</th>
                                <th>スレッドID</th>
                                <th>タイトル</th>
                                <th>レス数</th>
                                <th>最新投稿日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><?php echo escape_html($row['category_name']); ?></td>
                                    <td><?php echo $row['board_name']; ?></td>
                                    <td><?php echo escape_html($row['thread_id']); ?></td>
                                    <td>
                                        <a href="view.php/<?php echo urlencode($row['board_id']); ?>/<?php echo urlencode($row['thread_id']); ?>">
                                            <?php echo $row['title']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo escape_html($row['response_count']); ?></td>
                                    <td><?php echo escape_html($row['latest_post']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- ページネーション -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo escape_html(buildPageUrl($page - 1)); ?>">&laquo; 前へ</a>
                            <?php endif; ?>

                            <?php
                                // ページ番号の表示（例: 1 2 3 4 5）
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    if ($i == $page) {
                                        echo "<span class=\"current\">" . escape_html($i) . "</span>";
                                    } else {
                                        echo "<a href=\"" . escape_html(buildPageUrl($i)) . "\">" . escape_html($i) . "</a>";
                                    }
                                }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo escape_html(buildPageUrl($page + 1)); ?>">次へ &raquo;</a>
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
$shiftjis_content = mb_convert_encoding($content, 'Shift_JIS', 'UTF-8');

// ヘッダーをShift-JISに設定
header('Content-Type: text/html; charset=Shift_JIS');

// 出力
echo $shiftjis_content;
?>
