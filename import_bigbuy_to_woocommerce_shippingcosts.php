<?php
// Verhoog het geheugengebruik tot 1GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '1G');
ini_set('max_execution_time', '1200');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log bestand instellen
$log_file_shipping  = __DIR__ . '/update_shipping_costs.log'; // Zorg ervoor dat het pad naar het logbestand correct is
$logging_enabled_shipping = false; // Zet op true om logging in te schakelen, en false om uit te schakelen

// Functie om logmeldingen toe te voegen
function logMessageShipping($message) {
    global $log_file_shipping, $logging_enabled_shipping;
    if ($logging_enabled_shipping) {
        if (!file_exists($log_file_shipping) || filesize($log_file_shipping) === 0) {
            file_put_contents($log_file_shipping, "Start log\n", FILE_APPEND);
        }
        $timestamp = date("Y-m-d H:i:s");
        file_put_contents($log_file_shipping, "[$timestamp] $message\n", FILE_APPEND);
    }
}

// Functie om systeembronnen te loggen
function logSystemResources() {
    $memory_usage = memory_get_usage();
    $peak_memory_usage = memory_get_peak_usage();
    $cpu_load = sys_getloadavg();
    logMessageShipping("Huidig geheugengebruik: " . round($memory_usage / 1048576, 2) . "MB");
    logMessageShipping("Piek geheugengebruik: " . round($peak_memory_usage / 1048576, 2) . "MB");
    logMessageShipping("CPU belasting: " . implode(", ", $cpu_load));
}

// Starttijd definiëren
$start = microtime(true);

// Log start van script
logMessageShipping("Cron job gestart voor update van verzendkosten");

// Laad de WordPress-omgeving
logMessageShipping("Laden van WordPress-omgeving...");
require_once('/home/f6pw76hthyoq/public_html/wp-load.php');

// Functie om gegevens uit een JSON-bestand te halen
function getData($file) {
    global $log_file_shipping;
    $filePath = __DIR__ . '/' . $file . '.json';
    logMessageShipping("Laden van bestand: $filePath");
    if (!file_exists($filePath)) {
        logMessageShipping("Error: Bestand $filePath niet gevonden.");
        die("Error: Bestand $filePath niet gevonden.");
    }
    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessageShipping("Error: JSON fout in $filePath - " . json_last_error_msg());
        die("Error: JSON fout in $filePath - " . json_last_error_msg());
    }
    logMessageShipping("Gegevens geladen uit bestand: $filePath");
    return $data;
}

// Functie om gegevens naar een JSON-bestand te schrijven
function writeData($file, $data) {
    global $log_file_shipping;
    $filePath = __DIR__ . '/' . $file . '.json';
    logMessageShipping("Schrijven naar bestand: $filePath");
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessageShipping("Error: JSON fout bij coderen - " . json_last_error_msg());
        die("Error: JSON fout bij coderen - " . json_last_error_msg());
    }
    file_put_contents($filePath, $jsonContent);
}

// Functie om alle producten met hun SKU's uit WooCommerce op te halen
function getAllWooCommerceProducts($woocommerce) {
    $statuses = ['publish', 'trash']; // Voeg 'trash' toe om producten in de prullenbak op te halen
    $all_products = [];

    foreach ($statuses as $status) {
        $page = 1;
        $per_page = 100; // Aantal producten per pagina

        do {
            try {
                logMessageShipping("Ophalen pagina $page met status $status...");
                $products = $woocommerce->get('products', ['page' => $page, 'per_page' => $per_page, 'status' => $status]);
                $all_products = array_merge($all_products, $products);
                $page++;
                logMessageShipping("Aantal producten op pagina $page: " . count($products));
            } catch (Exception $e) {
                logMessageShipping("Fout bij ophalen van WooCommerce producten: " . $e->getMessage());
                break;
            }
            // Log systeembronnen na elke pagina
            logSystemResources();
        } while (count($products) == $per_page);
    }

    logMessageShipping("Aantal producten opgehaald uit WooCommerce: " . count($all_products));
    return $all_products;
}

