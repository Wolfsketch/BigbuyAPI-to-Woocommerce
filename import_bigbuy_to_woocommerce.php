<?php
// Verhoog het geheugengebruik tot 1GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '1G');
ini_set('max_execution_time', '1200');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log bestand instellen
$log_file_bigbuy = __DIR__ . '/import_bigbuy_to_woocommerce.log'; // Zorg ervoor dat het pad naar het logbestand correct is
$logging_enabled_bigbuy = false; // Zet op true om logging in te schakelen, en false om uit te schakelen

// Functie om logmeldingen toe te voegen
function logMessageBigbuy($message) {
    global $log_file_bigbuy, $logging_enabled_bigbuy;
    if ($logging_enabled_bigbuy) {
        if (!file_exists($log_file_bigbuy) || filesize($log_file_bigbuy) === 0) {
            file_put_contents($log_file_bigbuy, "Start log\n", FILE_APPEND);
        }
        $timestamp = date("Y-m-d H:i:s");
        file_put_contents($log_file_bigbuy, "[$timestamp] $message\n", FILE_APPEND);
    }
}

// Functie om verwerkte producten op te slaan in een JSON-bestand
function saveProcessedProducts($filePath, $processedProducts) {
    $jsonContent = json_encode($processedProducts, JSON_PRETTY_PRINT);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessageBigbuy("Error: JSON fout bij het coderen van verwerkte producten - " . json_last_error_msg());
        die("Error: JSON fout bij het coderen van verwerkte producten - " . json_last_error_msg());
    }
    file_put_contents($filePath, $jsonContent);
}

// Functie om de beschikbaarheid van een afbeelding te controleren
function checkImageAvailability($url) {
    $headers = @get_headers($url);
    return stripos($headers[0], "200 OK") ? true : false;
}

// Starttijd definiëren
$start = microtime(true);

// Log start van script
logMessageBigbuy("Cron job gestart");

// Laad de WordPress-omgeving
require_once('/home/f6pw76hthyoq/public_html/wp-load.php');

// Functie om de groothandelsprijs te importeren
function import_bigbuy_wholesale_price($product_id, $wholesale_price) {
    if (!update_post_meta($product_id, '_wholesale_price', $wholesale_price)) {
        logMessageBigbuy("Error: Kan de groothandelsprijs voor product ID $product_id niet bijwerken.");
    } else {
        logMessageBigbuy("Groothandelsprijs bijgewerkt voor product ID $product_id: $wholesale_price");
    }
}

// Functie om de detailhandelsprijs te importeren
function import_retail_price($product_id, $retail_price) {
    if (!update_post_meta($product_id, '_retail_price', $retail_price)) {
        logMessageBigbuy("Error: Kan de detailhandelsprijs voor product ID $product_id niet bijwerken.");
    } else {
        logMessageBigbuy("Detailhandelsprijs bijgewerkt voor product ID $product_id: $retail_price");
    }
}

// Functie om gegevens uit een JSON-bestand te halen
function getData($file) {
    global $log_file;
    $filePath = __DIR__ . '/' . $file . '.json';
    logMessageBigbuy("Laden van bestand: $filePath");
    if (!file_exists($filePath)) {
        logMessageBigbuy("Error: Bestand $filePath niet gevonden.");
        die("Error: Bestand $filePath niet gevonden.");
    }
    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessageBigbuy("Error: JSON fout in $filePath - " . json_last_error_msg());
        die("Error: JSON fout in $filePath - " . json_last_error_msg());
    }
    logMessageBigbuy("Bestandsgrootte: " . filesize($filePath) . " bytes");
    logMessageBigbuy("Gegevens geladen uit bestand: $filePath");
    return $data;
}

// Batch gegevens ophalen functie
function getDataInBatches($filePath, $batchSize) {
    logMessageBigbuy("Laden van bestand in batches: $filePath");
    if (!file_exists($filePath)) {
        logMessageBigbuy("Error: Bestand $filePath niet gevonden.");
        die("Error: Bestand $filePath niet gevonden.");
    }

    $fileContent = file_get_contents($filePath);
    $jsonContent = json_decode($fileContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessageBigbuy("Error: JSON fout in $filePath - " . json_last_error_msg());
        die("Error: JSON fout in $filePath - " . json_last_error_msg());
    }

    $totalItems = count($jsonContent);
    logMessageBigbuy("Totaal aantal items: $totalItems");

    $batches = array_chunk($jsonContent, $batchSize);
    logMessageBigbuy("Aantal batches: " . count($batches));

    return $batches;
}

