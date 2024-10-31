## dat2db
2ch/5chのdat形式のログからSQLite3のデータベース形式に変換するツール

## 使い方
php.iniでmbstring,sqlite3が有効になっていることを確認する。  
converter/datsフォルダに/[dirname]/xxx.datの形でデータを置く。dirnameは英数のみ。

### ローカル
dat2db.phpで、dat→dbを生成。  

### 掲示板名設定
bbsname.phpで、掲示版ディレクトリ名毎に掲示板名を設定（任意）  

### ブラウザ
converterフォルダ以外をサーバーにアップロード。  
dat.phpはdbからdat形式でデータ取得（専ブラ用）  
view.phpはスレッドの閲覧・検索  