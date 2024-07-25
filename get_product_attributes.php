<?php
// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

function fetchAttributes($client, $language = 'nl', $format = 'json', $taxonomyId, $retryCount = 3) {
    $attempts = 0;
    $waitTime = 10;  // Verhoog de wachttijd tussen pogingen tot 10 seconden
    while ($attempts < $retryCount) {
        try {
            $url = "catalog/attributes.$format?isoCode=$language&parentTaxonomy=$taxonomyId";
            echo "API URL: $url<br>";  // Debug: Toon de API URL
            $response = $client->get($url);

            // Print de ruwe responsinhoud voor debug-doeleinden
            $responseBody = $response->getBody()->getContents();
            echo "Raw Response Body Length: " . strlen($responseBody) . " bytes<br>";

            // Decodeer de JSON-respons
            $attributes = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON Decode Error: " . json_last_error_msg() . "<br>";
            } else {
                echo "Attributes Data: " . print_r($attributes, true) . "<br>";  // Debug: Toon de gedecodeerde attribuutdata
            }

            return $attributes;
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

// Haal de attribuutgegevens op van BigBuy
$attributeData = fetchAttributes($bigBuyClient, 'nl', 'json', $taxonomyId);

// Sla de attribuutgegevens op in een JSON-bestand
$filePath = 'attributes.json';
file_put_contents($filePath, json_encode($attributeData, JSON_PRETTY_PRINT));

// Toon het resultaat
echo 'Attribuutgegevens zijn opgeslagen in ' . $filePath . '<br>';
echo '</div>';
?>
