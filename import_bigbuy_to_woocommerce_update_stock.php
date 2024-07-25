<?php
// Verhoog het geheugengebruik tot 1GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '1G');
ini_set('max_execution_time', '1200');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log bestand instellen
$log_file = __DIR__ . '/update_stock.log'; // Zorg ervoor dat het pad naar het logbestand correct is
$logging_enabled = false; // Zet op true om logging in te schakelen, en false om uit te schakelen

// Functie om logmeldingen toe te voegen
function logMessage($message) {
    global $log_file, $logging_enabled;
    if ($logging_enabled) {
        if (!file_exists($log_file) || filesize($log_file) === 0) {
            file_put_contents($log_file, "Start log\n", FILE_APPEND);
        }
        $timestamp = date("Y-m-d H:i:s");
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}
// Starttijd definiëren
$start = microtime(true);

// Log start van script
logMessage("Cron job gestart voor voorraadupdate");

// Laad de WordPress-omgeving
require_once('/home/f6pw76hthyoq/public_html/wp-load.php');

// Functie om gegevens uit een JSON-bestand te halen
function getData($file) {
    global $log_file;
    $filePath = __DIR__ . '/' . $file . '.json';
    logMessage("Laden van bestand: $filePath");
    if (!file_exists($filePath)) {
        logMessage("Error: Bestand $filePath niet gevonden.");
        die("Error: Bestand $filePath niet gevonden.");
    }
    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("Error: JSON fout in $filePath - " . json_last_error_msg());
        die("Error: JSON fout in $filePath - " . json_last_error_msg());
    }
    logMessage("Gegevens geladen uit bestand: $filePath");
    return $data;
}

// Functie om alle producten met hun SKU's en voorraad uit WooCommerce op te halen
function getAllWooCommerceProducts($woocommerce) {
    $statuses = ['publish', 'trash']; // Voeg 'trash' toe om producten in de prullenbak op te halen
    $all_products = [];

    foreach ($statuses as $status) {
        $page = 1;
        $per_page = 100; // Aantal producten per pagina

        do {
            try {
                $products = $woocommerce->get('products', ['page' => $page, 'per_page' => $per_page, 'status' => $status]);
                $all_products = array_merge($all_products, $products);
                $page++;
            } catch (Exception $e) {
                logMessage("Fout bij ophalen van WooCommerce producten: " . $e->getMessage());
                break;
            }
        } while (count($products) == $per_page);
    }

    logMessage("Aantal producten opgehaald uit WooCommerce: " . count($all_products));
    return $all_products;
}

// Functie om voorraad bij te werken in WooCommerce
function updateProductStock($woocommerce, $stock_data, $woocommerce_products) {
    $sku_to_product_id = [];
    $sku_to_stock = [];
    $product_status = [];

    // Maak een map van SKU naar product ID en huidige voorraad in WooCommerce
    foreach ($woocommerce_products as $product) {
        if (isset($product->sku) && isset($product->id) && isset($product->stock_quantity)) {
            $sku_to_product_id[$product->sku] = $product->id;
            $sku_to_stock[$product->sku] = $product->stock_quantity;
            $product_status[$product->sku] = $product->status;
        }
    }

    logMessage("Aantal producten in WooCommerce met SKU's: " . count($sku_to_product_id));

    $update_count = 0;
    $match_count = 0;

    foreach ($stock_data as $item) {
        if (isset($item['sku']) && isset($item['stocks'])) {
            $sku = $item['sku'];
            if (isset($sku_to_product_id[$sku])) {
                $match_count++;
                // Bereken de totale voorraad
                $stock_quantity = array_reduce($item['stocks'], function($carry, $stock_item) {
                    return $carry + $stock_item['quantity'];
                }, 0);

                // Vergelijk de voorraad met de huidige voorraad in WooCommerce
                if ($sku_to_stock[$sku] != $stock_quantity) {
                    $product_id = $sku_to_product_id[$sku];
                    $update_data = [
                        'stock_quantity' => $stock_quantity,
                    ];

                    try {
                        $woocommerce->put('products/' . $product_id, $update_data);
                        logMessage("Product ID $product_id (SKU: $sku) voorraad bijgewerkt naar: " . $stock_quantity);
                        $update_count++;

                        // Controleer of product in prullenbak zit en teruggehaald moet worden
                        if ($stock_quantity > 0 && $product_status[$sku] == 'trash') {
                            wp_untrash_post($product_id);
                            logMessage("Product ID $product_id (SKU: $sku) teruggehaald uit prullenbak.");
                        }
                    } catch (Exception $e) {
                        logMessage("Fout bij bijwerken van voorraad voor product ID $product_id (SKU: $sku): " . $e->getMessage());
                    }
                }
            }
        }
    }

    logMessage("Aantal overeenkomende SKU's: " . $match_count);
    logMessage("Aantal bijgewerkte producten: " . $update_count);
}

// WooCommerce API import
require __DIR__ . '/vendor/autoload.php';
use Automattic\WooCommerce\Client;

$woocommerce = new Client(
    'https://waaaauw.be', 
    'ck_e6396ee94bdeb57f18f6bc4e306a0031cee2c6c5', 
    'cs_daf1adec3789c41bb183d7db0b5594731036e8d9',
    [
        'version' => 'wc/v3',
    ]
);

// Haal voorraadgegevens op uit JSON-bestand
logMessage("Laden voorraadgegevens...");
$stock_data = getData('all_stock_data');
logMessage("Voorraadgegevens geladen");

// Haal alle producten op uit WooCommerce
logMessage("Ophalen producten uit WooCommerce...");
$woocommerce_products = getAllWooCommerceProducts($woocommerce);

// Update voorraad in WooCommerce
logMessage("Start voorraad bijwerken...");
updateProductStock($woocommerce, $stock_data, $woocommerce_products);

// Log de uitvoeringstijd
$end = microtime(true);
$execution_time = ($end - $start);
logMessage("Script uitgevoerd in " . $execution_time . " seconden.");
?>
