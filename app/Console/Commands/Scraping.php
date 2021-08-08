<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use ZipArchive;

class Scraping extends Command
{
    // ==============================
    // 設定項目
    // ==============================

    // BASE 決済手数料率(%)
    const PLATFORM_FEE_PER = 3.6;

    // 国内送料(円)
    const POSTAGE_PRICE = 700;

    // 利益をいくら上乗せするか(円)
    const PROFIT_PRICE = 600;

    // 1ドルあたりの為替調整(円)
    const CURRENCY_AJUSTMENT = 3.6;


    // 定数
    const HOST = 'https://ja.aliexpress.com';
    const SEARCH_URL = '/store/sale-items/%s/%d.html'; // 1:StoreId, 2:PageNo
    const ITEM_URL = '/item/%s.html'; // 1:ItemId

    const BASE_MAX_REGIST = 1000; // (件) 1日にBASEに商品登録出来る上限数
    const SLEEP_TIME = 3; // (秒) アイテム詳細スクレイピング間隔（短くしすぎると不正アクセスになります）

    const CSV_FILE_PATH = 'app/base/%s_%s_%03d.csv'; // 1:StoreId, 2:ItemId, 3:Sequence
    const ZIP_FILE_PATH = 'app/base/%s_%s_%03d.zip'; // 1:StoreId, 2:ItemId, 3:Sequence
    const TEMP_IMAGE_PATH = 'app/temp_image/%s'; // 1:FileName


    private $currency = 0;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:exec {--store_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape executor';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // 指定されたストアのデータ初期化
        $this->deleteStoreItem();
        $this->deleteStoreItemDetail();

        // ドルから円に戻すために現在の為替相場を取得
        $this->getCurrencyApi();

        // ストアの商品一覧を取得
        $this->getStoreItems();

        // 商品の詳細情報を取得
        $this->getItemDetails();
        
        // BASEの一括登録用CSVと画像Zipファイルを作成
        $this->exportCsvAndZipImages();

        return 0;
    }

    private function deleteStoreItem()
    {
        DB::table('ali_store_items')->where('store_id', $this->option("store_id"))->delete();
    }

    private function deleteStoreItemDetail()
    {
        DB::table('ali_item_details')->where('store_id', $this->option("store_id"))->delete();
    }

