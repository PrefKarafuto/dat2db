## dat2db
2ch/5chのdat形式のログからSQLite3のデータベース形式に変換するツール

## 前準備
converter/categoriesフォルダに/[category_id]/[board_id]/xxx.datの形でデータを置く。category_id,board_idは英数のみ。
MeCabが必要なのでインストール・ビルドしておく。php_mecabを利用する。インストール方法はhttps://makeis.dev/archives/269 等を参照。  
辞書ファイルはmecab-ipadic-NEologdを推奨。インストール方法はhttps://www.wantedly.com/companies/rakus/post_articles/139910 等を参照。   

### 使い方
1 : board_config.phpをエディタで開き、カテゴリ名と掲示板名を設定  
2 : dat2db.phpをコマンドラインで実行し、dat→dbを生成  
3 : generate_sitemap.php [view.phpが置かれているURL] をコマンドラインで実行し、XMLサイトマップを生成（サイトに設置する場合はrobot.txtにsitemap.xmlのパスを記述しておくこと。）  

### ブラウザ
converterフォルダは不要。  
view.phpはスレッドの閲覧用（専ブラ可）  
search.phpはDBの検索