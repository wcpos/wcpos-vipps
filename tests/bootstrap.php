<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Polyfill str_contains for PHP 7.4.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}

// Define WP constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WCPOS_VIPPS_VERSION' ) ) {
	define( 'WCPOS_VIPPS_VERSION', '0.0.1' );
}
if ( ! defined( 'WCPOS_VIPPS_PLUGIN_DIR' ) ) {
	define( 'WCPOS_VIPPS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WCPOS_VIPPS_PLUGIN_URL' ) ) {
	define( 'WCPOS_VIPPS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wcpos-vipps/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Minimal WC_Payment_Gateway stub so Gateway can extend it.
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id;
		public $icon;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports = array();
		public $title;
		public $description;
		public $form_fields = array();
		protected $settings = array();

		public function __construct() {}

		public function init_form_fields() {}

		public function init_settings() {
			// Load settings from the stored array.
			foreach ( $this->form_fields as $key => $field ) {
				$this->settings[ $key ] = $field['default'] ?? '';
			}
		}

		public function get_option( $key, $default = '' ) {
			return $this->settings[ $key ] ?? $default;
		}

		public function update_option( $key, $value = '' ) {
			$this->settings[ $key ] = $value;
		}

		public function is_available() {
			return 'yes' === $this->get_option( 'enabled' );
		}

		public function get_return_url( $order = null ) {
			return 'http://example.com/thank-you/';
		}
	}
}

// Minimal WP_Error stub.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
