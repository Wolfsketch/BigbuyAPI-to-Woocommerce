<?php
// Verhoog het geheugengebruik tot 1GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '1G');
ini_set('max_execution_time', '1200');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

function fetchProducts($client, $language = 'nl', $format = 'json', $limit = 100, $page = 1, $taxonomyId, $retryCount = 3) {
    $attempts = 0;
    $waitTime = 10;  // Verhoog de wachttijd tussen pogingen tot 10 seconden
    while ($attempts < $retryCount) {
        try {
            $url = "catalog/products.$format?isoCode=$language&limit=$limit&page=$page&parentTaxonomy=$taxonomyId";
            echo "API URL: $url<br>";  // Debug: Toon de API URL
            $response = $client->get($url);

            // Print de ruwe responsinhoud voor debug-doeleinden
            $responseBody = $response->getBody()->getContents();
            echo "Raw Response Body Length: " . strlen($responseBody) . " bytes<br>";

            // Decodeer de JSON-respons
            $products = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON Decode Error: " . json_last_error_msg() . "<br>";
            } else {
                echo "Products Data: " . print_r($products, true) . "<br>";  // Debug: Toon de gedecodeerde productdata
            }

            return $products;
        } catch (Exception $e) {
            $attempts++;
            echo "Fout bij poging $attempts: " . $e->getMessage() . "<br>";
            if ($attempts >= $retryCount) {
                if ($e->getResponse()) {
                    $headers = $e->getResponse()->getHeaders();
                    echo "Headers:<br>";
                    foreach ($headers as $name => $values) {
                        echo "$name: " . implode(", ", $values) . "<br>";
                    }
                }
                echo 'Exception when calling BigBuy API: ', $e->getMessage(), PHP_EOL;
                return [];
            }
            // Wacht een paar seconden voor de volgende poging
            sleep($waitTime);
            $waitTime *= 2;  // Verhoog de wachttijd voor de volgende poging
        }
    }
}

// Maak een BigBuy client aan
$bigBuyClient = getBigBuyClient();
$taxonomyIds = [19653];  // Alleen de hoofd taxonomy ELEKTRONICA

$allowedTaxonomies = [18827, 25746];  // De gewenste taxonomieën om te filteren

echo '<div id="output">';

$allProducts = [];
foreach ($taxonomyIds as $taxonomyId) {
    echo "Verwerken taxonomie ID: $taxonomyId<br>";
    $page = 1;
    do {
        $products = fetchProducts($bigBuyClient, 'nl', 'json', 100, $page, $taxonomyId);
        if (!empty($products)) {
            foreach ($products as $product) {
                // Filter op de gewenste taxonomieën
                if (in_array($product['taxonomy'], $allowedTaxonomies)) {
                    $allProducts[$product['id']] = $product;
                }
            }
            $page++;
        } else {
            break;
        }
        // Voeg een pauze toe tussen verzoeken om de API-limieten niet te overschrijden
        sleep(1);  // Pauze van 1 seconde tussen verzoeken
    } while (count($products) == 100);
}

// Sla de gefilterde producten op in de JSON file
file_put_contents('products.json', json_encode(array_values($allProducts), JSON_PRETTY_PRINT));
echo 'Alle gefilterde producten zijn bijgewerkt en opgeslagen in products.json<br>';
echo '</div>';
?>
