<?php
/**
 * Plugin Name: WooCommerce MWS sync
 * Version: 1.0.2
 * Plugin URI: https://github.com/Seravo/woocommerce-mws-sync
 * Description: This plugin syncs product inventories between Woocommerce and Amazon
 * Author: Seravo
 * Author URI: https://seravo.com/
 * License: GPL v3 or later
 *
 * WC requires at least: 3.0
 * WC tested up to: 3.2
 *
 * Copyright: © 2015-2018 Seravo Oy
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 3, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


/*
 * Schedule the sync action to be done every 15 mins via WP-Cron
 */
add_action( 'init', 'woo_mws_setup_schedule' );
function woo_mws_setup_schedule() {
  //wp_clear_scheduled_hook ( 'woo_mws_sync_data' );
  if ( defined( 'AWS_ACCESS_KEY_ID' ) && ! wp_next_scheduled( 'woo_mws_sync_data' ) ) {
    // schedule sync for every 15 minutes
    wp_schedule_event( time(), '*/15', 'woo_mws_sync_data');

    // also set up scheduled inventory reports on mws
    woo_mws_schedule_reports();
  }
}


/*
 * Define Once 15 mins interval
 */
add_filter('cron_schedules', 'new_interval');
function new_interval($interval) {
    $interval['*/15'] = array( 'interval' => 15 * 60, 'display' => 'Once 15 minutes' );
    return $interval;
}

add_action('woo_mws_sync_data', 'woo_mws_do_data_sync');
function woo_mws_do_data_sync() {

  // helps debug
  $debug = '';

  // include MWS PHP API
  require_once '.config.inc.php';
  require_once 'api/src/MarketplaceWebService/Client.php';
  require_once 'api/src/MarketplaceWebService/Model.php';
  require_once 'api/src/MarketplaceWebService/Model/GetReportListRequest.php';
  require_once 'api/src/MarketplaceWebService/Model/GetReportListResponse.php';
  require_once 'api/src/MarketplaceWebService/Model/ResponseHeaderMetadata.php';
  require_once 'api/src/MarketplaceWebService/Model/TypeList.php';
  require_once 'api/src/MarketplaceWebService/Model/GetReportRequest.php';
  require_once 'api/src/MarketplaceWebService/Model/SubmitFeedRequest.php';
  require_once 'api/src/MarketplaceWebService/Model/IdList.php';

  // define API Client
  global $service;

  $service = new MarketplaceWebService_Client(
    AWS_ACCESS_KEY_ID,
    AWS_SECRET_ACCESS_KEY,
    array(
      'ServiceURL' => $serviceUrl,
      'ProxyHost' => null,
      'ProxyPort' => -1,
      'MaxErrorRetry' => 3,
    ),
    APPLICATION_NAME,
    APPLICATION_VERSION
  );


  // get newest report ID
  $mws_report_id = _get_newest_report_id();

  // we only want to update local stock values if the inventory has updated
  if (get_option('amazon_inventory_id') && $mws_report_id != get_option('amazon_inventory_id')) {

    // get inventory from amazon
    $new_mws_inventory = _get_amazon_inventory();
    $old_mws_inventory = get_option('amazon_inventory');

    // get woocommerce inventory for corresponding skus
    $woocommerce_inventory = _get_woocommerce_inventory( array_keys( $new_mws_inventory ) );

    // only handle the products found in woocommerce
    $new_mws_inventory = array_intersect_key($new_mws_inventory, $woocommerce_inventory);

    // update woocommerce inventory with the diff from two previous amazon inventories
    foreach ($new_mws_inventory as $sku => $quantity) {

      $amazon = intval( $quantity );
      $shop = intval( $old_mws_inventory[$sku] );

      // how much the inventory has changed
      $change = $amazon - $shop;

      if ($change != 0) {
        // quantity has changed for this item
        // we assume it's a purchase because we never add stock through amazon
        $debug .= "Discrepancy detected for SKU $sku. Shop qty: $shop, Amazon qty: $amazon, Change: $change\n";

        // only accept negative change for purchases
        if ( 0 > $change) {
          if ($product = _woocommerce_get_product_by_sku($sku)) {
            // update value
            $product->set_stock( $woocommerce_inventory[$sku] + $change );
            $debug .= "Decreased local stock for product $sku by " . (-$change) . "\n";
          }
        } else if ( 0 < $change) {
          // HOW DID THIS HAPPEN??
          $debug .= "WARNING: Amazon stock was increased unexpectedly for SKU $sku\n";
        }
      }
    }

    // update mws with woocommerce stocks
    $new_mws_inventory = _get_woocommerce_inventory( array_keys( $new_mws_inventory ) );

    // only send the changed values
    $mws_inventory_changed = array_diff_assoc($new_mws_inventory, $old_mws_inventory);
    //$debug .= print_r($new_mws_inventory_changed, true) . "\n";
    //$mws_inventory_changed = $new_mws_inventory;

    if ( !empty( $mws_inventory_changed ) ) {
      // sync the inventories
      $debug .= "Updating Amazon inventory with current Woocommerce state...\n";
      $update = _update_amazon_inventory( $mws_inventory_changed, $debug );
      if ($update) {
        $debug .= print_r($mws_inventory_changed, true) . "\n";
        $debug .= "Update ID: $update";
      }
      else {
        $debug .= "ERROR: MWS update failed!";
      }
    }

    //print_r($new_mws_inventory);

    // save latest inventory to DB for comparison
    update_option( 'amazon_inventory', $new_mws_inventory );
    update_option( 'amazon_inventory_id', $mws_report_id );


  }

  else {
    echo "WARNING: MWS report ID hasn't changed from last time. Aborting sync. Maybe MWS API is being slow or you're running the script too often?\n";
    echo "MWS Report ID: $mws_report_id";
  }

  // send debug mail
  if ( defined('DEBUG_EMAIL') && !empty($debug) )
    wp_mail(DEBUG_EMAIL, 'MWS Integration Debug', $debug);

  // kill execution if called from ?do_sync
  if (isset($_GET['mws_do_sync'])) {
    echo "<h1>MWS Integration Debug:</h1><pre>$debug</pre>";
    die();
  }
}

