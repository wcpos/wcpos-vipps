<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\AjaxHandler;

class AjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( array(
			'add_action'          => null,
			'absint'              => function ( $v ) { return abs( intval( $v ) ); },
			'sanitize_text_field' => function ( $v ) { return trim( strip_tags( $v ) ); },
			'apply_filters'       => true,
			'wp_salt'             => 'fixed_salt_for_testing',
			'wp_hash'             => 'md5',
		) );

		Functions\stubs( array(
			'get_transient'    => false,
			'set_transient'    => null,
			'delete_transient' => null,
			'get_option'       => false,
		) );

		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		Functions\stubs( array(
			'wc_get_logger' => $mock_logger,
		) );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Use reflection to call the private validate_request method.
	 */
	private function call_validate_request( AjaxHandler $handler ): ?\WC_Order {
		$method = new \ReflectionMethod( AjaxHandler::class, 'validate_request' );
		$method->setAccessible( true );
		return $method->invoke( $handler );
	}

	/**
	 * Compute the expected token for an order_id using the same
	 * logic the class will use with our stubs (wp_salt returns
	 * a fixed string, wp_hash delegates to md5).
	 */
	private function expected_token( int $order_id ): string {
		$data = 'wcpos_vipps_' . $order_id . 'fixed_salt_for_testing';
		return substr( md5( $data ), 0, 10 );
	}

	// ---------------------------------------------------------------
	// generate_token
	// ---------------------------------------------------------------

	public function test_generate_token_is_deterministic(): void {
		$token1 = AjaxHandler::generate_token( 42 );
		$token2 = AjaxHandler::generate_token( 42 );

		$this->assertSame( $token1, $token2 );
		$this->assertSame( 10, strlen( $token1 ) );
	}

	public function test_generate_token_differs_for_different_orders(): void {
		$token_a = AjaxHandler::generate_token( 1 );
		$token_b = AjaxHandler::generate_token( 2 );

		$this->assertNotSame( $token_a, $token_b );
	}

	// ---------------------------------------------------------------
	// validate_request
	// ---------------------------------------------------------------

	public function test_validate_request_fails_on_missing_order_id(): void {
		$_POST = array();

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::on( function ( $data ) {
				return $data['message'] === 'Missing order ID or token.';
			} ) );

		$handler = new AjaxHandler();
		$result  = $this->call_validate_request( $handler );

		$this->assertNull( $result );
	}

	public function test_validate_request_fails_on_invalid_token(): void {
		$_POST = array(
			'order_id' => '42',
			'token'    => 'wrong_token',
		);

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::on( function ( $data ) {
				return $data['message'] === 'Invalid token.';
			} ) );

		$handler = new AjaxHandler();
		$result  = $this->call_validate_request( $handler );

		$this->assertNull( $result );
	}

	public function test_validate_request_fails_on_nonexistent_order(): void {
		$order_id = 42;
		$token    = $this->expected_token( $order_id );

		$_POST = array(
			'order_id' => (string) $order_id,
			'token'    => $token,
		);

		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::on( function ( $data ) {
				return $data['message'] === 'Order not found.';
			} ) );

		$handler = new AjaxHandler();
		$result  = $this->call_validate_request( $handler );

		$this->assertNull( $result );
	}

	public function test_validate_request_returns_order_on_valid_request(): void {
		$order_id = 42;
		$token    = $this->expected_token( $order_id );

		$_POST = array(
			'order_id' => (string) $order_id,
			'token'    => $token,
		);

		$mock_order = \Mockery::mock( 'WC_Order' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( $order_id )
			->andReturn( $mock_order );

		$handler = new AjaxHandler();
		$result  = $this->call_validate_request( $handler );

		$this->assertSame( $mock_order, $result );
	}

	// ---------------------------------------------------------------
	// normalize_no_phone
	// ---------------------------------------------------------------

	private function call_normalize_no_phone( AjaxHandler $handler, string $phone, int $order_id = 1 ): ?string {
		$method = new \ReflectionMethod( AjaxHandler::class, 'normalize_no_phone' );
		$method->setAccessible( true );
		return $method->invoke( $handler, $phone, $order_id );
	}

	public function test_normalize_phone_8_digit_local(): void {
		$handler = new AjaxHandler();
		$this->assertSame( '4741234567', $this->call_normalize_no_phone( $handler, '41234567' ) );
	}

	public function test_normalize_phone_with_country_code(): void {
		$handler = new AjaxHandler();
		$this->assertSame( '4741234567', $this->call_normalize_no_phone( $handler, '4741234567' ) );
	}

	public function test_normalize_phone_with_plus_prefix(): void {
		$handler = new AjaxHandler();
		$this->assertSame( '4741234567', $this->call_normalize_no_phone( $handler, '+4741234567' ) );
	}

	public function test_normalize_phone_with_0047_prefix(): void {
		$handler = new AjaxHandler();
		$this->assertSame( '4741234567', $this->call_normalize_no_phone( $handler, '004741234567' ) );
	}

	public function test_normalize_phone_with_spaces(): void {
		$handler = new AjaxHandler();
		$this->assertSame( '4741234567', $this->call_normalize_no_phone( $handler, '+47 412 34 567' ) );
	}

	public function test_normalize_phone_rejects_too_short(): void {
		$handler = new AjaxHandler();
		$this->assertNull( $this->call_normalize_no_phone( $handler, '1234' ) );
	}

	public function test_normalize_phone_rejects_non_norwegian(): void {
		$handler = new AjaxHandler();
		$this->assertNull( $this->call_normalize_no_phone( $handler, '+4612345678' ) );
	}

	// ---------------------------------------------------------------
	// redact_url_token
	// ---------------------------------------------------------------

	private function call_redact_url_token( AjaxHandler $handler, string $url ): string {
		$method = new \ReflectionMethod( AjaxHandler::class, 'redact_url_token' );
		$method->setAccessible( true );
		return $method->invoke( $handler, $url );
	}

	public function test_redact_url_token_with_query_param(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?token=secret123&foo=bar';
		$result  = $this->call_redact_url_token( $handler, $url );

		$this->assertStringContainsString( 'token=[redacted]', $result );
		$this->assertStringNotContainsString( 'secret123', $result );
	}

	public function test_redact_url_token_with_ampersand_param(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?foo=bar&token=secret123';
		$result  = $this->call_redact_url_token( $handler, $url );

		$this->assertStringContainsString( 'token=[redacted]', $result );
		$this->assertStringNotContainsString( 'secret123', $result );
	}

	public function test_redact_url_token_without_token(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?foo=bar';
		$result  = $this->call_redact_url_token( $handler, $url );

		$this->assertSame( $url, $result );
	}

	// ---------------------------------------------------------------
	// build_return_url
	// ---------------------------------------------------------------

	private function call_build_return_url( AjaxHandler $handler, \WC_Order $order ): string {
		$method = new \ReflectionMethod( AjaxHandler::class, 'build_return_url' );
		$method->setAccessible( true );
		return $method->invoke( $handler, $order );
	}

	public function test_build_return_url_contains_required_params(): void {
		Functions\expect( 'home_url' )
			->once()
			->with( '/' )
			->andReturn( 'https://example.com/' );

		Functions\expect( 'add_query_arg' )
			->once()
			->andReturnUsing( function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			} );

		$mock_order = \Mockery::mock( 'WC_Order' );
		$mock_order->shouldReceive( 'get_id' )->andReturn( 42 );

		$handler = new AjaxHandler();
		$url     = $this->call_build_return_url( $handler, $mock_order );

		$this->assertStringContainsString( 'wcpos_vipps_return=1', $url );
		$this->assertStringContainsString( 'order_id=42', $url );
		$this->assertStringContainsString( 'token=', $url );
	}
}