// Functie om alleen de gewenste categorieën aan te maken of bij te werken
function createCategories($taxonomies) {
    global $log_file_bigbuy;
    $createdCategories = [];
    $allowedTaxonomies = [18827, 25746]; // Alleen deze categorieën toestaan

    foreach ($taxonomies as $taxonomy) {
        logMessageBigbuy("Verwerken categorie ID: {$taxonomy['id']}, Naam: {$taxonomy['name']}, Slug: {$taxonomy['url']}");
        if (in_array($taxonomy['id'], $allowedTaxonomies)) {
            $term = get_term_by('slug', $taxonomy['url'], 'product_cat');
            if ($term) {
                logMessageBigbuy("Categorie met slug '{$taxonomy['url']}' gevonden in WooCommerce met ID '{$term->term_id}'");
                $createdCategories[$taxonomy['id']] = $term->term_id;
            } else {
                logMessageBigbuy("Categorie met slug '{$taxonomy['url']}' niet gevonden in WooCommerce, nieuwe categorie aanmaken");
                // Categorie bestaat niet, maak een nieuwe aan
                $term = wp_insert_term($taxonomy['name'], 'product_cat', array('slug' => $taxonomy['url']));
                if (is_wp_error($term)) {
                    logMessageBigbuy("Error: Kan categorie '{$taxonomy['name']}' met slug '{$taxonomy['url']}' niet aanmaken. " . $term->get_error_message());
                    continue;
                }
                $createdCategories[$taxonomy['id']] = $term['term_id'];
                logMessageBigbuy("Categorie '{$taxonomy['name']}' met slug '{$taxonomy['url']}' aangemaakt met ID " . $term['term_id']);
            }
        } else {
            logMessageBigbuy("Categorie ID {$taxonomy['id']} niet toegestaan");
        }
    }
    return $createdCategories;
}

// Voorbeeld gebruik van de functies
$taxonomies = [
    ['id' => 18827, 'name' => "TV's", 'url' => 'tv-video-en-thuisbioscoop-tv-s'],
    ['id' => 25746, 'name' => "TV-muur- en plafondbeugels", 'url' => 'tv-tafels-en-standaards-tv-muur-en-plafondbeugels_25746']
];

logMessageBigbuy("Start categorieën aanmaken/bijwerken");
$createdCategories = createCategories($taxonomies);
$updatedCategories = updateCategoryNames($createdCategories);
logMessageBigbuy("Categorieën aanmaken/bijwerken voltooid");


// Haal alle gegevens op uit de verschillende bestanden
logMessageBigbuy("Laden gegevens...");
$products = getData('products');
logMessageBigbuy("Products geladen");
$product_prices = getData('product_prices');
logMessageBigbuy("Product Prices geladen");
$taxonomies = getData('product_taxonomies');
logMessageBigbuy("Taxonomies geladen");
$tags = getData('product_tags');
logMessageBigbuy("Tags geladen");
$stock = getData('all_stock_data');
logMessageBigbuy("Stock geladen");
$attributes = getData('attributes');
logMessageBigbuy("Attributes geladen");
$manufacturers = getData('manufacturers');
logMessageBigbuy("Manufacturers geladen");
$product_information = getData('product_information');
logMessageBigbuy("Product Information geladen");

// Laad product_images in batches
$product_images_batches = getDataInBatches(__DIR__ . '/product_images.json', 5000);

// Verwerk de batches van product_images
$product_images = [];
foreach ($product_images_batches as $batch) {
    logMessageBigbuy("Batch grootte: " . count($batch));
    $product_images = array_merge($product_images, $batch);
    // Voeg je verwerkingslogica hier toe
    // Free up memory
    unset($batch);
    gc_collect_cycles();
}
logMessageBigbuy("Totaal aantal afbeeldingen geladen: " . count($product_images));

// Creëer alleen de gewenste categorieën
$createdCategories = updateCategoryNames(createCategories($taxonomies));

// Bestand met verwerkte producten
$processedProductsFile = '/home/f6pw76hthyoq/public_html/BigbuyAPI/processed_products.json';
if (file_exists($processedProductsFile)) {
    $processedProducts = json_decode(file_get_contents($processedProductsFile), true);
} else {
    $processedProducts = [];
}
logMessageBigbuy("Processed Products geladen");

