<?php
/**
 * Plugin Name: WooCommerce POS - Vipps MobilePay
 * Plugin URI: https://github.com/wcpos/wcpos-vipps
 * Description: Vipps MobilePay payment gateway with QR code and push notification support.
 * Version: 0.0.1
 * Author: kilbot
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wcpos-vipps
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPOS_VIPPS_VERSION', '0.0.1' );
define( 'WCPOS_VIPPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCPOS_VIPPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    $autoloader = WCPOS_VIPPS_PLUGIN_DIR . 'vendor/autoload.php';
    if ( file_exists( $autoloader ) ) {
        require_once $autoloader;
    }

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = \WCPOS\WooCommercePOS\Vipps\Gateway::class;
        return $gateways;
    } );

    new \WCPOS\WooCommercePOS\Vipps\AjaxHandler();
} );