function _get_woocommerce_inventory( $skus ) {

  // get woocommerce inventory for the corresponding items
  $woocommerce_inventory = array();

  foreach ($skus as $sku) {
    $product = _woocommerce_get_product_by_sku( $sku );
    if ( $product ) {
      $sku = strval( $sku );
      $inventory = intval( $product->get_stock_quantity() );
      $woocommerce_inventory[$sku] = $inventory;
    }
    else {
      //print_r( "Notice: Product $sku found in Amazon but not in Woocommerce\n" );
    }
  }
  return $woocommerce_inventory;
}

function _get_amazon_inventory() {

  return _get_inventory_from_report( _get_newest_report_id() );
}

function _get_newest_report_id() {

  global $service;
  static $report_id; // this doesn't change during request so we memoize it

  // memoize the output of this function
  if (isset($report_id)) {
    return $report_id;
  }

  // start request
  $request = new MarketplaceWebService_Model_GetReportListRequest();

  // set our merchant ID
  $request->setMerchant( MERCHANT_ID );

  // we only want reports of type _GET_FLAT_FILE_OPEN_LISTINGS_DATA_
  $typelist = new MarketplaceWebService_Model_TypeList();
  $request->setReportTypeList( $typelist->withType('_GET_FLAT_FILE_OPEN_LISTINGS_DATA_') );

  // get response
  $response = $service->getReportList( $request );

  if ($response->isSetGetReportListResult()) {

    $getReportListResult = $response->getGetReportListResult();
    $reportInfoList = $getReportListResult->getReportInfoList();

    // return newest report ID
    $report_id = $reportInfoList[0]->getReportId();
    return $report_id;
  }

  return false;
}

function _get_inventory_from_report( $report_id ) {

  global $service;

  $request = new MarketplaceWebService_Model_GetReportRequest();
  $request->setMerchant(MERCHANT_ID);
  $request->setReport(@fopen('php://memory', 'rw+'));
  $request->setReportId( $report_id );

  $response = $service->getReport($request);

  if ($response->isSetGetReportResult())
    $report = stream_get_contents($request->getReport());

  $rows = explode( "\n", trim($report) );

  // remove header row
  array_shift( $rows );

  $report = array();
  foreach ($rows as $row) {
     $cols = explode("\t", $row);
     $sku = strval( $cols[0] );
     $inventory = intval( $cols[3] );
     $report[$sku] = $inventory;
  }

  return $report;
}