// Functie om verzendkosten bij te werken in WooCommerce
function updateProductShippingCost($woocommerce, $shipping_cost_data, $woocommerce_products, &$progress) {
    $sku_to_product_id = [];

    // Maak een map van SKU naar product ID in WooCommerce
    foreach ($woocommerce_products as $product) {
        if (isset($product->sku) && !empty($product->sku) && isset($product->id)) {
            $sku_to_product_id[$product->sku] = $product->id;
        }
    }

    logMessageShipping("Aantal producten in WooCommerce met SKU's: " . count($sku_to_product_id));

    $update_count = 0;
    $not_updated_skus = [];
    $updated_products = [];

    foreach ($shipping_cost_data as $item) {
        $sku = $item['sku'];
        // Sla over als de SKU al is verwerkt
        if (in_array($sku, $progress['processed_skus'])) {
            continue;
        }

        logMessageShipping("Verwerken SKU: " . $sku);
        if (isset($sku) && isset($item['shipping_cost'])) {
            if (isset($sku_to_product_id[$sku])) {
                $product_id = $sku_to_product_id[$sku];
                logMessageShipping("Gevonden product ID voor SKU $sku: $product_id");
                if (!in_array($product_id, $updated_products)) {
                    $update_data = [
                        'meta_data' => [
                            [
                                'key' => '_shipping_cost',
                                'value' => $item['shipping_cost']
                            ]
                        ]
                    ];

                    logMessageShipping("Update data voor product ID $product_id: " . json_encode($update_data));
                    try {
                        $result = $woocommerce->put('products/' . $product_id, $update_data);
                        if (isset($result->id)) {
                            logMessageShipping("Product ID $product_id (SKU: $sku) verzendkosten bijgewerkt naar: " . $item['shipping_cost']);
                            $updated_products[] = $product_id;
                            $update_count++;
                            // Voeg SKU toe aan verwerkte lijst
                            $progress['processed_skus'][] = $sku;
                        } else {
                            logMessageShipping("Bijwerken mislukt voor Product ID $product_id (SKU: $sku). Resultaat: " . json_encode($result));
                        }
                    } catch (Exception $e) {
                        logMessageShipping("Fout bij bijwerken van verzendkosten voor product ID $product_id (SKU: $sku): " . $e->getMessage());
                    }
                } else {
                    logMessageShipping("Product ID $product_id (SKU: $sku) is al bijgewerkt.");
                }
            } else {
                $not_updated_skus[] = $sku;
                logMessageShipping("SKU $sku niet gevonden in WooCommerce.");
            }
        } else {
            logMessageShipping("Ongeldige gegevens in verzendkosten data: " . json_encode($item));
        }

        // Save progress after every update
        writeData('progress_verzendkosten_processed_products', $progress);

        // Log systeembronnen na elke update
        logSystemResources();
    }

    logMessageShipping("Aantal bijgewerkte producten: " . $update_count);
    logMessageShipping("Aantal niet bijgewerkte producten: " . count($not_updated_skus));
    logMessageShipping("SKU's van niet bijgewerkte producten: " . implode(', ', $not_updated_skus));
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

// Haal verzendkosten gegevens op uit JSON-bestand
logMessageShipping("Laden verzendkosten gegevens...");
$shipping_cost_data = getData('product_shipping_costs');
logMessageShipping("Verzendkosten gegevens geladen");

// Haal alle producten op uit WooCommerce
logMessageShipping("Ophalen producten uit WooCommerce...");
$woocommerce_products = getAllWooCommerceProducts($woocommerce);

// Laad de voortgang
$progress_file = 'progress_verzendkosten_processed_products.json';
if (file_exists($progress_file)) {
    $progress = getData('progress_verzendkosten_processed_products');
    if (!isset($progress['processed_skus']) || !is_array($progress['processed_skus'])) {
        $progress['processed_skus'] = [];
    }
    logMessageShipping("Voortgang geladen: aantal verwerkte SKU's is " . count($progress['processed_skus']));
} else {
    $progress = ['processed_skus' => []];
    writeData('progress_verzendkosten_processed_products', $progress);
    logMessageShipping("Voortgangsbestand niet gevonden, nieuw voortgangsbestand aangemaakt.");
}

// Update verzendkosten in WooCommerce in batches
$batch_size = 200; // Verhoog de batchgrootte
$total_batches = ceil(count($shipping_cost_data) / $batch_size);

logMessageShipping("Totaal aantal batches: $total_batches");

for ($batch = 0; $batch < $total_batches; $batch++) {
    $start_index = $batch * $batch_size;
    $end_index = $start_index + $batch_size;
    logMessageShipping("Verwerken batch " . ($batch + 1) . " van " . $total_batches . " (index: $start_index tot $end_index)");
    $batch_data = array_slice($shipping_cost_data, $start_index, $batch_size);
    updateProductShippingCost($woocommerce, $batch_data, $woocommerce_products, $progress);
}

// Log de uitvoeringstijd
$end = microtime(true);
$execution_time = ($end - $start);
logMessageShipping("Script uitgevoerd in " . $execution_time . " seconden.");
?>
