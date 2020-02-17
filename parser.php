<?php

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Promise;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DomCrawler\Crawler;
use function Amp\call;
use function Amp\Promise\any;
use function Amp\Promise\wait;

require_once __DIR__ . "/vendor/autoload.php";

const SELECTOR_PRICING_SPANS = '#priceBox > div.pricing p.price:not(.enter-email) > span';
const SELECTOR_PRICE_V1 = '#priceBox > div.pricing p.price:not(.enter-email) > span:nth-child(1)';
const SELECTOR_PRICE_V2 = '#priceBox > div.pricing p.price:not(.enter-email)';
const SELECTOR_QTY_V1 = '#priceBox > div.pricing p.price:not(.enter-email) > span:nth-child(1)';
const SELECTOR_QTY_V2 = '#priceBox > div.pricing p.price:not(.enter-email) > span:nth-child(1)';
const SELECTOR_NAME = '#mainProductContentContainer > h1';
const SELECTOR_SKU = '#mainProductContentContainer > div.product-subhead > span.item-number > span > span';
const SELECTOR_UPC = '#page > div.side-col.aside.new-exp > div:nth-child(2) > div > div.meta > div.product__stat > span.product__stat-desc';

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray([
    'Price', 'Name', 'SKU', 'Upc', 'Qty'
]);
$rowIndex = 2;
unset($sheet);

$productLinksPath = getopt('p:')['p'] ?? null;

if (null === $productLinksPath) {
    echo 'Укажи путь к файлу со ссылками с помощью аргумента `-p`' . PHP_EOL;
    die();
}

$file = fopen($productLinksPath,'r');

$time = time();
$links = [];
while(!feof($file)) {
    $link = str_replace("\n", '', fgets($file));
    if (filter_var($link, FILTER_VALIDATE_URL)) {
        $links[] = $link;
    }
    if (10 === count($links)) {

        gc_disable();

        $result = wait(processLinksChunk($links));
        writeToSheet($spreadsheet, $rowIndex, $result);
        $rowIndex += count($result);

        echo PHP_EOL;
        echo 'Current row index: ' . $rowIndex . PHP_EOL;
        echo 'Time passed: ' . (time() - $time) . PHP_EOL;
        echo 'Memory usage: ' . memory_get_usage() . PHP_EOL;
        echo 'Memory peak usage: ' . memory_get_peak_usage() . PHP_EOL;
        echo 'Last processed link: ' . end($links) . PHP_EOL;
        echo '-------------' . PHP_EOL;
        echo PHP_EOL;

        $links = [];

        gc_enable();
        gc_collect_cycles();
    }
    usleep(rand(200, 1000) * 1000);
}
fclose($file);

if ([] !== $links) {

    echo PHP_EOL;
    echo 'Last chunk' . PHP_EOL;
    echo 'Current row index: ' . $rowIndex . PHP_EOL;
    echo 'Time passed: ' . (time() - $time) . PHP_EOL;
    echo 'Memory usage: ' . memory_get_usage() . PHP_EOL;
    echo 'Memory peak usage: ' . memory_get_peak_usage() . PHP_EOL;
    echo 'Last processed link: ' . end($links) . PHP_EOL;
    echo '-------------' . PHP_EOL;
    echo PHP_EOL;

    gc_disable();

    $result = wait(processLinksChunk($links));
    writeToSheet($spreadsheet, $rowIndex, $result);

    gc_enable();
    gc_collect_cycles();
}



// ------------------------------------------------------------------------
// functions
// ------------------------------------------------------------------------


function processLinksChunk(array $links): Promise
{
    return call(function () use ($links) {
        $client = HttpClientBuilder::buildDefault();

        $responses = [];
        foreach ($links as $link) {
            $request = new Request($link);
            $request->setHeaders(makeHeaders());
            $responses[] = $client->request($request);
        }
        $responses = yield any($responses);
        echo 'succeeded = ' . count($responses[1]) . PHP_EOL;

        $bodies = [];
        foreach ($responses[1] as $response) {
            $bodies[] = $response->getBody()->buffer();
        }
        $bodies = yield any($bodies);
        echo 'succeeded bodies = ' . count($bodies[1]) . PHP_EOL;

        unset($responses);

        $result = [];
        /** @var \Amp\Http\Client\Response $response */
        foreach ($bodies[1] as $body) {
            if ('' === $body) {
                continue;
            }
            $crawler = new Crawler($body);

            try {
                $pricingSpansCount = $crawler->filter(SELECTOR_PRICING_SPANS)->count();
                $productName = $crawler->filter(SELECTOR_NAME)->text();

                $result[] = [
                    'price' => getPrice($crawler, $pricingSpansCount),
                    'name' => $productName,
                    'sku' => $crawler->filter(SELECTOR_SKU)->text(),
                    'upc' => checkUpcAvailable($crawler) ? $crawler->filter(SELECTOR_UPC)->text() : '-',
                    'qty' => getQty($crawler, $pricingSpansCount, $productName),
                ];
            } catch (LogicException $e) {
                try {
                    echo 'Product with different html was found. ' . $crawler->filter(SELECTOR_NAME)->text() . PHP_EOL;
                } catch (Exception $e) {
                    echo 'Page with captcha was returned.' . PHP_EOL;
                }
            }

            unset($crawler);
        }

        return $result;
    });
}

function checkUpcAvailable(Crawler $crawler): bool
{
    return (bool) $crawler->filter(SELECTOR_UPC)->count();
}

function getQty(Crawler $crawler, int $pricingSpansCount, string $productName): string
{
    if (2 === $pricingSpansCount) {
        $qty = $crawler->filter(SELECTOR_QTY_V1)->text();
    } else {
        $qty = $crawler->filter(SELECTOR_QTY_V2)->text();
    }

    if ('/Each' === $qty) {
        return '1';
    }

    $matches = [];
    preg_match('/ (\d+)\/\w+/', $productName, $matches);
    return $matches[1] ?? '1';
}

function getPrice(Crawler $crawler, int $pricingSpansCount): string
{
    if (2 === $pricingSpansCount) {
        $price = $crawler->filter(SELECTOR_PRICE_V1)->text();
    } else {
        $priceField = $crawler->filter(SELECTOR_PRICE_V2)->text();
        $matches = [];
        preg_match('/([\d,.]+)/', $priceField, $matches);
        $price = $matches[1];
    }

    if (false !== strpos($price, '/')) {
        throw new LogicException('Product with different html was found.');
    }

    return $price;
}

function makeHeaders(): array
{
    return [
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
        'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'sec-fetch-dest' => 'document',
        'sec-fetch-mode' => 'navigate',
        'sec-fetch-site' => 'none',
        'upgrade-insecure-requests' => '1',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.106 Mobile Safari/537.36'
    ];
}

function writeToSheet(Spreadsheet $spreadsheet, int $rowIndex, array $data): void
{
    foreach ($data as $row) {
        $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow(1, $rowIndex, $row['price']);
        $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow(2, $rowIndex, $row['name']);
        $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow(3, $rowIndex, $row['sku']);
        $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow(4, $rowIndex, $row['upc']);
        $spreadsheet->getActiveSheet()->setCellValueByColumnAndRow(5, $rowIndex, $row['qty']);
        $rowIndex++;
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save('Products Pricing.xlsx');

    unset($writer);
}