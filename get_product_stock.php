<?php
// Verhoog het geheugengebruik tot 1GB en de uitvoeringstijd tot 1200 seconden
ini_set('memory_limit', '1G');
ini_set('max_execution_time', '1200');

// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

// Logging inschakelen of uitschakelen
$logging_enabled = false;

// Functie om te loggen naar een bestand
function logMessage($message) {
    global $logging_enabled;
    if ($logging_enabled) {
        $logFile = 'script.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }
}

// Functie om voorraadgegevens op te halen voor een specifieke taxonomie
function fetchStockForTaxonomy($client, $taxonomyId, $language = 'nl', $format = 'json', $retryCount = 3) {
    $attempts = 0;
    $waitTime = 10;  // Wachttijd tussen pogingen
    while ($attempts < $retryCount) {
        try {
            $url = "catalog/productsstockbyhandlingdays.$format?parentTaxonomy=$taxonomyId&isoCode=$language";
            echo "API URL: $url<br>";  // Debug: Toon de API URL
            logMessage("Fetching stock data for taxonomy ID: $taxonomyId, URL: $url");
            $response = $client->get($url);

            // Print de ruwe responsinhoud voor debug-doeleinden
            $responseBody = $response->getBody()->getContents();
            echo "Raw Response Body Length: " . strlen($responseBody) . " bytes<br>";

            // Decodeer de JSON-respons
            $stockData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON Decode Error: " . json_last_error_msg() . "<br>";
                logMessage("JSON Decode Error: " . json_last_error_msg());
            } else {
                echo "Stock Data: " . print_r($stockData, true) . "<br>";  // Debug: Toon de gedecodeerde voorraaddata
                logMessage("Fetched stock data for taxonomy ID: $taxonomyId");
            }

            return $stockData;
        } catch (GuzzleHttp\Exception\ConnectException $e) {
            $attempts++;
            echo "Fout bij poging $attempts: " . $e->getMessage() . "<br>";
            logMessage("Error on attempt $attempts - " . $e->getMessage());
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
            logMessage("Error on attempt $attempts - " . $e->getMessage());
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

// Taxonomy ID voor ELEKTRONICA
$taxonomyId = 19653;

// Haal de voorraadgegevens op voor de specifieke taxonomie
$stockData = fetchStockForTaxonomy($bigBuyClient, $taxonomyId);

// Controleer of het bestand bestaat en of we schrijfrechten hebben
$filePath = 'all_stock_data.json';
if (is_writable($filePath) || !file_exists($filePath)) {
    // Sla de voorraadgegevens op in een JSON file
    $result = file_put_contents($filePath, json_encode($stockData, JSON_PRETTY_PRINT));
    if ($result === false) {
        echo 'Fout bij het opslaan van voorraadgegevens in ' . $filePath . '<br>';
        logMessage('Fout bij het opslaan van voorraadgegevens in ' . $filePath);
    } else {
        echo 'Voorraadgegevens zijn bijgewerkt en opgeslagen in ' . $filePath . '<br>';
        logMessage('Voorraadgegevens zijn bijgewerkt en opgeslagen in ' . $filePath);
    }
} else {
    echo 'Geen schrijfrechten voor ' . $filePath . '<br>';
    logMessage('Geen schrijfrechten voor ' . $filePath);
}
?>
