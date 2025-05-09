<?php
// 各カテゴリのIDと名前、及びそのカテゴリに属する掲示板のIDと掲示板名を関連付けます。
// フォルダ構成は、
//   categories/[category_id]/[board_id]
// となります。
//
// 例:
// return [
//     'ent' => [                     // カテゴリID（ディレクトリ名）
//         'name' => 'エンタメ',        // カテゴリ名（表示名）
//         'boards' => [              // このカテゴリに属する掲示板
//             'movies' => '映画掲示板', // 掲示板ID => 掲示板名
//             'music'  => '音楽掲示板',
//         ],
//     ],
// ];
return [
    // 以下に各カテゴリの設定を記述してください。
    // 'カテゴリID' => [
//         'name' => 'カテゴリ名',
//         'boards' => [
//              '掲示板ID' => '掲示板名',
//         ],
//     ],
'general' => [
         'name' => '一般',
         'boards' => [
              'cross' => 'クヒケー',
              'evil' => 'エビケー',
              'sayedandsayed' => 'スバケー',
              'zhongguo' => 'チャンケー',
         ],
     ],
];
