<?php

namespace WCPOS\WooCommercePOS\Vipps;

class AjaxHandler {

	public function __construct() {
		$actions = array( 'create_payment', 'check_status', 'cancel_payment' );

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_wcpos_vipps_' . $action, array( $this, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_wcpos_vipps_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Generate a deterministic token for an order. Same pattern as SumUp/Stripe gateways.
	 */
	public static function generate_token( int $order_id ): string {
		$data = 'wcpos_vipps_' . $order_id . wp_salt( 'nonce' );
		return substr( wp_hash( $data ), 0, 10 );
	}

	/**
	 * Validate the AJAX request and return the order.
	 */
	private function validate_request(): ?\WC_Order {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$token    = sanitize_text_field( $_POST['token'] ?? '' );

		if ( ! $order_id || ! $token ) {
			wp_send_json_error( array( 'message' => 'Missing order ID or token.' ) );
			return null;
		}

		if ( $token !== self::generate_token( $order_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid token.' ) );
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ) );
			return null;
		}

		return $order;
	}

	/**
	 * Get the gateway's Api instance.
	 */
	private function get_api(): ?Api {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways['wcpos_vipps'] ?? null;

		if ( ! $gateway instanceof Gateway ) {
			wp_send_json_error( array( 'message' => 'Gateway not available.' ) );
			return null;
		}

		return $gateway->get_api();
	}

	/**
	 * Build a success response that includes buffered log entries.
	 */
	private function success_with_logs( array $data, int $order_id ): void {
		$data['log_entries'] = Logger::flush( $order_id );
		wp_send_json_success( $data );
	}

	/**
	 * Create a Vipps payment (QR or push).
	 */
	public function ajax_create_payment(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$flow  = sanitize_text_field( $_POST['flow'] ?? 'qr' );
		$phone = sanitize_text_field( $_POST['phone'] ?? '' );

		$reference = 'wcpos-' . $order->get_id() . '-' . time();
		$amount    = array(
			'currency' => $order->get_currency(),
			'value'    => absint( round( $order->get_total() * 100 ) ),
		);

		$params = array(
			'amount'              => $amount,
			'paymentMethod'       => array( 'type' => 'WALLET' ),
			'reference'           => $reference,
			'returnUrl'           => $order->get_checkout_payment_url( true ),
			'paymentDescription'  => sprintf( 'Order #%s', $order->get_order_number() ),
			'customerInteraction' => 'CUSTOMER_PRESENT',
		);

		if ( 'push' === $flow ) {
			if ( empty( $phone ) ) {
				wp_send_json_error( array( 'message' => 'Phone number is required for push flow.' ) );
				return;
			}
			$params['userFlow'] = 'PUSH_MESSAGE';
			$params['customer'] = array( 'phoneNumber' => $phone );
		} else {
			$params['userFlow'] = 'QR';
			$params['qrFormat'] = array(
				'format' => 'IMAGE/PNG',
				'size'   => 250,
			);
		}

		$api = $this->get_api();
		$api->set_order_id( $order->get_id() );
		$result = $api->create_payment( $params );

		if ( ! $result ) {
			Logger::log( 'Failed to create Vipps payment', 'ERROR', $order->get_id() );
			$error_data = array( 'message' => 'Failed to create Vipps payment.' );
			$error_data['log_entries'] = Logger::flush( $order->get_id() );
			wp_send_json_error( $error_data );
			return;
		}

		$order->update_meta_data( '_wcpos_vipps_reference', $reference );
		$order->update_meta_data( '_wcpos_vipps_status', 'CREATED' );
		$order->save();

		$response = array(
			'reference' => $reference,
			'flow'      => $flow,
		);

		if ( 'qr' === $flow && ! empty( $result['redirectUrl'] ) ) {
			$response['qrUrl'] = $result['redirectUrl'];
		}

		Logger::log( "Payment created — flow: {$flow}, ref: {$reference}", 'INFO', $order->get_id() );
		$this->success_with_logs( $response, $order->get_id() );
	}

	/**
	 * Check payment status via Vipps API.
	 */
	public function ajax_check_status(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$reference = $order->get_meta( '_wcpos_vipps_reference' );
		if ( ! $reference ) {
			wp_send_json_error( array( 'message' => 'No payment reference found.' ) );
			return;
		}

		$api = $this->get_api();
		$api->set_order_id( $order->get_id() );
		$result = $api->get_payment( $reference );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'Failed to check payment status.' ) );
			return;
		}

		$state = $result['state'] ?? 'UNKNOWN';
		$order->update_meta_data( '_wcpos_vipps_status', $state );
		$order->save();

		$this->success_with_logs( array( 'state' => $state ), $order->get_id() );
	}

	/**
	 * Cancel a Vipps payment.
	 */
	public function ajax_cancel_payment(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$reference = $order->get_meta( '_wcpos_vipps_reference' );

		if ( $reference && 'CREATED' === $order->get_meta( '_wcpos_vipps_status' ) ) {
			$api = $this->get_api();
			$api->set_order_id( $order->get_id() );
			$api->cancel_payment( $reference );
		}

		$order->update_meta_data( '_wcpos_vipps_status', 'CANCELLED' );
		$order->delete_meta_data( '_wcpos_vipps_reference' );
		$order->save();

		Logger::log( 'Payment cancelled by cashier', 'INFO', $order->get_id() );
		$this->success_with_logs( array( 'message' => 'Payment cancelled.' ), $order->get_id() );
	}
}
