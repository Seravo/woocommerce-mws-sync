=== WooCommerce MWS Sync ===
Contributors: zuige, ottok
Tags: woocommerce, mws, sync, amazon, integration, ecommerce, sku, seravo
Donate link: https://seravo.com/
Requires at least: 4.0.1
Tested up to: 4.9.4
Requires PHP: 5.6.0
Stable tag: trunk
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

This plugin syncronises the inventory between a WooCommerce instance and an Amazon seller account using the MWS API.

== Description ==

This plugin automatically syncronises the inventory between a WooCommerce instance and an Amazon seller account using the MWS API.

Product inventories are syncronised based purely on their SKU values.

Adding inventory from the Amazon store is not currently supported, please refill shop inventory via your WooCommerce shop instead.

API documentation:
https://developer.amazonservices.com/gp/mws/api.html

MWS Scratchpad:
https://mws.amazonservices.com/scratchpad/index.html

Plugin maintained at https://github.com/Seravo/woocommerce-mws-sync
Pull requests welcomed!

== Installation ==

1. Download and activate the plugin.

2. Set up your MWS credentials in your wp-config.php file.
```
// AWS API Key
define('AWS_ACCESS_KEY_ID', 'XXXXXXXX');
define('AWS_SECRET_ACCESS_KEY', 'XXXXXXXX');
// Our Merchant ID
define('MERCHANT_ID', 'XXXXXXXX');
define('MARKETPLACE_ID', 'XXXXXXXX');
define('MERCHANT_IDENTIFIER', 'XXXXXXXX');
// We want the US API
define('SERVICE_URL', 'https://mws.amazonservices.com');
```

3. Prepare your products on Amazon Seller Central

Make sure the products to be synced have an initial inventory value of 0 at Amazon.

Make sure the products you want to sync have the exact same SKU values in
WooCommerce and the Amazon Seller Central. Also, make sure SKU names don't
clash for any products you don't want to syncronise vai this plugin.

4. Installation done! The first sync should happen within the first 30
minutes, as MWS generates the first inventory report.

== Frequently Asked Questions ==

No FAQ section yet.

== Screenshots ==

No screenshots yet.

== Changelog ==

= 1.0.1 =
Typofixes

= 1.0 =
Published to private GitHub repo

== Upgrade Notice ==

-
