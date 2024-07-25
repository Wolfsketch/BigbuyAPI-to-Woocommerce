<?php
// Verhoog het geheugengebruik tot 2GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '2G');
ini_set('max_execution_time', '1800');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');
require __DIR__ . '/vendor/autoload.php';
use Automattic\WooCommerce\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

// Logging inschakelen of uitschakelen
$logging_enabled = true;

// Functie om te loggen naar een bestand
function logMessage($message) {
    global $logging_enabled;
    if ($logging_enabled) {
        $logFile = 'script.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }
}

// WooCommerce client maken
$woocommerce = new Client(
    'https://waaaauw.be',
    'ck_e6396ee94bdeb57f18f6bc4e306a0031cee2c6c5',
    'cs_daf1adec3789c41bb183d7db0b5594731036e8d9',
    [
        'version' => 'wc/v3',
    ]
);

// Functie om alle producten uit WooCommerce op te halen
function getWooCommerceProducts($woocommerce) {
    $all_products = [];
    $page = 1;
    do {
        $products = $woocommerce->get('products', ['page' => $page, 'per_page' => 100]);
        if (empty($products)) {
            break;
        }
        $all_products = array_merge($all_products, $products);
        $page++;
    } while (true);
    return $all_products;
}

// Haal WooCommerce producten op
$woocommerce_products = getWooCommerceProducts($woocommerce);

// Maak een lijst van SKU's
$skus = [];
foreach ($woocommerce_products as $product) {
    if (!empty($product->sku)) {
        $skus[] = $product->sku;
    }
}

// Sla de SKU's op in een JSON-bestand
file_put_contents('product_skus.json', json_encode($skus, JSON_PRETTY_PRINT));
logMessage("SKU's opgeslagen in product_skus.json");

// Functie om verzendkosten op te halen voor een specifiek land
function fetchShippingCost($client, $country) {
    $attempts = 0;
    $retryCount = 3;
    $waitTime = 10;  // Wachttijd tussen pogingen
    while ($attempts < $retryCount) {
        try {
            $url = "https://api.bigbuy.eu/rest/shipping/lowest-shipping-costs-by-country/$country.json";
            logMessage("Fetching shipping cost for country: $country, URL: $url");
            $response = $client->get($url);

            $responseBody = $response->getBody()->getContents();
            $shippingData = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                logMessage("JSON Decode Error: " . json_last_error_msg());
                return [];
            }

            logMessage("Fetched shipping costs for country: $country");
            return $shippingData;
        } catch (GuzzleHttp\Exception\ConnectException $e) {
            $attempts++;
            logMessage("Connection error on attempt $attempts - " . $e->getMessage());
            sleep($waitTime);
            $waitTime *= 2;
        } catch (GuzzleHttp\Exception\RequestException $e) {
            $attempts++;
            logMessage("Request error on attempt $attempts - " . $e->getMessage());
            if ($e->hasResponse()) {
                $headers = $e->getResponse()->getHeaders();
                foreach ($headers as $name => $values) {
                    logMessage("$name: " . implode(", ", $values));
                }
            }
            if ($attempts >= $retryCount) {
                logMessage('Request exception when calling BigBuy API: ' . $e->getMessage());
                return [];
            }
            sleep($waitTime);
            $waitTime *= 2;
        }
    }
    return [];
}

// Maak een BigBuy client aan
$bigBuyClient = getBigBuyClient();

// Haal verzendkosten op voor BE en NL
$shipping_costs_be = fetchShippingCost($bigBuyClient, 'BE');
$shipping_costs_nl = fetchShippingCost($bigBuyClient, 'NL');

// Verwerk de SKU's
$product_shipping_costs = [];
$no_shipping_costs = [];

foreach ($skus as $sku) {
    $cost_be = null;
    $cost_nl = null;
    
    foreach ($shipping_costs_be as $shipping_cost) {
        if ($shipping_cost['reference'] == $sku) {
            $cost_be = $shipping_cost['cost'];
            break;
        }
    }
    
    foreach ($shipping_costs_nl as $shipping_cost) {
        if ($shipping_cost['reference'] == $sku) {
            $cost_nl = $shipping_cost['cost'];
            break;
        }
    }

    if ($cost_be !== null || $cost_nl !== null) {
        $highest_shipping_cost = max($cost_be ?? 0, $cost_nl ?? 0);
        $product_shipping_costs[] = [
            'sku' => $sku,
            'shipping_cost' => $highest_shipping_cost
        ];
    } else {
        $no_shipping_costs[] = $sku;
    }
}

// Verwijder dubbele en sla op in no_shipping_costs.json
$unique_no_shipping_costs = array_unique($no_shipping_costs);
file_put_contents('no_shipping_costs.json', json_encode($unique_no_shipping_costs, JSON_PRETTY_PRINT));

// Sla de verzendkosten op in product_shipping_costs.json
$existing_shipping_costs = file_exists('product_shipping_costs.json') ? json_decode(file_get_contents('product_shipping_costs.json'), true) : [];
$product_shipping_costs = array_merge($existing_shipping_costs, $product_shipping_costs);
file_put_contents('product_shipping_costs.json', json_encode($product_shipping_costs, JSON_PRETTY_PRINT));
logMessage("Alle producten zijn bijgewerkt met verzendkosten en opgeslagen in product_shipping_costs.json.");

?>
