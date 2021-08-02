# Scraping with Laravel
### 解説
Laravelのコマンドラインでスクレイピングを実行する  
現在の対応スクレイピングターゲットはAliExpressのみ  
store_idにAliExpressのストアIDを指定するとそのストアの全商品の情報を取得する  

### コマンド
`$> ./vendor/bin/sail php artisan scrape:exec --store_id=XXXXX`

### 出力ファイル
BASEショップのCSV一括登録機能向けのCSVファイルと画像のZIPファイルを生成する  
BASEでは1日に1,000件までしか登録が出来ない為、  
1,000件を超える場合はナンバリングされたファイルに分割される
