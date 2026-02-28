<?php

namespace WCPOS\WooCommercePOS\Vipps;

class Api {

	private string $client_id;
	private string $client_secret;
	private string $subscription_key;
	private string $merchant_serial_number;
	private string $base_url;
	private ?string $access_token = null;

	public function __construct(
		string $client_id,
		string $client_secret,
		string $subscription_key,
		string $merchant_serial_number,
		bool $test_mode = false
	) {
		$this->client_id              = $client_id;
		$this->client_secret          = $client_secret;
		$this->subscription_key       = $subscription_key;
		$this->merchant_serial_number = $merchant_serial_number;
		$this->base_url               = $test_mode ? 'https://apitest.vipps.no' : 'https://api.vipps.no';
	}

	/**
	 * Get access token from Vipps. Caches in transient for 1 hour.
	 */
	public function get_access_token(): ?string {
		$cache_key = 'wcpos_vipps_token_' . md5( $this->client_id . $this->merchant_serial_number );
		$cached    = get_transient( $cache_key );

		if ( $cached ) {
			$this->access_token = $cached;
			return $cached;
		}

		$response = wp_remote_post( $this->base_url . '/accesstoken/get', array(
			'headers' => array(
				'client_id'                 => $this->client_id,
				'client_secret'             => $this->client_secret,
				'Ocp-Apim-Subscription-Key' => $this->subscription_key,
				'Merchant-Serial-Number'    => $this->merchant_serial_number,
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Access token error: ' . $response->get_error_message() );
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body['access_token'] ) ) {
			Logger::log( 'Access token failed (HTTP ' . $status . '): ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		$this->access_token = $body['access_token'];
		set_transient( $cache_key, $this->access_token, HOUR_IN_SECONDS );

		return $this->access_token;
	}

	/**
	 * Clear cached access token.
	 */
	public function clear_token_cache(): void {
		$cache_key = 'wcpos_vipps_token_' . md5( $this->client_id . $this->merchant_serial_number );
		delete_transient( $cache_key );
		$this->access_token = null;
	}

	/**
	 * Create a payment.
	 */
	public function create_payment( array $params ): ?array {
		return $this->request( 'POST', '/epayment/v1/payments', $params );
	}

	/**
	 * Get payment details by reference.
	 */
	public function get_payment( string $reference ): ?array {
		return $this->request( 'GET', '/epayment/v1/payments/' . $reference );
	}

	/**
	 * Capture an authorized payment.
	 */
	public function capture_payment( string $reference, array $amount ): ?array {
		return $this->request( 'POST', '/epayment/v1/payments/' . $reference . '/capture', array(
			'modificationAmount' => $amount,
		) );
	}

	/**
	 * Cancel a payment.
	 */
	public function cancel_payment( string $reference ): ?array {
		return $this->request( 'POST', '/epayment/v1/payments/' . $reference . '/cancel' );
	}

	/**
	 * Refund a captured payment.
	 */
	public function refund_payment( string $reference, array $amount ): ?array {
		return $this->request( 'POST', '/epayment/v1/payments/' . $reference . '/refund', array(
			'modificationAmount' => $amount,
		) );
	}

	/**
	 * Make an authenticated API request.
	 */
	private function request( string $method, string $endpoint, ?array $data = null ): ?array {
		if ( ! $this->access_token && ! $this->get_access_token() ) {
			return null;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization'               => 'Bearer ' . $this->access_token,
				'Ocp-Apim-Subscription-Key'   => $this->subscription_key,
				'Merchant-Serial-Number'      => $this->merchant_serial_number,
				'Content-Type'                => 'application/json',
				'Idempotency-Key'             => wp_generate_uuid4(),
				'Vipps-System-Name'           => 'WooCommerce POS',
				'Vipps-System-Plugin-Name'    => 'wcpos-vipps',
				'Vipps-System-Plugin-Version' => WCPOS_VIPPS_VERSION,
			),
			'timeout' => 30,
		);

		if ( null !== $data ) {
			$args['body'] = wp_json_encode( $data );
		}

		$url      = $this->base_url . $endpoint;
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::log( "API error [{$method} {$endpoint}]: " . $response->get_error_message() );
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			Logger::log( "API error [{$method} {$endpoint}] HTTP {$status}: " . wp_remote_retrieve_body( $response ) );
			return null;
		}

		return $body ?: array();
	}
}
