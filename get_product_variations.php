<?php
// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

// Logging inschakelen of uitschakelen
$logging_enabled = false;

// Log bestand instellen
$logFile = __DIR__ . '/variations.log';

// Functie om te loggen naar een bestand
function logMessage($message) {
    global $logging_enabled, $logFile;
    if ($logging_enabled) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }
}

function fetchProductPrices($client, $language = 'nl', $format = 'json', $taxonomyId, $retryCount = 3) {
    $attempts = 0;
    $waitTime = 10;  // Verhoog de wachttijd tussen pogingen tot 10 seconden
    while ($attempts < $retryCount) {
        try {
            $url = "catalog/productprices.$format?isoCode=$language&parentTaxonomy=$taxonomyId";
            echo "API URL: $url<br>";  // Debug: Toon de API URL
            logMessage("Fetching product prices from URL: $url");
            $response = $client->get($url);

            // Print de ruwe responsinhoud voor debug-doeleinden
            $responseBody = $response->getBody()->getContents();
            echo "Raw Response Body Length: " . strlen($responseBody) . " bytes<br>";
            logMessage("Raw Response Body Length: " . strlen($responseBody) . " bytes");

            // Decodeer de JSON-respons
            $prices = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON Decode Error: " . json_last_error_msg() . "<br>";
                logMessage("JSON Decode Error: " . json_last_error_msg());
            } else {
                echo "Prices Data: " . print_r($prices, true) . "<br>";  // Debug: Toon de gedecodeerde prijsgegevens
                logMessage("Prices Data: " . print_r($prices, true));
            }

            return $prices;
        } catch (GuzzleHttp\Exception\ConnectException $e) {
            $attempts++;
            echo "Fout bij poging $attempts: " . $e->getMessage() . "<br>";
            logMessage("Fout bij poging $attempts: " . $e->getMessage());
            if ($attempts >= $retryCount) {
                echo 'Connection exception when calling BigBuy API: ', $e->getMessage(), PHP_EOL;
                logMessage('Connection exception when calling BigBuy API: ' . $e->getMessage());
                return [];
            }
            // Wacht een paar seconden voor de volgende poging
            sleep($waitTime);
            $waitTime *= 2;  // Verhoog de wachttijd voor de volgende poging
        } catch (GuzzleHttp\Exception\RequestException $e) {
            $attempts++;
            echo "Fout bij poging $attempts: " . $e->getMessage() . "<br>";
            logMessage("Fout bij poging $attempts: " . $e->getMessage());
            if ($attempts >= $retryCount) {
                if ($e->hasResponse()) {
                    $headers = $e->getResponse()->getHeaders();
                    echo "Headers:<br>";
                    foreach ($headers as $name => $values) {
                        echo "$name: " . implode(", ", $values) . "<br>";
                        logMessage("$name: " . implode(", ", $values));
                    }
                }
                echo 'Exception when calling BigBuy API: ', $e->getMessage(), PHP_EOL;
                logMessage('Exception when calling BigBuy API: ' . $e->getMessage());
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

// Haal de prijsgegevens op van BigBuy
$priceData = fetchProductPrices($bigBuyClient, 'nl', 'json', $taxonomyId);
if ($priceData) {
    // Sla de prijsgegevens op in een JSON-bestand
    $filePath = 'product_prices.json';
    file_put_contents($filePath, json_encode($priceData, JSON_PRETTY_PRINT));
    echo 'Prijsgegevens zijn opgeslagen in ' . $filePath . '<br>';
    logMessage("Prijsgegevens zijn opgeslagen in " . $filePath . " at " . date('Y-m-d H:i:s'));
} else {
    echo 'Geen prijsgegevens gevonden.<br>';
    logMessage("Geen prijsgegevens gevonden at " . date('Y-m-d H:i:s'));
}

echo '</div>';
logMessage("Script ended at " . date('Y-m-d H:i:s'));
?>
