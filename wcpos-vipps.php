<?php
/**
 * Plugin Name: WCPOS Vipps MobilePay
 * Plugin URI: https://github.com/wcpos/wcpos-vipps
 * Description: Vipps MobilePay payment gateway with QR code and push notification support.
 * Version: 0.3.1
 * Author: kilbot
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wcpos-vipps
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

$wcpos_vipps_data    = get_file_data( __FILE__, array( 'version' => 'Version' ) );
$wcpos_vipps_version = ! empty( $wcpos_vipps_data['version'] ) ? trim( $wcpos_vipps_data['version'] ) : '0.0.0';
define( 'WCPOS_VIPPS_VERSION', $wcpos_vipps_version );
unset( $wcpos_vipps_version, $wcpos_vipps_data );
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

    if ( is_admin() ) {
        new \WCPOS\WooCommercePOS\Vipps\AdminNotice();
    }
} );
