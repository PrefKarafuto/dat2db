## dat2db
2ch/5chのdat形式のログからSQLite3のデータベース形式に変換するツール

## 使い方
php.iniでmbstring,sqlite3が有効になっていることを確認する。  
converter/datsフォルダに/[dirname]/xxx.datの形でデータを置く。dirnameは英数のみ。

### ローカル
先にboard_config.phpを編集して、掲示板ディレクトリ名とカテゴリ名、掲示板名を紐付ける。  
```php dat2db.php```で、dat→dbを生成。  

### ブラウザ
converterフォルダ以外をサーバーにアップロード。  
dat.phpはdbからdat形式でデータ取得（専ブラ用）  
view.phpはスレッドの閲覧・検索  