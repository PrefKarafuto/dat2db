<?php
/**
 * generate_sitemap.php
 * 
 * Usage:
 *   php generate_sitemap.php https://example.com
 *
 * このスクリプトは、SQLite3 の bbs_log.db を元に、
 * サイト内の URL（ホームページ、掲示板ページ、各スレッドページ）を収集し、
 * XML サイトマップを生成します。
 * URL 数が 50,000 を超える場合は、複数のサイトマップファイルに分割し、
 * sitemap_index.xml で管理します。
 */

// エラーレポート（デバッグ時は display_errors を 1 に変更）
error_reporting(E_ALL);
ini_set('display_errors', '1');

// コマンドライン引数チェック
if ($argc < 2) {
    echo "Usage: php generate_sitemap.php [BASE_URL]\n";
    exit(1);
}

$baseUrl = rtrim($argv[1], '/') . "/view.php";  // 例: https://example.com

// サイトマップの最大 URL 件数（50,000）
define('MAX_URLS', 50000);

// データベース接続（SQLite3）
$dbFile = '../bbs_log.db';
if (!file_exists($dbFile)) {
    echo "Error: データベースファイルが存在しません。\n";
    exit(1);
}
$db = new SQLite3($dbFile);

// 現在時刻
$lastmodTimestamp = time();
$lastmodString = date("Y-m-d\TH:i:sP", $lastmodTimestamp);

// URL を格納する配列
$urls = [];

// 1. ホームページURL（必要に応じて追加）
$urls[] = [
    'loc' => $baseUrl . '/',
    'lastmod' => $lastmodString,
    'changefreq' => 'daily',
    'priority' => '1.0'
];

// 2. Boards テーブルから掲示板URLを追加
$boardsResult = $db->query("SELECT board_id FROM Boards ORDER BY board_id ASC");
while ($row = $boardsResult->fetchArray(SQLITE3_ASSOC)) {
    $boardId = $row['board_id'];
    $urls[] = [
        'loc' => $baseUrl . '/' . $boardId . '/',
        'lastmod' => $lastmodString,
        'changefreq' => 'daily',
        'priority' => '0.8'
    ];
}

// 3. Threads テーブルから各スレッドのURLを追加
// ※スレッド URL は、例として "baseUrl/board_id/thread_id/" 形式とする
$threadsResult = $db->query("SELECT board_id, thread_id FROM Threads ORDER BY board_id, thread_id ASC");
while ($row = $threadsResult->fetchArray(SQLITE3_ASSOC)) {
    $boardId = $row['board_id'];
    $threadId = $row['thread_id'];
    $urls[] = [
        'loc' => $baseUrl . '/' . $boardId . '/' . $threadId . '/',
        'lastmod' => $lastmodString,
        'changefreq' => 'weekly',
        'priority' => '0.5'
    ];
}

// 総 URL 件数
$totalUrls = count($urls);
echo "Total URLs: $totalUrls\n";

// 分割数の計算
$numSitemaps = ceil($totalUrls / MAX_URLS);

// サイトマップファイル群を出力するディレクトリ（同一ディレクトリに出力）
$sitemapFiles = [];

// 各サイトマップファイルを生成
for ($i = 0; $i < $numSitemaps; $i++) {
    $chunk = array_slice($urls, $i * MAX_URLS, MAX_URLS);
    $sitemapContent = generateSitemapXML($chunk);
    $sitemapFilename = "sitemap_" . ($i + 1) . ".xml";
    file_put_contents($sitemapFilename, $sitemapContent);
    echo "生成: $sitemapFilename (" . count($chunk) . " URLs)\n";
    $sitemapFiles[] = $sitemapFilename;
}

// サイトマップインデックスファイルを生成
if ($numSitemaps > 1) {
    $sitemapIndexContent = generateSitemapIndexXML($sitemapFiles, $baseUrl, $lastmodString);
    file_put_contents("sitemap_index.xml", $sitemapIndexContent);
    echo "生成: sitemap_index.xml\n";
}

// DB 切断
$db->close();
exit(0);


/**
 * generateSitemapXML
 * 与えられた URL 配列から XML サイトマップ文書を生成する。
 */
function generateSitemapXML($urlArray) {
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('urlset');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    foreach ($urlArray as $urlInfo) {
        $xml->startElement('url');
        $xml->writeElement('loc', $urlInfo['loc']);
        $xml->writeElement('lastmod', $urlInfo['lastmod']);
        $xml->writeElement('changefreq', $urlInfo['changefreq']);
        $xml->writeElement('priority', $urlInfo['priority']);
        $xml->endElement(); // url
    }

    $xml->endElement(); // urlset
    $xml->endDocument();
    return $xml->outputMemory();
}

/**
 * generateSitemapIndexXML
 * 与えられたサイトマップファイル名配列からサイトマップインデックス文書を生成する。
 */
function generateSitemapIndexXML($sitemapFiles, $baseUrl, $lastmod) {
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);
    $xml->startElement('sitemapindex');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    // 各サイトマップファイルについて
    foreach ($sitemapFiles as $filename) {
        // 絶対URLを生成。ここでは、ベース URL の直下にサイトマップファイルがあると仮定
        $sitemapUrl = $baseUrl . '/' . $filename;
        $xml->startElement('sitemap');
        $xml->writeElement('loc', $sitemapUrl);
        $xml->writeElement('lastmod', $lastmod);
        $xml->endElement(); // sitemap
    }

    $xml->endElement(); // sitemapindex
    $xml->endDocument();
    return $xml->outputMemory();
}
