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
	 * Return URL for Vipps WEB_REDIRECT.
	 *
	 * Uses a custom endpoint instead of WooCommerce's checkout-pay URL to avoid
	 * the "order already paid" error when the customer returns from Vipps.
	 */
	private function build_return_url( \WC_Order $order ): string {
		return add_query_arg(
			array(
				'wcpos_vipps_return'    => 1,
				'wcpos_vipps_order_id'  => $order->get_id(),
				'wcpos_vipps_token'     => self::generate_token( $order->get_id() ),
			),
			home_url( '/' )
		);
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
	 * Get the gateway instance.
	 */
	private function get_gateway(): ?Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways['wcpos_vipps'] ?? null;

		return $gateway instanceof Gateway ? $gateway : null;
	}

	/**
	 * Get the gateway's Api instance.
	 */
	private function get_api(): ?Api {
		$gateway = $this->get_gateway();

		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => 'Gateway not available.' ) );
			return null;
		}

		$api = $gateway->get_api();
		if ( ! $api instanceof Api ) {
			wp_send_json_error( array( 'message' => 'API not available.' ) );
			return null;
		}

		return $api;
	}

	/**
	 * Build a success response that includes buffered log entries.
	 */
	private function success_with_logs( array $data, int $order_id ): void {
		$data['log_entries'] = Logger::flush( $order_id );
		wp_send_json_success( $data );
	}

	/**
	 * Normalize a Norwegian phone number to 47XXXXXXXX format.
	 *
	 * Accepts: +47..., 0047..., 47..., or 8-digit local numbers.
	 */
	private function normalize_no_phone( string $phone, int $order_id ): ?string {
		$phone = (string) preg_replace( '/\D+/', '', $phone );

		if ( strpos( $phone, '0047' ) === 0 ) {
			$phone = '47' . substr( $phone, 4 );
		}

		if ( strlen( $phone ) === 8 ) {
			$phone = '47' . $phone;
		}

		if ( strlen( $phone ) !== 10 || strpos( $phone, '47' ) !== 0 ) {
			return null;
		}

		$masked = str_repeat( '*', max( 0, strlen( $phone ) - 4 ) ) . substr( $phone, -4 );
		Logger::log( "Phone normalized: {$masked}", 'DEBUG', $order_id );
		return $phone;
	}

	/**
	 * Redact token values from a URL for safe logging.
	 */
	private function redact_url_token( string $url ): string {
		return (string) preg_replace( '/([?&])token=[^&]*/', '$1token=[redacted]', $url );
	}

	/**
	 * Create a Vipps payment (QR or push).
	 */
	public function ajax_create_payment(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return;
		}

		$lock_key = 'wcpos_vipps_create_lock_' . $order->get_id();
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array(
				'message'     => 'Payment creation already in progress.',
				'log_entries' => Logger::flush( $order->get_id() ),
			) );
			return;
		}
		set_transient( $lock_key, 1, 30 );

		try {
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
				'returnUrl'           => $this->build_return_url( $order ),
				'paymentDescription'  => sprintf( 'Order #%s', $order->get_order_number() ),
				'customerInteraction' => 'CUSTOMER_PRESENT',
			);

			if ( 'push' === $flow ) {
				if ( empty( $phone ) ) {
					delete_transient( $lock_key );
					wp_send_json_error( array( 'message' => 'Phone number is required for push flow.' ) );
					return;
				}

				$normalized = $this->normalize_no_phone( $phone, $order->get_id() );
				if ( ! $normalized ) {
					delete_transient( $lock_key );
					wp_send_json_error( array( 'message' => 'Invalid phone number. Use a Norwegian number, e.g. 4741234567.' ) );
					return;
				}
				$phone = $normalized;

				$gateway      = $this->get_gateway();
				$use_redirect = $gateway && 'redirect' === $gateway->get_option( 'phone_flow', 'push' );

				$params['userFlow'] = $use_redirect ? 'WEB_REDIRECT' : 'PUSH_MESSAGE';
				$params['customer'] = array( 'phoneNumber' => $phone );
			} else {
				$params['userFlow'] = 'QR';
				$params['qrFormat'] = array(
					'format' => 'IMAGE/PNG',
					'size'   => 250,
				);
			}

			$api->set_order_id( $order->get_id() );
			$result = $api->create_payment( $params );

			// If PUSH_MESSAGE failed with a "not allowed" error, save redirect mode
			// to the gateway setting and signal the frontend to retry.
			if ( ! $result && 'push' === $flow && 'PUSH_MESSAGE' === ( $params['userFlow'] ?? '' ) ) {
				$error_title = $api->get_last_error_title();

				$is_push_not_allowed =
					$error_title &&
					false !== stripos( $error_title, 'PUSH_MESSAGE' ) &&
					(
						false !== stripos( $error_title, 'not allowed' ) ||
						false !== stripos( $error_title, 'not enabled' ) ||
						false !== stripos( $error_title, 'not permitted' ) ||
						false !== stripos( $error_title, 'disabled' )
					);

				if ( $is_push_not_allowed ) {
					$gateway = $this->get_gateway();
					if ( ! $gateway ) {
						Logger::log( 'Direct Push not supported — gateway unavailable, using Web Redirect for this request only', 'WARNING', $order->get_id() );
					} elseif ( 'yes' === $gateway->get_option( 'test_mode' ) ) {
						Logger::log( 'Direct Push not supported in test mode — using Web Redirect for this request only', 'INFO', $order->get_id() );
					} else {
						$gateway->update_option( 'phone_flow', 'redirect' );
						Logger::log( 'Direct Push not supported — switched setting to Web Redirect', 'INFO', $order->get_id() );
					}

					delete_transient( $lock_key );
					$this->success_with_logs( array(
						'modeChanged' => true,
						'flow'        => 'redirect',
					), $order->get_id() );
					return;
				}
			}

			if ( ! is_array( $result ) ) {
				Logger::log( 'Failed to create Vipps payment', 'ERROR', $order->get_id() );
				delete_transient( $lock_key );
				wp_send_json_error( array(
					'message'     => 'Failed to create Vipps payment.',
					'log_entries' => Logger::flush( $order->get_id() ),
				) );
				return;
			}

			$response = array(
				'reference' => $reference,
				'flow'      => ( 'WEB_REDIRECT' === $params['userFlow'] ) ? 'redirect' : $flow,
			);

			if ( 'qr' === $flow && ! empty( $result['redirectUrl'] ) ) {
				$response['qrUrl'] = $result['redirectUrl'];
			}

			// Validate redirectUrl before persisting order state.
			if ( 'WEB_REDIRECT' === ( $params['userFlow'] ?? '' ) ) {
				if ( empty( $result['redirectUrl'] ) ) {
					Logger::log( 'WEB_REDIRECT payment missing redirectUrl', 'ERROR', $order->get_id() );
					delete_transient( $lock_key );
					wp_send_json_error( array(
						'message'     => 'Failed to create Vipps redirect payment.',
						'log_entries' => Logger::flush( $order->get_id() ),
					) );
					return;
				}
				$response['redirectUrl'] = $result['redirectUrl'];

				Logger::log(
					'Vipps redirectUrl: ' . $this->redact_url_token( (string) $result['redirectUrl'] ),
					'DEBUG',
					$order->get_id()
				);
			}

			$order->update_meta_data( '_wcpos_vipps_reference', $reference );
			$order->update_meta_data( '_wcpos_vipps_status', 'CREATED' );
			$order->save();

			Logger::log( "Payment created — flow: {$flow}, userFlow: {$params['userFlow']}, ref: {$reference}", 'INFO', $order->get_id() );
			delete_transient( $lock_key );
			$this->success_with_logs( $response, $order->get_id() );

		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Check payment status via Vipps API.
	 */
	public function ajax_check_status(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$reference = (string) $order->get_meta( '_wcpos_vipps_reference' );
		if ( ! $reference ) {
			wp_send_json_error( array( 'message' => 'No payment reference found.' ) );
			return;
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return;
		}

		$api->set_order_id( $order->get_id() );
		$result = $api->get_payment( $reference );

		if ( ! is_array( $result ) ) {
			wp_send_json_error( array(
				'message'     => 'Failed to check payment status.',
				'log_entries' => Logger::flush( $order->get_id() ),
			) );
			return;
		}

		$state = (string) ( $result['state'] ?? 'UNKNOWN' );

		$order->update_meta_data( '_wcpos_vipps_status', $state );
		$order->save();

		$completed = in_array( $state, array( 'CAPTURED', 'AUTHORIZED' ), true );

		$this->success_with_logs( array(
			'state'     => $state,
			'completed' => $completed,
		), $order->get_id() );
	}

	/**
	 * Cancel a Vipps payment.
	 */
	public function ajax_cancel_payment(): void {
		$order = $this->validate_request();
		if ( ! $order ) {
			return;
		}

		$reference = (string) $order->get_meta( '_wcpos_vipps_reference' );

		if ( $reference && 'CREATED' === (string) $order->get_meta( '_wcpos_vipps_status' ) ) {
			$api = $this->get_api();
			if ( $api ) {
				$api->set_order_id( $order->get_id() );
				$api->cancel_payment( $reference );
			}
		}

		$order->update_meta_data( '_wcpos_vipps_status', 'CANCELLED' );
		$order->delete_meta_data( '_wcpos_vipps_reference' );
		$order->save();

		Logger::log( 'Payment cancelled by cashier', 'INFO', $order->get_id() );
		$this->success_with_logs( array( 'message' => 'Payment cancelled.' ), $order->get_id() );
	}
}
