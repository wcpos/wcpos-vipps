<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\Api;
use WCPOS\WooCommercePOS\Vipps\Gateway;

class GatewayTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( array(
			'__'           => function ( $text ) { return $text; },
			'esc_html_e'   => function ( $text ) { echo $text; },
			'esc_attr_e'   => function ( $text ) { echo $text; },
			'add_action'   => null,
			'get_option'   => function () { return array(); },
			'apply_filters' => true,
			'absint'       => function ( $v ) { return abs( intval( $v ) ); },
		) );

		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		Functions\stubs( array(
			'wc_get_logger' => $mock_logger,
		) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Create a Gateway instance and apply the given settings.
	 */
	private function make_gateway( array $settings = array() ): Gateway {
		$gateway = new Gateway();
		foreach ( $settings as $key => $value ) {
			$gateway->update_option( $key, $value );
		}
		return $gateway;
	}

	/**
	 * Inject a mock Api into the Gateway's private $api property via reflection.
	 */
	private function inject_api( Gateway $gateway, $mock_api ): void {
		$ref = new \ReflectionProperty( Gateway::class, 'api' );
		$ref->setAccessible( true );
		$ref->setValue( $gateway, $mock_api );
	}

	/**
	 * Create a mock WC_Order with sensible defaults for process_payment tests.
	 */
	private function make_order_mock( array $meta = array(), array $overrides = array() ): \Mockery\MockInterface {
		$order = \Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );

		$order->shouldReceive( 'get_checkout_payment_url' )
			->zeroOrMoreTimes()
			->andReturn( 'http://example.com/checkout/order-pay/123/' );

		$order->shouldReceive( 'get_currency' )
			->zeroOrMoreTimes()
			->andReturn( $overrides['currency'] ?? 'NOK' );

		$order->shouldReceive( 'get_total' )
			->zeroOrMoreTimes()
			->andReturn( $overrides['total'] ?? 100.00 );

		return $order;
	}

	// ---------------------------------------------------------------
	// Form fields
	// ---------------------------------------------------------------

	public function test_init_form_fields_has_required_keys(): void {
		$gateway = $this->make_gateway();

		$expected_keys = array(
			'enabled',
			'title',
			'description',
			'merchant_serial_number',
			'client_id',
			'client_secret',
			'subscription_key',
			'auto_capture',
			'debug',
			'test_mode',
			'test_merchant_serial_number',
			'test_client_id',
			'test_client_secret',
			'test_subscription_key',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $gateway->form_fields, "Missing form field: {$key}" );
		}

		$this->assertCount( 14, $gateway->form_fields );
	}

	// ---------------------------------------------------------------
	// is_available
	// ---------------------------------------------------------------

	public function test_is_available_returns_true_with_all_credentials(): void {
		$gateway = $this->make_gateway( array(
			'enabled'                => 'yes',
			'client_id'              => 'cid',
			'client_secret'          => 'csec',
			'subscription_key'       => 'skey',
			'merchant_serial_number' => 'msn',
		) );

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_is_available_returns_false_when_credential_missing(): void {
		$gateway = $this->make_gateway( array(
			'enabled'                => 'yes',
			'client_id'              => 'cid',
			'client_secret'          => 'csec',
			'subscription_key'       => 'skey',
			// merchant_serial_number intentionally missing
		) );

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_is_available_uses_test_credentials_in_test_mode(): void {
		$gateway = $this->make_gateway( array(
			'enabled'                     => 'yes',
			'test_mode'                   => 'yes',
			'test_client_id'              => 'tcid',
			'test_client_secret'          => 'tcsec',
			'test_subscription_key'       => 'tskey',
			'test_merchant_serial_number' => 'tmsn',
		) );

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_is_available_returns_false_when_disabled(): void {
		$gateway = $this->make_gateway( array(
			'enabled'                => 'no',
			'client_id'              => 'cid',
			'client_secret'          => 'csec',
			'subscription_key'       => 'skey',
			'merchant_serial_number' => 'msn',
		) );

		$this->assertFalse( $gateway->is_available() );
	}

	// ---------------------------------------------------------------
	// get_api
	// ---------------------------------------------------------------

	public function test_get_api_returns_api_instance(): void {
		$gateway = $this->make_gateway( array(
			'client_id'              => 'cid',
			'client_secret'          => 'csec',
			'subscription_key'       => 'skey',
			'merchant_serial_number' => 'msn',
		) );

		$this->assertInstanceOf( Api::class, $gateway->get_api() );
	}

	public function test_get_api_returns_same_instance_on_second_call(): void {
		$gateway = $this->make_gateway( array(
			'client_id'              => 'cid',
			'client_secret'          => 'csec',
			'subscription_key'       => 'skey',
			'merchant_serial_number' => 'msn',
		) );

		$first  = $gateway->get_api();
		$second = $gateway->get_api();

		$this->assertSame( $first, $second );
	}

	// ---------------------------------------------------------------
	// process_payment
	// ---------------------------------------------------------------

	public function test_process_payment_redirects_when_not_authorized(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_status'    => 'CREATED',
			'_wcpos_vipps_reference' => 'ref-123',
		) );

		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $order );

		$gateway = $this->make_gateway();
		$result  = $gateway->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'http://example.com/checkout/order-pay/123/', $result['redirect'] );
	}

	public function test_process_payment_completes_with_auto_capture(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_status'    => 'AUTHORIZED',
			'_wcpos_vipps_reference' => 'ref-456',
		) );

		$order->shouldReceive( 'payment_complete' )->once()->with( 'ref-456' );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->once()->with( 99 )->andReturn( $order );

		$mock_api = \Mockery::mock( Api::class );
		$mock_api->shouldReceive( 'capture_payment' )
			->once()
			->with( 'ref-456', array( 'currency' => 'NOK', 'value' => 10000 ) );

		$gateway = $this->make_gateway( array( 'auto_capture' => 'yes' ) );
		$this->inject_api( $gateway, $mock_api );

		$result = $gateway->process_payment( 99 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'http://example.com/thank-you/', $result['redirect'] );
	}

	public function test_process_payment_skips_capture_when_disabled(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_status'    => 'AUTHORIZED',
			'_wcpos_vipps_reference' => 'ref-789',
		) );

		$order->shouldReceive( 'payment_complete' )->once()->with( 'ref-789' );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->once()->with( 50 )->andReturn( $order );

		$mock_api = \Mockery::mock( Api::class );
		$mock_api->shouldNotReceive( 'capture_payment' );

		$gateway = $this->make_gateway( array( 'auto_capture' => 'no' ) );
		$this->inject_api( $gateway, $mock_api );

		$result = $gateway->process_payment( 50 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'http://example.com/thank-you/', $result['redirect'] );
	}

	public function test_process_payment_returns_failure_for_missing_order(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 999 )->andReturn( false );
		Functions\expect( 'wc_add_notice' )->once();

		$gateway = $this->make_gateway();
		$result  = $gateway->process_payment( 999 );

		$this->assertSame( 'failure', $result['result'] );
	}

	// ---------------------------------------------------------------
	// process_refund
	// ---------------------------------------------------------------

	public function test_process_refund_returns_true_on_success(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_reference' => 'ref-refund-1',
		) );
		$order->shouldReceive( 'add_order_note' )->once();

		Functions\expect( 'wc_get_order' )->once()->with( 10 )->andReturn( $order );
		Functions\expect( 'wc_price' )->once()->with( 25.50 )->andReturn( 'kr 25.50' );

		$mock_api = \Mockery::mock( Api::class );
		$mock_api->shouldReceive( 'refund_payment' )
			->once()
			->with( 'ref-refund-1', array( 'currency' => 'NOK', 'value' => 2550 ) )
			->andReturn( array( 'state' => 'REFUNDED' ) );

		$gateway = $this->make_gateway();
		$this->inject_api( $gateway, $mock_api );

		$result = $gateway->process_refund( 10, 25.50, 'Customer request' );

		$this->assertTrue( $result );
	}

	public function test_process_refund_returns_wp_error_on_failure(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_reference' => 'ref-refund-2',
		) );

		Functions\expect( 'wc_get_order' )->once()->with( 11 )->andReturn( $order );

		$mock_api = \Mockery::mock( Api::class );
		$mock_api->shouldReceive( 'refund_payment' )
			->once()
			->andReturn( null );

		$gateway = $this->make_gateway();
		$this->inject_api( $gateway, $mock_api );

		$result = $gateway->process_refund( 11, 10.00, 'Defective' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'refund_failed', $result->code );
	}

	public function test_process_refund_returns_wp_error_when_no_reference(): void {
		$order = $this->make_order_mock( array(
			'_wcpos_vipps_reference' => '',
		) );

		Functions\expect( 'wc_get_order' )->once()->with( 12 )->andReturn( $order );

		$gateway = $this->make_gateway();
		$result  = $gateway->process_refund( 12, 5.00, 'No ref' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_reference', $result->code );
	}
}
