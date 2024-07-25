<?php
// Toon foutmeldingen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('config.php');

function fetchProductTaxonomiesFromBigBuy($client, $language = 'nl', $format = 'json') {
    try {
        $response = $client->get("catalog/taxonomies.$format?isoCode=$language");
        return json_decode($response->getBody(), true);
    } catch (Exception $e) {
        echo 'Exception when calling BigBuy API: ', $e->getMessage(), PHP_EOL;
        return [];
    }
}

// Maak een BigBuy client aan
$bigBuyClient = getBigBuyClient();

// Haal producttaxonomieën op van BigBuy
$taxonomies = fetchProductTaxonomiesFromBigBuy($bigBuyClient);

// Sla de taxonomieën op in een JSON-bestand
$filePath = 'product_taxonomies.json';
file_put_contents($filePath, json_encode($taxonomies, JSON_PRETTY_PRINT));

// Toon het resultaat
echo 'Taxonomiegegevens zijn opgeslagen in ' . $filePath;
?>