// Log het aantal producten in products.json
logMessageBigbuy("Aantal producten in products.json: " . count($products));

// Log het aantal producten in processed_products.json
logMessageBigbuy("Aantal producten in processed_products.json: " . count($processedProducts));

// Functie om merken toe te voegen aan producten
function addBrandToProduct($product_id, $brand_name) {
    if (!term_exists($brand_name, 'brand')) {
        $term = wp_insert_term($brand_name, 'brand');
        if (is_wp_error($term)) {
            logMessageBigbuy("Error: Kan merk '$brand_name' niet aanmaken. " . $term->get_error_message());
            return;
        }
        $term_id = $term['term_id'];
    } else {
        $term = get_term_by('name', $brand_name, 'brand');
        $term_id = $term->term_id;
    }
    wp_set_object_terms($product_id, $term_id, 'brand');
    logMessageBigbuy("Merk '$brand_name' toegevoegd aan product ID $product_id.");
}

// Functie om producten en hun gerelateerde gegevens samen te voegen
function mergeProductData($products, $product_prices, $taxonomies, $tags, $stock, $images, $attributes, $manufacturers, $product_information, $woocommerce, $offset, $batch_size, &$processedProducts) {
    global $wpdb;
    global $createdCategories;
    global $log_file;
    global $processedProductsFile;

    // Productinformatie indexeren op ID voor snellere toegang
    $productInfoById = [];
    foreach ($product_information as $info) {
        $productInfoById[$info['id']] = $info;
    }

    // Filter producten op basis van de gewenste categorieën en de conditie (alleen "NEW")
    $filteredProducts = array_filter($products, function($product) use ($processedProducts) {
        $shouldProcess = in_array($product['taxonomy'], [18827, 25746]) && isset($product['condition']) && $product['condition'] === 'NEW';
        return $shouldProcess;
    });

    // Log het aantal gefilterde producten
    $totalFilteredProducts = count($filteredProducts);
    logMessageBigbuy("Totaal aantal producten geselecteerd voor verwerking: " . $totalFilteredProducts);

    // Verwijder de al verwerkte producten
    $newProducts = array_filter($filteredProducts, function($product) use ($processedProducts) {
        return !in_array($product['id'], $processedProducts);
    });

    // Log het aantal nieuwe producten
    $totalNewProducts = count($newProducts);
    logMessageBigbuy("Totaal aantal nieuwe producten: " . $totalNewProducts);

    // Batchverwerking
    $productsToProcess = array_slice($newProducts, $offset, $batch_size);

    logMessageBigbuy("Product ID's in de huidige batch: " . implode(", ", array_column($productsToProcess, 'id')));

    $processed_count = 0;
    $not_processed_count = 0;
    $already_processed_count = 0;
    $filter_not_met_count = 0;

    foreach ($productsToProcess as &$product) {
        // Haal de productinformatie op
        $info = isset($productInfoById[$product['id']]) ? $productInfoById[$product['id']] : null;

        if ($info === null) {
            logMessageBigbuy("Geen productinformatie gevonden voor product ID " . $product['id']);
            continue;
        }

        $product['name'] = $info['name'];
        $product['description'] = $info['description'];
        $product['sku'] = $info['sku'];
        if (isset($info['wholesalePrice'])) {
            $product['wholesalePrice'] = $info['wholesalePrice'];
        }
        if (isset($info['retailPrice'])) {
            $product['retailPrice'] = $info['retailPrice'];
        }

        // Controleer of de productnaam beschikbaar is
        if (empty($product['name'])) {
            logMessageBigbuy("Product ID " . $product['id'] . " heeft geen naam, overslaan.");
            continue;
        }

        logMessageBigbuy("Verwerken product ID " . $product['id']);
        logMessageBigbuy("Product taxonomy: " . $product['taxonomy']);

        $product['prices'] = array_values(array_filter($product_prices, function($price) use ($product) {
            return $price['id'] == $product['id'];
        }));

        $product['taxonomies'] = array_values(array_filter($taxonomies, function($taxonomy) use ($product) {
            return $taxonomy['id'] == $product['taxonomy'];
        }));

        $product['tags'] = array_values(array_filter($tags, function($tag) use ($product) {
            return $tag['id'] == $product['id'];
        }));

        $product['stock'] = array_values(array_filter($stock, function($stockItem) use ($product) {
            return is_array($stockItem) && isset($stockItem['id']) && $stockItem['id'] == $product['id'];
        }));

        logMessageBigbuy("Voorraad voor product ID " . $product['id'] . ": " . print_r($product['stock'], true));

        if (empty($product['stock'])) {
            logMessageBigbuy("Geen voorraadgegevens gevonden voor product ID " . $product['id']);
        }

        $product['categories'] = array_values(array_filter($taxonomies, function($taxonomy) use ($product) {
            return $taxonomy['id'] == $product['taxonomy'];
        }));

        logMessageBigbuy("Product ID " . $product['id'] . " categorieën: " . print_r($product['categories'], true));

        $product['images'] = array_reduce($images, function($carry, $image) use ($product) {
            if ($image['id'] == $product['id']) {
                foreach ($image['images'] as $img) {
                    if (isset($img['url']) && filter_var($img['url'], FILTER_VALIDATE_URL) && checkImageAvailability($img['url'])) {
                        $carry[] = ['src' => $img['url']];
                    }
                }
            }
            return $carry;
        }, []);

        $product['attributes'] = array_values(array_filter($attributes, function($attribute) use ($product) {
            return $attribute['id'] == $product['id'];
        }));

        $product['manufacturer'] = array_values(array_filter($manufacturers, function($manufacturer) use ($product) {
            return $manufacturer['id'] == $product['manufacturer'];
        }));

        if (!empty($product['categories'])) {
            $product['categories'] = array_map(function($category) use ($createdCategories) {
                $categoryData = [
                    'id' => isset($createdCategories[$category['id']]) ? $createdCategories[$category['id']] : 0,
                    'name' => isset($category['name']) ? $category['name'] : ''
                ];
                return $categoryData;
            }, $product['categories']);
        } else {
            $product['categories'] = [['id' => 0, 'name' => '']];
        }

        logMessageBigbuy("Product ID " . $product['id'] . " categories: " . json_encode($product['categories']));

        // Voeg hier een try-catch block toe voor WooCommerce API-aanroep
        try {
            $product_id = wc_get_product_id_by_sku($product['sku']);
            if ($product_id) {
                logMessageBigbuy("Bestaand product gevonden voor SKU " . $product['sku'] . " met product ID " . $product_id);
                wp_set_object_terms($product_id, array_column($product['categories'], 'id'), 'product_cat');
                logMessageBigbuy("Categories set for Product ID $product_id: " . json_encode($product['categories']));

                // Voeg deze regel toe om de wholesale price op te slaan
                if (isset($product['wholesalePrice'])) {
                    logMessageBigbuy("Importing wholesale price for Product ID $product_id: " . $product['wholesalePrice']);
                    import_bigbuy_wholesale_price($product_id, $product['wholesalePrice']);
                }

                // Voeg deze regel toe om de retail price op te slaan
                if (isset($product['retailPrice'])) {
                    logMessageBigbuy("Importing retail price for Product ID $product_id: " . $product['retailPrice']);
                    import_retail_price($product_id, $product['retailPrice']);
                }

                // Voeg product ID toe aan verwerkte producten
                $processedProducts[] = $product['id'];
                saveProcessedProducts($processedProductsFile, $processedProducts);

            } else {
                logMessageBigbuy("Geen bestaand product gevonden voor SKU " . $product['sku'] . ", maak nieuw product aan.");
                // Maak nieuw product aan
                $data = [
                    'name' => $product['name'] ?? 'Naam niet beschikbaar',
                    'type' => 'simple',
                    'regular_price' => (string)($product['retailPrice'] ?? ''),
                    'description' => $product['description'] ?? '',
                    'short_description' => $product['short_description'] ?? '',
                    'sku' => $product['sku'] ?? '',
                    'manage_stock' => true,
                    'stock_quantity' => array_reduce($product['stock'], function($carry, $item) {
                        // Alleen de grootste "quantity" gebruiken
                        $max_stock = max(array_column($item['stocks'], 'quantity'));
                        $carry += $max_stock;
                        return $carry;
                    }, 0),
                    'categories' => array_map(function($category) {
                        return ['id' => $category['id']];
                    }, $product['categories']),
                    'images' => $product['images'],
                    'attributes' => array_map(function($attribute) {
                        return [
                            'id' => $attribute['id'] ?? '',
                            'name' => $attribute['name'] ?? '',
                            'option' => $attribute['option'] ?? ''
                        ];
                    }, $product['attributes']),
                ];

                if (!empty($product['ean13'])) {
                    $data['meta_data'][] = [
                        'key' => '_ean_code',
                        'value' => $product['ean13']
                    ];
                }

                if (!empty($product['manufacturer'])) {
                    $manufacturer = reset($product['manufacturer']);
                    if (!empty($manufacturer['name'])) {
                        $data['meta_data'][] = [
                            'key' => '_brand_name',
                            'value' => $manufacturer['name']
                        ];
                    }
                }

                if (!empty($product['wholesalePrice'])) {
                    $data['meta_data'][] = [
                        'key' => '_wholesale_price',
                        'value' => $product['wholesalePrice']
                    ];
                }

                if (!empty($product['retailPrice'])) {
                    $data['meta_data'][] = [
                        'key' => '_retail_price',
                        'value' => $product['retailPrice']
                    ];
                }

                logMessageBigbuy("Nieuwe productdata: " . json_encode($data));
                $result = $woocommerce->post('products', $data);
                logMessageBigbuy("Product " . $product['id'] . " geïmporteerd met resultaat: " . json_encode($result));

                // Koppel merk aan nieuw product
                if (!empty($product['manufacturer'])) {
                    $manufacturer = reset($product['manufacturer']);
                    if (!empty($manufacturer['name'])) {
                        addBrandToProduct($result->id, $manufacturer['name']);
                    }
                }

                // Voeg product ID toe aan verwerkte producten
                $processedProducts[] = $product['id'];
                saveProcessedProducts($processedProductsFile, $processedProducts);
            }

            $processed_count++;

        } catch (Exception $e) {
            logMessageBigbuy("Fout bij WooCommerce API-aanroep: " . $e->getMessage());
            $not_processed_count++;
        }
    }

    logMessageBigbuy("Totaal aantal producten reeds verwerkt: " . $already_processed_count);
    logMessageBigbuy("Totaal aantal producten voldoen niet aan de filtercriteria: " . $filter_not_met_count);
    logMessageBigbuy("Verwerkte producten in deze batch: $processed_count, Niet verwerkte producten: $not_processed_count");

    return $processed_count;
}

