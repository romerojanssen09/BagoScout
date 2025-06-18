<?php
/**
 * Central configuration file for API keys
 * This file stores all API keys in one place for easy management
 */

// Mapbox API key
define('MAPBOX_API_KEY', 'pk.eyJ1Ijoicm9tZXJvamFuc3NlbjA5IiwiYSI6ImNsenFqOHVqdTFrcGoyaW44MTJqMm11ZDUifQ.KEBrpTsF6sUiUSKxhoN_VQ');

// Ably API key
define('ABLY_API_KEY', 'wAsqVg.n1Cj3Q:ljbmcQu_KaVT-VCdxg5Oxg17fwf-7vZVwWCGUEk_Ei4');

/**
 * Get API key by name
 * 
 * @param string $key_name The name of the API key to retrieve
 * @return string|null The API key value or null if not found
 */
function getApiKey($key_name) {
    switch ($key_name) {
        case 'mapbox':
            return MAPBOX_API_KEY;
        case 'ably':
            return ABLY_API_KEY;
        default:
            return null;
    }
}
?> 