<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\AjaxHandler;
use WCPOS\WooCommercePOS\Vipps\Api;
use WCPOS\WooCommercePOS\Vipps\Gateway;

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
			'__'                  => function ( $text ) { return $text; },
		) );

		Functions\stubs( array(
			'get_transient'    => false,
			'set_transient'    => null,
			'delete_transient' => null,
			'get_option'          => false,
			'add_option'          => true,
			'wp_generate_uuid4'   => 'test-uuid-1234',
			'delete_option'       => null,
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

	private function call_release_lock( AjaxHandler $handler, string $lock_key, string $lock_uuid ): void {
		$method = new \ReflectionMethod( AjaxHandler::class, 'release_lock' );
		$method->setAccessible( true );
		$method->invoke( $handler, $lock_key, $lock_uuid );
	}

	/**
	 * Compute the expected token for an order_id using the same
	 * logic the class will use with our stubs (wp_salt returns
	 * a fixed string, wp_hash delegates to md5).
	 */

	private function inject_api( Gateway $gateway, $mock_api ): void {
		$ref = new \ReflectionProperty( Gateway::class, 'api' );
		$ref->setAccessible( true );
		$ref->setValue( $gateway, $mock_api );
	}

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
	// release_lock
	// ---------------------------------------------------------------

	public function test_release_lock_deletes_option_when_uuid_matches(): void {
		$handler   = new AjaxHandler();
		$lock_key  = 'wcpos_vipps_create_lock_42';
		$lock_uuid = 'owned-lock-uuid';

		$get_option_calls = 0;
		Functions\when( 'get_option' )->alias( function ( $key ) use ( $lock_key, $lock_uuid, &$get_option_calls ) {
			$this->assertSame( $lock_key, $key );
			++$get_option_calls;

			return 1 === $get_option_calls ? '1710000000:' . $lock_uuid : '1710000000:other-request-uuid';
		} );

		$wpdb = new class() {
			public $options = 'wp_options';

			public $delete_calls = array();

			public function delete( $table, $where, $format ) {
				$this->delete_calls[] = compact( 'table', 'where', 'format' );

				return 1;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( $lock_key, 'options' )
			->andReturn( true );

		$this->call_release_lock( $handler, $lock_key, $lock_uuid );

		$this->assertSame( 1, $get_option_calls );
		$this->assertSame( array(
			array(
				'table'  => 'wp_options',
				'where'  => array(
					'option_name'  => $lock_key,
					'option_value' => '1710000000:' . $lock_uuid,
				),
				'format' => array( '%s', '%s' ),
			),
		), $wpdb->delete_calls );
	}

	public function test_release_lock_keeps_option_when_uuid_does_not_match(): void {
		$handler   = new AjaxHandler();
		$lock_key  = 'wcpos_vipps_create_lock_42';
		$lock_uuid = 'current-request-uuid';

		Functions\when( 'get_option' )->alias( function ( $key ) use ( $lock_key ) {
			$this->assertSame( $lock_key, $key );

			return '1710000000:other-request-uuid';
		} );

		$wpdb = new class() {
			public $options = 'wp_options';

			public $delete_calls = array();

			public function delete( $table, $where, $format ) {
				$this->delete_calls[] = compact( 'table', 'where', 'format' );

				return 1;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		Functions\expect( 'delete_option' )
			->never();
		Functions\expect( 'wp_cache_delete' )
			->never();

		$this->call_release_lock( $handler, $lock_key, $lock_uuid );

		$this->assertSame( array(), $wpdb->delete_calls );
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

	public function test_redact_url_token_with_prefixed_param(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?wcpos_vipps_token=secret123&foo=bar';
		$result  = $this->call_redact_url_token( $handler, $url );

		$this->assertStringContainsString( 'wcpos_vipps_token=[redacted]', $result );
		$this->assertStringNotContainsString( 'secret123', $result );
	}

	public function test_redact_url_token_percent_encoded(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?foo=bar&token%3Dsecret123';
		$result  = $this->call_redact_url_token( $handler, $url );

		$this->assertStringNotContainsString( 'secret123', $result );
	}

	public function test_redact_url_token_percent_encoded_prefixed_token(): void {
		$handler = new AjaxHandler();
		$url     = 'https://example.com/?foo=bar&wcpos_vipps_token%3Dsecret123';
		$result  = $this->call_redact_url_token( $handler, $url );

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
		$this->assertStringContainsString( 'wcpos_vipps_order_id=42', $url );
		$this->assertStringContainsString( 'wcpos_vipps_token=', $url );
	}


	// ---------------------------------------------------------------
	// ajax_check_status
	// ---------------------------------------------------------------

	public function test_ajax_check_status_completes_order_when_authorized(): void {
		$order_id = 42;
		$token    = $this->expected_token( $order_id );

		$_POST = array(
			'order_id' => (string) $order_id,
			'token'    => $token,
		);

		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $order_id );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_reference' )->andReturn( 'ref-123' );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_capture_completed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_capture_idempotency_key' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_status', 'AUTHORIZED' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_capture_idempotency_key', 'test-uuid-1234' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_capture_completed', 'yes' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_status', 'CAPTURED' );
		$order->shouldReceive( 'save' )->times( 3 );
		$order->shouldReceive( 'is_paid' )->twice()->andReturn( false );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'NOK' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( 100.00 );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'ref-123' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->once()->with( $order_id )->andReturn( $order );

		$api = \Mockery::mock( Api::class );
		$api->shouldReceive( 'set_order_id' )->once()->with( $order_id );
		$api->shouldReceive( 'get_payment' )->once()->with( 'ref-123' )->andReturn( array( 'state' => 'AUTHORIZED' ) );
		$api->shouldReceive( 'capture_payment' )
			->once()
			->with( 'ref-123', array( 'currency' => 'NOK', 'value' => 10000 ), 'test-uuid-1234' )
			->andReturn( array() );

		$gateway = new Gateway();
		$gateway->update_option( 'auto_capture', 'yes' );
		$this->inject_api( $gateway, $api );

		$wc = new class( $gateway ) {
			private $gateway;

			public function __construct( $gateway ) {
				$this->gateway = $gateway;
			}

			public function payment_gateways() {
				return new class( $this->gateway ) {
					private $gateway;

					public function __construct( $gateway ) {
						$this->gateway = $gateway;
					}

					public function payment_gateways() {
						return array( 'wcpos_vipps' => $this->gateway );
					}
				};
			}
		};

		Functions\expect( 'WC' )->twice()->andReturn( $wc );
		Functions\expect( 'wp_send_json_success' )->once()->with( \Mockery::on( function ( $data ) {
			return 'AUTHORIZED' === $data['state']
				&& true === $data['completed']
				&& 'http://example.com/thank-you/' === $data['redirectUrl'];
		} ) );

		$handler = new AjaxHandler();
		$handler->ajax_check_status();
	}

	public function test_ajax_check_status_returns_error_when_order_completion_fails(): void {
		$order_id = 43;
		$token    = $this->expected_token( $order_id );

		$_POST = array(
			'order_id' => (string) $order_id,
			'token'    => $token,
		);

		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $order_id );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_reference' )->andReturn( 'ref-fail' );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_capture_completed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_wcpos_vipps_capture_idempotency_key' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_status', 'AUTHORIZED' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_wcpos_vipps_capture_idempotency_key', 'test-uuid-1234' );
		$order->shouldReceive( 'save' )->twice();
		$order->shouldReceive( 'is_paid' )->twice()->andReturn( false );
		$order->shouldReceive( 'get_currency' )->once()->andReturn( 'NOK' );
		$order->shouldReceive( 'get_total' )->once()->andReturn( 100.00 );
		$order->shouldReceive( 'payment_complete' )->never();
		$order->shouldReceive( 'add_order_note' )->never();

		Functions\expect( 'wc_get_order' )->once()->with( $order_id )->andReturn( $order );

		$api = \Mockery::mock( Api::class );
		$api->shouldReceive( 'set_order_id' )->once()->with( $order_id );
		$api->shouldReceive( 'get_payment' )->once()->with( 'ref-fail' )->andReturn( array( 'state' => 'AUTHORIZED' ) );
		$api->shouldReceive( 'capture_payment' )
			->once()
			->with( 'ref-fail', array( 'currency' => 'NOK', 'value' => 10000 ), 'test-uuid-1234' )
			->andReturn( null );

		$gateway = new Gateway();
		$gateway->update_option( 'auto_capture', 'yes' );
		$this->inject_api( $gateway, $api );

		$wc = new class( $gateway ) {
			private $gateway;

			public function __construct( $gateway ) {
				$this->gateway = $gateway;
			}

			public function payment_gateways() {
				return new class( $this->gateway ) {
					private $gateway;

					public function __construct( $gateway ) {
						$this->gateway = $gateway;
					}

					public function payment_gateways() {
						return array( 'wcpos_vipps' => $this->gateway );
					}
				};
			}
		};

		Functions\expect( 'WC' )->twice()->andReturn( $wc );
		Functions\expect( 'wp_send_json_error' )->once()->with( \Mockery::on( function ( $data ) {
			return 'AUTHORIZED' === $data['state']
				&& false === $data['completed']
				&& 'Vipps payment was accepted, but capture failed. Please try again.' === $data['message'];
		} ) );

		$handler = new AjaxHandler();
		$handler->ajax_check_status();
	}

}