// Functie om categorieën te updaten zonder de namen te wijzigen
function updateCategoryNames($categories) {
    $updatedCategories = [];
    foreach ($categories as $id => $term_id) {
        logMessageBigbuy("Bijwerken categorie ID: {$id}, WooCommerce term ID: {$term_id}");
        // Controleer of de categorie bestaat in WooCommerce
        if ($term_id && is_numeric($term_id)) {
            $term = get_term_by('id', $term_id, 'product_cat');
            if ($term) {
                // Update de categorie zonder de naam te wijzigen
                logMessageBigbuy("Categorie bestaat met ID {$term_id} en naam '{$term->name}'");
                $updatedCategories[$id] = $term->term_id;
            } else {
                logMessageBigbuy("Categorie ID {$id} niet gevonden in WooCommerce");
            }
        } else {
            logMessageBigbuy("Ongeldige categoriedata: ID={$id}, Term ID={$term_id}");
        }
    }
    return $updatedCategories;
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

// Bepaal batchgrootte en offset
$batch_size = 5; // Aantal producten per batch
$offset = get_option('bigbuy_import_offset', 0);

// Voeg alle gegevens samen
logMessageBigbuy("Start mergeProductData");
$processed_count = mergeProductData($products, $product_prices, $taxonomies, $tags, $stock, $product_images, $attributes, $manufacturers, $product_information, $woocommerce, $offset, $batch_size, $processedProducts);
logMessageBigbuy("End mergeProductData, processed_count: " . $processed_count);

// Toon het resultaat
logMessageBigbuy('Aantal verwerkte producten in deze batch: ' . $processed_count);

if ($processed_count < $batch_size) {
    logMessageBigbuy('Alle producten zijn verwerkt of geen producten verwerkt in deze batch.');
    // Zet offset terug naar 0 voor het geval dat de volgende run begint vanaf het begin
    update_option('bigbuy_import_offset', 0);
} else {
    // Update de offset voor de volgende batch
    $next_offset = $offset + $batch_size;
    logMessageBigbuy('Update offset naar: ' . $next_offset);
    update_option('bigbuy_import_offset', $next_offset);
}

$end = microtime(true);
$execution_time = ($end - $start);
logMessageBigbuy("Script uitgevoerd in " . $execution_time . " seconden.");
?>
