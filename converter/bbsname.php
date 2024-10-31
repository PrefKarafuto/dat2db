<?php
// データベースファイルの確認
$db_file = '../bbs_log.db';
if (!file_exists($db_file)) {
    echo "エラー: データベースファイル '{$db_file}' が存在しません。\n";
    exit;
}

// データベース接続
$db = new SQLite3($db_file);
if (!$db) {
    echo "エラー: データベースに接続できません。\n";
    exit;
}

// 全ての board_id を取得
$result = $db->query("SELECT board_id FROM Boards");
$board_ids = [];

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $board_ids[] = $row['board_id'];
}

// board_id が存在しない場合は終了
if (empty($board_ids)) {
    echo "エラー: データベースに board_id が登録されていません。\n";
    $db->close();
    exit;
}

// 既存の board_name を取得
$existing_board_names = [];
$result = $db->query("SELECT board_name FROM Boards WHERE board_name IS NOT NULL");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $existing_board_names[] = $row['board_name'];
}

// 各 board_id に対して board_name を設定
foreach ($board_ids as $board_id) {
    while (true) {
        echo "board_id '{$board_id}' の board_name を入力してください：";
        $board_name = trim(fgets(STDIN));
        
        // board_nameが空でないかチェック
        if (empty($board_name)) {
            echo "board_name が空です。再度入力してください。\n";
            continue;
        }

        // board_name の重複チェック
        if (in_array($board_name, $existing_board_names)) {
            echo "board_name '{$board_name}' は既に使用されています。別の名前を入力してください。\n";
            continue;
        }

        // バリデーションを通過した場合、既存のリストに追加
        $existing_board_names[] = $board_name;
        break;  // 入力が有効であればループを抜ける
    }

    // データベースを更新（board_id が既に存在する場合は更新）
    $stmt = $db->prepare("UPDATE Boards SET board_name = :board_name WHERE board_id = :board_id");
    $stmt->bindValue(':board_id', $board_id, SQLITE3_TEXT);
    $stmt->bindValue(':board_name', $board_name, SQLITE3_TEXT);

    if ($stmt->execute()) {
        echo "board_id '{$board_id}' に対して board_name '{$board_name}' を設定しました。\n";
    } else {
        echo "エラー: board_id '{$board_id}' の board_name を設定できませんでした。\n";
    }
}

$db->close();
echo "全ての board_id に対する設定が完了しました。\n";
?>
