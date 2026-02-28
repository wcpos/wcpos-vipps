<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WP constants used by the plugin.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WCPOS_VIPPS_VERSION', '0.0.1' );
define( 'WCPOS_VIPPS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WCPOS_VIPPS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wcpos-vipps/' );
define( 'HOUR_IN_SECONDS', 3600 );

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