function _update_amazon_inventory($inventory) {

  if (empty($inventory))
    return false;

  global $service;

  $counter = 0;

  ob_start();
  echo '<'; // Avoid having <?xml in file as it can trigger PHP syntax error
?>?xml version="1.0" encoding="UTF-8"?>
<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
  <Header>
    <DocumentVersion>1.01</DocumentVersion>
    <MerchantIdentifier><?php echo MERCHANT_IDENTIFIER; ?></MerchantIdentifier>
  </Header>
  <MessageType>Inventory</MessageType>
  <?php foreach($inventory as $sku => $quantity) : ?>
  <Message>
    <MessageID><?php echo ++$counter; ?></MessageID>
    <OperationType>Update</OperationType>
    <Inventory>
      <SKU><?php echo $sku; ?></SKU>
      <Quantity><?php echo $quantity; ?></Quantity>
    </Inventory>
  </Message>
  <?php endforeach; ?>
</AmazonEnvelope>
<?php

  $feed = ob_get_clean();

  $feedHandle = @fopen('php://memory', 'rw+');
  fwrite($feedHandle, $feed);
  rewind($feedHandle);

  $request = new MarketplaceWebService_Model_SubmitFeedRequest();
  $request->setMerchant(MERCHANT_ID);
  $request->setMarketplaceIdList(array("Id" => array(MARKETPLACE_ID)));
  $request->setFeedType('_POST_INVENTORY_AVAILABILITY_DATA_');
  $request->setContentMd5(base64_encode(md5(stream_get_contents($feedHandle), true)));
  rewind($feedHandle);

  $request->setPurgeAndReplace(false);
  $request->setFeedContent($feedHandle);

  rewind($feedHandle);
  $response = $service->submitFeed($request);
  @fclose($feedHandle);

  if ($response->isSetSubmitFeedResult()) {
    $submitFeedResult = $response->getSubmitFeedResult();
    if ($submitFeedResult->isSetFeedSubmissionInfo()) {
      $feedSubmissionInfo = $submitFeedResult->getFeedSubmissionInfo();
      if ($feedSubmissionInfo->isSetFeedSubmissionId()) {
        return $feedSubmissionInfo->getFeedSubmissionId();
      }
    }
  }

  return false;
}

/*
 * This returns WC_Product via sku
 */
function _woocommerce_get_product_by_sku( $sku ) {
  global $wpdb;
  $product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) );
  if ( $product_id ) return new WC_Product( $product_id );
  return null;
}


/*
 * Run sync via GET parameters
 */
if (isset($_GET['mws_do_sync'])) {
  add_action('init', 'woo_mws_do_data_sync');
}


/*
 * Set up scheduled inventory reports on MWS
 */
function woo_mws_schedule_reports() {

  // include MWS PHP API
  require_once '.config.inc.php';
  require_once 'api/src/MarketplaceWebService/Client.php';
  require_once 'api/src/MarketplaceWebService/Model.php';
  require_once 'api/src/MarketplaceWebService/Model/ManageReportScheduleRequest.php';

  // define API Client
  global $service;

  $service = new MarketplaceWebService_Client(
    AWS_ACCESS_KEY_ID,
    AWS_SECRET_ACCESS_KEY,
    array(
     'ServiceURL' => $serviceUrl,
     'ProxyHost' => null,
     'ProxyPort' => -1,
     'MaxErrorRetry' => 3,
    ),
    APPLICATION_NAME,
    APPLICATION_VERSION
  );

  $request = new MarketplaceWebService_Model_ManageReportScheduleRequest();

  $request->setMerchant(MERCHANT_ID);
  $request->setReportType('_GET_FLAT_FILE_OPEN_LISTINGS_DATA_');
  $request->setSchedule('_15_MINUTES_'); // lowest value mws allows
  $request->setScheduleDate(new DateTime('now', new DateTimeZone('UTC')));

  $response = $service->manageReportSchedule($request);

  return $response->isSetManageReportScheduleResult();
}

if (isset($_GET['mws_schedule'])) {
  echo woo_mws_schedule_reports() ? 'Schedule successful!' : 'Schedule failed. Check your auth credentials.';
  die();
}