    private function getStoreItems()
    {
        $storeId = $this->option("store_id");
        if ($storeId == "")
        {
            echo "ArgumentException: AliExpress の StoreId を --store_id= で指定してください。\n";
            return -1;
        }

        $page = 1;
        $url = $this::HOST . sprintf($this::SEARCH_URL, $storeId, $page);

        echo sprintf("Searching... | StoreId = %s, Page = %d | URL = %s\n", $storeId, $page, $url);

        $crawler = \Goutte::request('GET', $url);
        $maxPage = $this->getMaxPageNo($crawler);

        for ($i = $page; $i <= $maxPage; $i++)
        {
            if ($i > 1)
            {
                $url = $this::HOST . sprintf($this::SEARCH_URL, $storeId, $i);
                $crawler = \Goutte::request('GET', $url);
            }

            $crawler->filter('.m-o-large-all-detail .ui-box .ui-box-body .items-list .item')->each
            (function ($node) {
                $itemId = $this->getItemId($node);

                if (!DB::table('ali_store_items')->where('item_id', $itemId)->exists())
                {
                    echo "Insert to ali_store_items table. StoreId = {$this->option("store_id")}, ItemId = {$itemId}\n";
                    DB::table('ali_store_items')->insert([
                        'store_id' => $this->option("store_id"),
                        'item_id' => $itemId,
                        'url' => $this::HOST . sprintf($this::ITEM_URL, $itemId),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
            });

            sleep($this::SLEEP_TIME);
        }
    }

    private function getItemDetails()
    {
        $storeItems = DB::table('ali_store_items')->where('store_id', $this->option("store_id"))->get();
        foreach ($storeItems as $item)
        {
            echo $item->url . "\n";
            $crawler = \Goutte::request('GET', $item->url);
            $html = $crawler->html();

            $store_name = $this->getJsonText($html, "storeName");
            $item_name = $this->getJsonText($html, "subject");
            $price = $this->getCurrency($html, "actSkuCalPrice");
            $org_price = $this->getCurrency($html, "skuCalPrice");
            $discount_price = $org_price - $price;
            $discount_per = $this->getJsonText($html, "discount", true);
            $colors = $this->getPropertyList($html, 1);
            $sizes = $this->getPropertyList($html, 2);
            $review_point = $this->getJsonText($html, "averageStar");
            $review_count = $this->getJsonText($html, "totalValidNum", true);
            $sales = $this->getJsonText($html, "tradeCount", true);
            $images = $this->getImagePaths($html, "imagePathList");

            echo "Insert to ali_item_details table. StoreId = {$this->option("store_id")}, ItemId = {$item->item_id}\n";
            DB::table('ali_item_details')->insert([
                'store_id' => $this->option("store_id"),
                'store_name' => $store_name,
                'item_id' => $item->item_id,
                'item_name' => $item_name,
                'price' => $price,
                'org_price' => $org_price,
                'discount_price' => $discount_price,
                'discount_per' => intval($discount_per),
                'colors' => $colors,
                'sizes' => $sizes,
                'review_point' => $review_point,
                'review_count' => $review_count,
                'sales' => $sales,
                'images' => $images,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            sleep($this::SLEEP_TIME);
        }
    }

    private function getMaxPageNo($crawler)
    {
        $result = $crawler->filter('.ui-pagination-navi')->text();
        $pages = explode(" ", $result);

        $maxPage = 1;
        foreach($pages as $page)
        {
            if ($page == "" || $page == "Previous")
            {
                continue;
            }

            if ($page == "Next")
            {
                break;
            }

            $maxPage = $page;
        }

        return $maxPage;
    }

    private function getItemId($node)
    {
        return $node->filter('.info > .atc-product-id')->attr("value");
    }

    private function getJsonText($html, $target, $isNumber = false)
    {
        $jsonTargetText = '"' . $target . '":' . ($isNumber ? '' : '"');
        if (strpos($html, $jsonTargetText) == false)
        {
            return $isNumber == true ? 0 : "";
        }

        $jsonStartPos = strpos($html, $jsonTargetText) + strlen($jsonTargetText);
        $cutHtml = substr($html, $jsonStartPos);
        $targetLength = strpos($cutHtml, ($isNumber ? ',' : '",'));
        $text = substr($cutHtml, 0, $targetLength);
        return html_entity_decode($text);
    }

    private function getCurrencyApi()
    {
        $api = 'http://api.aoikujira.com/kawase/json/usd';
        $this->currency = json_decode(file_get_contents($api), true);
    }

    private function getCurrency($html, $target)
    {
        $text = $this->getJsonText($html, $target);
        return $this->convertCurrency($text);
    }

    private function convertCurrency($amount)
    {
        if (!isset($this->currency["result"]) || $this->currency["result"] != "ok")
        {
            return $amount * 110;
        }

        $amount = (float)$amount;
        $jpy = (float)$this->currency['JPY'];
        $jpy = $jpy + $this::CURRENCY_AJUSTMENT;
        return round($amount * $jpy);
    }

    private function getPropertyList($html, $hierarchy)
    {
        $target = '"skuPropertyValues":';
        $offset = 0;
        $jsonStartPos = -1;

        while ($hierarchy > 0)
        {
            $jsonStartPos = strpos($html, $target, $offset);
            $offset = $jsonStartPos;
            $hierarchy--;

            if ($jsonStartPos > 0 && $hierarchy <= 0)
            {
                $jsonStartPos = $jsonStartPos + strlen($target);
                return $this->getList($html, $jsonStartPos);
            }
        }
        return "";
    }

    private function getList($html, $jsonStartPos)
    {
        $cutHtml = substr($html, $jsonStartPos);
        $targetLength = strpos($cutHtml, '}]}') + 2;
        $json = substr($cutHtml, 0, $targetLength);

        $result = array();
        $list = json_decode($json);
        foreach ($list as $row)
        {
            $result[] = html_entity_decode(strtoupper($row->propertyValueDisplayName));
        }

        return implode(",", $result);
    }

    private function getImagePaths($html, $target)
    {
        $jsonTargetText = '"' . $target . '":[';
        $jsonStartPos = strpos($html, $jsonTargetText) + strlen($jsonTargetText);
        $cutHtml = substr($html, $jsonStartPos);
        $targetLength = strpos($cutHtml, ']');
        $text = substr($cutHtml, 0, $targetLength);
        return str_replace('"', '', $text);
    }

    private function exportCsvAndZipImages()
    {
        $rows = [];
        $fileNo = 1;
        $rowCount = 0;

        $items = DB::table('ali_item_details')->where('store_id', $this->option("store_id"))->get();
        $itemCount = count($items);
        foreach ($items as $index => $item)
        {
            $colors = explode(",", $item->colors);
            $sizes = explode(",", $item->sizes);
            if (count($sizes) == 0)
            {
                $sizes = [""];
            }

            if ($rowCount + (count($colors) * count($sizes)) > $this::BASE_MAX_REGIST)
            {
                $this->writeFile($fileNo, $rows);
                $this->writeZipFile($fileNo);

                $rows = [];
                $fileNo++;
                $rowCount = 0;
            }

            $no = 1;
            foreach ($colors as $color)
            {
                foreach ($sizes as $size)
                {
                    $rows[] = $this->generateRow($item, $color, $size, $no);
                    $rowCount++;
                    $no++;
                }
            }

            echo "Processing... ItemId = " . $item->item_id . " (" . ($index + 1) . "/" . $itemCount . ")\n";
            $this->downloadImages($item);
        }

        if (count($rows) > 0)
        {
            $this->writeFile($fileNo, $rows);
            $this->writeZipFile($fileNo);
        }
    }

    private function writeFile($fileNo, $rows)
    {
        $file_path = sprintf($this::CSV_FILE_PATH, date("Ymd"), $this->option('store_id'), $fileNo);
        $file = fopen(storage_path($file_path), 'w');
        if (!$file)
        {
            throw new \Exception('ファイルの作成に失敗しました。');
        }

        $csv_header = ['商品ID','商品名','種類ID','種類名','説明','価格','税率','在庫数','公開状態','表示順','種類在庫数',
                       '画像1','画像2','画像3','画像4','画像5','画像6','画像7','画像8','画像9','画像10',
                       '画像11','画像12','画像13','画像14','画像15','画像16','画像17','画像18','画像19','画像20',
                       '商品コード','種類コード','JAN/GTIN'];
        if (!fputcsv($file, $csv_header))
        {
            throw new \Exception('ヘッダの書き込みに失敗しました。');
        }

        foreach ($rows as $row)
        {
            if (!fputcsv($file, $row))
            {
                throw new \Exception('データの書き込みに失敗しました。');
            }
        }

        fclose($file);
    }

    private function generateRow($item, $color, $size, $no)
    {
        $salePrice = intval($item->price * $this::PLATFORM_FEE_PER / 100) + $this::POSTAGE_PRICE + $this::PROFIT_PRICE;

        $data = [
            '',                                                         // 商品ID
            $item->item_name,                                           // 商品名
            '',                                                         // 種類ID
            strtoupper($color) . " " . strtoupper($size),               // 種類名
            $this->getItemDescription($item, $color, $size),            // 説明 (商品ページURLとかレビュー情報とか販売数を入れてある)
            $salePrice,                                                 // 価格（送料とか利益とか手数料とか全部計算する）
            1,                                                          // 税率
            count(explode(",", $item->sizes)) * 100,                    // 在庫数
            0,                                                          // 公開状態
            1,                                                          // 表示順
            100,                                                        // 種類在庫数
        ];

        $images = explode(",", $item->images);

        for ($i = 0; $i < 20; $i++)
        {
            if (isset($images[$i]))
            {
                $extension = "." . pathinfo($images[$i])["extension"];
                $data[] = $item->item_id . "_" . ($i + 1) . $extension;
            }
            else
            {
                $data[] = "";
            }
        }

        $data[] = $item->item_id;                                   // 商品コード（アイテムID）
        $data[] = $item->item_id . "_" . $no;                       // 種類コード（アイテムID＋連番）
        $data[] = $this->option("store_id") . $item->item_id . $no; // JAN/GTIN（ストアID＋カラー＋サイズ）

        return $data;
    }

    private function getItemDescription($item, $color, $size)
    {
        $description = $this::HOST . sprintf($this::ITEM_URL, $item->item_id) . "\n\n";
        $description = $description . "レビュー: " . $item->review_point . "\n";
        $description = $description . "レビュー数: " . $item->review_count . "\n";
        $description = $description . "販売数: " . $item->sales . "\n";
        return $description;
    }

    private function downloadImages($item)
    {
        $no = 1;
        foreach (explode(",", $item->images) as $url)
        {
            $extension = "." . pathinfo($url)["extension"];
            $temp_image_path = storage_path(sprintf($this::TEMP_IMAGE_PATH, $item->item_id . "_" . $no . $extension));
            //echo "Download image file. PATH = " . sprintf($this::TEMP_IMAGE_PATH, $item->item_id . "_" . $no . $extension) . "\n";
            $no++;

            if (\File::exists($temp_image_path))
            {
                continue;
            }

            for ($i = 0; $i < 3; $i++)
            {
                try
                {
                    $data = file_get_contents($url);
                    if (!file_put_contents($temp_image_path, $data))
                    {
                        throw new \Exception("画像ファイルの保存に失敗しました。");
                    }
                    unset($data);
                    break;
                }
                catch (\Exception $e)
                {
                    echo "Failed download image. RetryCount = " . ($i + 1) . ", URL = " . $url;
                    sleep($i + 1);
                }
            }
        }
    }

    private function writeZipFile($fileNo)
    {
        $local_temp_files = [];

        $files = \File::files(storage_path(sprintf($this::TEMP_IMAGE_PATH, "")));
        foreach ($files as $file)
        {
            $local_temp_files[] = $file->getpathName();
        }

        $this->zipFiles($fileNo, $local_temp_files);

        foreach ($files as $file)
        {
            unlink($file);
        }
    }

    private function zipFiles($fileNo, $local_file_paths)
    {
        $zip = new ZipArchive;

        $zip_file_path =  storage_path(sprintf($this::ZIP_FILE_PATH, date("Ymd"), $this->option("store_id"), $fileNo));
        $result = $zip->open($zip_file_path, ZipArchive::CREATE);
        if(!$result)
        {
            throw new \Exception("Zipファイルの作成に失敗しました。");
        }

        foreach ($local_file_paths as $image)
        {
            $zip->addFile($image, basename($image));
        }
        
        $zip->close();
    }
}
