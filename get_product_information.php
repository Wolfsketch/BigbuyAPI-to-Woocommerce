<?php
// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

function fetchProductInformation($client, $language = 'nl', $format = 'json', $taxonomyId, $retryCount = 3) {
    $attempts = 0;
    $waitTime = 10;  // Verhoog de wachttijd tussen pogingen tot 10 seconden
    while ($attempts < $retryCount) {
        try {
            $url = "catalog/productsinformation.$format?isoCode=$language&parentTaxonomy=$taxonomyId";
            echo "API URL: $url<br>";  // Debug: Toon de API URL
            $response = $client->get($url);

            // Print de ruwe responsinhoud voor debug-doeleinden
            $responseBody = $response->getBody()->getContents();
            echo "Raw Response Body Length: " . strlen($responseBody) . " bytes<br>";

            // Decodeer de JSON-respons
            $productInformation = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON Decode Error: " . json_last_error_msg() . "<br>";
            } else {
                echo "Product Information Data: " . print_r($productInformation, true) . "<br>";  // Debug: Toon de gedecodeerde productinformatie
            }

            return $productInformation;
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
$taxonomyId = 19653;  // Alleen de hoofd taxonomy ELEKTRONICA

echo '<div id="output">';

// Haal de productinformatie op van BigBuy
$productInformation = fetchProductInformation($bigBuyClient, 'nl', 'json', $taxonomyId);

// Sla de productinformatie op in een JSON-bestand
$filePath = 'product_information.json';
file_put_contents($filePath, json_encode($productInformation, JSON_PRETTY_PRINT));

// Toon het resultaat
echo 'Productinformatie is opgeslagen in ' . $filePath . '<br>';
echo '</div>';
?>
