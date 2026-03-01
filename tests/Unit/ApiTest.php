<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\Api;

class ApiTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private string $client_id           = 'test_client_id';
	private string $client_secret       = 'test_client_secret';
	private string $subscription_key    = 'test_sub_key';
	private string $merchant_serial     = 'test_msn';
	private string $fake_token          = 'fake-access-token-abc123';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_logger();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Stub Logger and utility WP functions so code under test works.
	 */
	private function stub_logger(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )->zeroOrMoreTimes();

		Functions\stubs( array(
			'apply_filters'  => true,
			'wc_get_logger'  => $mock_logger,
			'wp_json_encode' => 'json_encode',
		) );
	}

	/**
	 * Build an Api instance (production mode by default).
	 */
	private function make_api( bool $test_mode = false ): Api {
		return new Api(
			$this->client_id,
			$this->client_secret,
			$this->subscription_key,
			$this->merchant_serial,
			$test_mode
		);
	}

	/**
	 * Expected cache key the Api class will compute.
	 */
	private function cache_key(): string {
		return 'wcpos_vipps_token_' . md5( $this->client_id . $this->merchant_serial );
	}

	/**
	 * Pre-warm the token on an Api instance so payment methods
	 * don't need to fetch a token first.
	 */
	private function api_with_token(): Api {
		Functions\expect( 'get_transient' )
			->once()
			->with( $this->cache_key() )
			->andReturn( $this->fake_token );

		$api = $this->make_api();
		$api->get_access_token();

		return $api;
	}

	/**
	 * Build a fake HTTP response array.
	 */
	private function http_response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => json_encode( $body ),
		);
	}

	// ---------------------------------------------------------------
	// Token tests
	// ---------------------------------------------------------------

	public function test_get_access_token_returns_cached_token(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( $this->cache_key() )
			->andReturn( $this->fake_token );

		// wp_remote_post should never be called.
		Functions\expect( 'wp_remote_post' )->never();

		$api   = $this->make_api();
		$token = $api->get_access_token();

		$this->assertSame( $this->fake_token, $token );
	}

	public function test_get_access_token_fetches_and_caches_new_token(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( $this->cache_key() )
			->andReturn( false );

		$response_body = array( 'access_token' => $this->fake_token );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		Functions\expect( 'set_transient' )
			->once()
			->with( $this->cache_key(), $this->fake_token, HOUR_IN_SECONDS );

		$api   = $this->make_api();
		$token = $api->get_access_token();

		$this->assertSame( $this->fake_token, $token );
	}

	public function test_get_access_token_returns_null_on_wp_error(): void {
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( false );

		$wp_error = new \WP_Error( 'http_error', 'Connection refused' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( true );

		$api   = $this->make_api();
		$token = $api->get_access_token();

		$this->assertNull( $token );
	}

	public function test_get_access_token_returns_null_on_non_200(): void {
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $this->http_response( 401, array( 'error' => 'Unauthorized' ) ) );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 401 );

		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '{"error":"Unauthorized"}' );

		$api   = $this->make_api();
		$token = $api->get_access_token();

		$this->assertNull( $token );
	}

	public function test_get_access_token_uses_test_url(): void {
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				\Mockery::on( function ( $url ) {
					return str_contains( $url, 'apitest.vipps.no' );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, array( 'access_token' => $this->fake_token ) ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( array( 'access_token' => $this->fake_token ) ) );
		Functions\expect( 'set_transient' )->once();

		$api   = $this->make_api( true );
		$token = $api->get_access_token();

		$this->assertSame( $this->fake_token, $token );
	}

	public function test_clear_token_cache_deletes_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( $this->cache_key() );

		$api = $this->make_api();
		$api->clear_token_cache();

		// If we got here without exceptions, the expectation was met.
		$this->assertTrue( true );
	}

	// ---------------------------------------------------------------
	// Payment operation tests
	// ---------------------------------------------------------------

	public function test_create_payment_sends_correct_params(): void {
		$api = $this->api_with_token();

		$payment_data = array( 'amount' => array( 'value' => 1000, 'currency' => 'NOK' ) );
		$response_body = array( 'reference' => 'ref-123' );

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::on( function ( $url ) {
					return str_contains( $url, '/epayment/v1/payments' )
						&& ! str_contains( $url, '/capture' )
						&& ! str_contains( $url, '/cancel' )
						&& ! str_contains( $url, '/refund' );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->create_payment( $payment_data );

		$this->assertSame( $response_body, $result );
	}

	public function test_create_payment_returns_null_on_failure(): void {
		$api = $this->api_with_token();

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( $this->http_response( 400, array( 'error' => 'Bad Request' ) ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 400 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '{"error":"Bad Request"}' );

		$result = $api->create_payment( array( 'amount' => 1000 ) );

		$this->assertNull( $result );
	}

	public function test_get_payment_returns_status(): void {
		$api = $this->api_with_token();

		$reference     = 'ref-abc-123';
		$response_body = array( 'state' => 'AUTHORIZED', 'reference' => $reference );

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::on( function ( $url ) use ( $reference ) {
					return str_contains( $url, '/epayment/v1/payments/' . $reference );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->get_payment( $reference );

		$this->assertSame( $response_body, $result );
	}

	public function test_get_payment_returns_null_on_failure(): void {
		$api = $this->api_with_token();

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( $this->http_response( 404, array( 'error' => 'Not Found' ) ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 404 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '{"error":"Not Found"}' );

		$result = $api->get_payment( 'nonexistent-ref' );

		$this->assertNull( $result );
	}

	public function test_capture_payment_sends_amount(): void {
		$api = $this->api_with_token();

		$reference     = 'ref-capture-1';
		$amount        = array( 'value' => 5000, 'currency' => 'NOK' );
		$response_body = array( 'state' => 'CAPTURED' );

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::on( function ( $url ) use ( $reference ) {
					return str_contains( $url, '/epayment/v1/payments/' . $reference . '/capture' );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->capture_payment( $reference, $amount );

		$this->assertSame( $response_body, $result );
	}

	public function test_cancel_payment_sends_request(): void {
		$api = $this->api_with_token();

		$reference     = 'ref-cancel-1';
		$response_body = array( 'state' => 'TERMINATED' );

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::on( function ( $url ) use ( $reference ) {
					return str_contains( $url, '/epayment/v1/payments/' . $reference . '/cancel' );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->cancel_payment( $reference );

		$this->assertSame( $response_body, $result );
	}

	public function test_refund_payment_sends_amount(): void {
		$api = $this->api_with_token();

		$reference     = 'ref-refund-1';
		$amount        = array( 'value' => 2500, 'currency' => 'NOK' );
		$response_body = array( 'state' => 'REFUNDED' );

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1234' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::on( function ( $url ) use ( $reference ) {
					return str_contains( $url, '/epayment/v1/payments/' . $reference . '/refund' );
				} ),
				\Mockery::any()
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->refund_payment( $reference, $amount );

		$this->assertSame( $response_body, $result );
	}

	public function test_request_returns_null_when_no_token(): void {
		// get_transient returns false (no cache), and token fetch itself fails.
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( false );

		$wp_error = new \WP_Error( 'http_error', 'Timeout' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( true );

		// wp_remote_request should never be called since token fetch failed.
		Functions\expect( 'wp_remote_request' )->never();

		$api    = $this->make_api();
		$result = $api->create_payment( array( 'amount' => 1000 ) );

		$this->assertNull( $result );
	}

	public function test_request_includes_correct_auth_headers(): void {
		$api = $this->api_with_token();

		$response_body = array( 'state' => 'CREATED' );
		$fake_token    = $this->fake_token;
		$sub_key       = $this->subscription_key;
		$msn           = $this->merchant_serial;

		Functions\expect( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-5678' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				\Mockery::any(),
				\Mockery::on( function ( $args ) use ( $fake_token, $sub_key, $msn ) {
					$headers = $args['headers'];
					return $headers['Authorization'] === 'Bearer ' . $fake_token
						&& $headers['Ocp-Apim-Subscription-Key'] === $sub_key
						&& $headers['Merchant-Serial-Number'] === $msn
						&& $headers['Content-Type'] === 'application/json'
						&& $headers['Idempotency-Key'] === 'uuid-5678';
				} )
			)
			->andReturn( $this->http_response( 200, $response_body ) );

		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( json_encode( $response_body ) );

		$result = $api->create_payment( array( 'test' => true ) );

		$this->assertSame( $response_body, $result );
	}
}
