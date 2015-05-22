<?php

/**
 * WARNING: Please do not publish this file if you hardcode your credentials
 */

// API source location
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/api/src');

// AWS API Key
//define('AWS_ACCESS_KEY_ID', 'XXXXXXXX');
//define('AWS_SECRET_ACCESS_KEY', 'XXXXXXXX');

// Our Merchant ID
//define('MERCHANT_ID', 'XXXXXXXX');
//define('MARKETPLACE_ID', 'XXXXXXXX');
//define('MERCHANT_IDENTIFIER', 'XXXXXXXX');

// We want the US API
//define('SERVICE_URL', 'https://mws.amazonservices.com');

// Email to get debug mail
//define('DEBUG_EMAIL', 'admin@example.com');

$serviceUrl = SERVICE_URL;

// Information about this plugin sent to MWS
define('APPLICATION_NAME', 'Woocommerce Sync');
define('APPLICATION_VERSION', '1.0');

// What we use as the date format
define('DATE_FORMAT', 'Y-m-d\TH:i:s\Z');
