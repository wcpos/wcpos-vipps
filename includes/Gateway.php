<?php

namespace WCPOS\WooCommercePOS\Vipps;

class Gateway extends \WC_Payment_Gateway {

	private ?Api $api = null;

	public function __construct() {
		$this->id                 = 'wcpos_vipps';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'Vipps MobilePay', 'wcpos-vipps' );
		$this->method_description = __( 'Accept payments via Vipps MobilePay QR codes and push notifications.', 'wcpos-vipps' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_import_credentials();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'wcpos-vipps' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Vipps MobilePay', 'wcpos-vipps' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'    => __( 'Title', 'wcpos-vipps' ),
				'type'     => 'text',
				'default'  => __( 'Vipps MobilePay', 'wcpos-vipps' ),
				'desc_tip' => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'wcpos-vipps' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Vipps MobilePay via QR code or push notification.', 'wcpos-vipps' ),
			),
			'merchant_serial_number' => array(
				'title' => __( 'Merchant Serial Number', 'wcpos-vipps' ),
				'type'  => 'text',
			),
			'client_id' => array(
				'title' => __( 'Client ID', 'wcpos-vipps' ),
				'type'  => 'password',
			),
			'client_secret' => array(
				'title' => __( 'Client Secret', 'wcpos-vipps' ),
				'type'  => 'password',
			),
			'subscription_key' => array(
				'title' => __( 'Subscription Key', 'wcpos-vipps' ),
				'type'  => 'password',
			),
			'auto_capture' => array(
				'title'   => __( 'Auto Capture', 'wcpos-vipps' ),
				'type'    => 'checkbox',
				'label'   => __( 'Automatically capture payments after authorization', 'wcpos-vipps' ),
				'default' => 'yes',
			),
			'test_mode' => array(
				'title'   => __( 'Test Mode', 'wcpos-vipps' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable test mode (apitest.vipps.no)', 'wcpos-vipps' ),
				'default' => 'no',
			),
			'test_merchant_serial_number' => array(
				'title' => __( 'Test Merchant Serial Number', 'wcpos-vipps' ),
				'type'  => 'text',
			),
			'test_client_id' => array(
				'title' => __( 'Test Client ID', 'wcpos-vipps' ),
				'type'  => 'password',
			),
			'test_client_secret' => array(
				'title' => __( 'Test Client Secret', 'wcpos-vipps' ),
				'type'  => 'password',
			),
			'test_subscription_key' => array(
				'title' => __( 'Test Subscription Key', 'wcpos-vipps' ),
				'type'  => 'password',
			),
		);
	}

	/**
	 * Import credentials from the official Vipps WooCommerce plugin on first load.
	 */
	private function maybe_import_credentials(): void {
		if ( $this->get_option( 'client_id' ) || $this->get_option( '_imported_credentials' ) ) {
			return;
		}

		$vipps_settings = get_option( 'woocommerce_vipps_settings', array() );
		if ( empty( $vipps_settings ) ) {
			return;
		}

		$map = array(
			'merchantSerialNumber'        => 'merchant_serial_number',
			'clientId'                    => 'client_id',
			'secret'                      => 'client_secret',
			'Ocp_Apim_Key_eCommerce'      => 'subscription_key',
			'merchantSerialNumber_test'   => 'test_merchant_serial_number',
			'clientId_test'               => 'test_client_id',
			'secret_test'                 => 'test_client_secret',
			'Ocp_Apim_Key_eCommerce_test' => 'test_subscription_key',
		);

		foreach ( $map as $source => $target ) {
			if ( ! empty( $vipps_settings[ $source ] ) ) {
				$this->update_option( $target, $vipps_settings[ $source ] );
			}
		}

		$this->update_option( '_imported_credentials', 'yes' );

		if ( ( $vipps_settings['developermode'] ?? '' ) === 'yes'
			&& ( $vipps_settings['testmode'] ?? '' ) === 'yes' ) {
			$this->update_option( 'test_mode', 'yes' );
		}

		Logger::log( 'Imported credentials from official Vipps plugin' );
	}

	/**
	 * Get an Api instance with the current credentials.
	 */
	public function get_api(): Api {
		if ( null === $this->api ) {
			$test_mode = 'yes' === $this->get_option( 'test_mode' );
			$prefix    = $test_mode ? 'test_' : '';

			$this->api = new Api(
				$this->get_option( $prefix . 'client_id' ),
				$this->get_option( $prefix . 'client_secret' ),
				$this->get_option( $prefix . 'subscription_key' ),
				$this->get_option( $prefix . 'merchant_serial_number' ),
				$test_mode
			);
		}
		return $this->api;
	}

	/**
	 * Only available when credentials are configured.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		$prefix = 'yes' === $this->get_option( 'test_mode' ) ? 'test_' : '';

		return ! empty( $this->get_option( $prefix . 'client_id' ) )
			&& ! empty( $this->get_option( $prefix . 'client_secret' ) )
			&& ! empty( $this->get_option( $prefix . 'subscription_key' ) )
			&& ! empty( $this->get_option( $prefix . 'merchant_serial_number' ) );
	}

	/**
	 * Render payment fields. Full QR/push interface only on order-pay page.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wpautop( wptexturize( $this->description ) );
		}

		global $wp;
		if ( ! isset( $wp->query_vars['order-pay'] ) ) {
			return;
		}

		?>
		<div id="wcpos-vipps-root"></div>
		<noscript><?php esc_html_e( 'JavaScript is required for Vipps MobilePay payments.', 'wcpos-vipps' ); ?></noscript>
		<?php
	}

	/**
	 * Process the payment. On first call from regular checkout, redirects to order-pay.
	 * On order-pay submission after AJAX payment, completes the order.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'wcpos-vipps' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$status    = $order->get_meta( '_wcpos_vipps_status' );
		$reference = $order->get_meta( '_wcpos_vipps_reference' );

		// Payment not yet completed — redirect to order-pay page
		if ( 'AUTHORIZED' !== $status ) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		}

		// Auto-capture if enabled
		if ( 'yes' === $this->get_option( 'auto_capture' ) ) {
			$amount = array(
				'currency' => $order->get_currency(),
				'value'    => absint( round( $order->get_total() * 100 ) ),
			);
			$this->get_api()->capture_payment( $reference, $amount );
		}

		$order->payment_complete( $reference );
		$order->add_order_note(
			sprintf( __( 'Vipps MobilePay payment completed. Reference: %s', 'wcpos-vipps' ), $reference )
		);

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process refund via Vipps API.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order     = wc_get_order( $order_id );
		$reference = $order->get_meta( '_wcpos_vipps_reference' );

		if ( ! $reference ) {
			return new \WP_Error( 'missing_reference', __( 'No Vipps payment reference found.', 'wcpos-vipps' ) );
		}

		$refund_amount = array(
			'currency' => $order->get_currency(),
			'value'    => absint( round( $amount * 100 ) ),
		);

		$result = $this->get_api()->refund_payment( $reference, $refund_amount );

		if ( null === $result ) {
			return new \WP_Error( 'refund_failed', __( 'Vipps refund request failed.', 'wcpos-vipps' ) );
		}

		$order->add_order_note(
			sprintf(
				__( 'Vipps MobilePay refund of %s processed. Reason: %s', 'wcpos-vipps' ),
				wc_price( $amount ),
				$reason
			)
		);

		return true;
	}

	/**
	 * Enqueue frontend payment scripts on the order-pay page.
	 */
	public function enqueue_payment_scripts(): void {
		global $wp;

		if ( ! isset( $wp->query_vars['order-pay'] ) ) {
			return;
		}

		$order_id = absint( $wp->query_vars['order-pay'] );

		wp_enqueue_style(
			'wcpos-vipps-payment',
			WCPOS_VIPPS_PLUGIN_URL . 'assets/css/payment.css',
			array(),
			WCPOS_VIPPS_VERSION
		);

		wp_enqueue_script(
			'wcpos-vipps-payment',
			WCPOS_VIPPS_PLUGIN_URL . 'assets/dist/payment.js',
			array(),
			WCPOS_VIPPS_VERSION,
			true
		);

		wp_localize_script( 'wcpos-vipps-payment', 'wcposVippsData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'orderId' => $order_id,
			'token'   => AjaxHandler::generate_token( $order_id ),
			'strings' => array(
				'generatingQr'      => __( 'Generating QR code...', 'wcpos-vipps' ),
				'sendingPush'       => __( 'Sending payment request...', 'wcpos-vipps' ),
				'waitingForPayment' => __( 'Waiting for payment...', 'wcpos-vipps' ),
				'paymentSuccess'    => __( 'Payment successful!', 'wcpos-vipps' ),
				'paymentFailed'     => __( 'Payment failed. Please try again.', 'wcpos-vipps' ),
				'paymentCancelled'  => __( 'Payment cancelled.', 'wcpos-vipps' ),
				'paymentExpired'    => __( 'Payment expired. Please try again.', 'wcpos-vipps' ),
				'networkError'      => __( 'Network error. Please check your connection.', 'wcpos-vipps' ),
				'phoneRequired'     => __( 'Please enter a phone number.', 'wcpos-vipps' ),
				'phoneLabel'        => __( 'Phone number (optional)', 'wcpos-vipps' ),
				'phonePlaceholder'  => __( 'e.g. 4712345678', 'wcpos-vipps' ),
			),
		) );
	}
}
