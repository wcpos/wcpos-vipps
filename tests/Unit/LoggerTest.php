<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\Logger;

class LoggerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// is_enabled
	// ---------------------------------------------------------------

	public function test_is_enabled_returns_true_when_debug_yes(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_wcpos_vipps_settings', array() )
			->andReturn( array( 'debug' => 'yes' ) );

		$this->assertTrue( Logger::is_enabled() );
	}

	public function test_is_enabled_returns_false_when_debug_no(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_wcpos_vipps_settings', array() )
			->andReturn( array( 'debug' => 'no' ) );

		$this->assertFalse( Logger::is_enabled() );
	}

	public function test_is_enabled_returns_false_when_setting_missing(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_wcpos_vipps_settings', array() )
			->andReturn( array() );

		$this->assertFalse( Logger::is_enabled() );
	}

	// ---------------------------------------------------------------
	// log
	// ---------------------------------------------------------------

	public function test_log_passes_message_to_wc_logger(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )
			->once()
			->with( 'Test message', array( 'source' => 'wcpos-vipps' ) );

		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'debug' => 'yes' ) );

		Functions\expect( 'current_time' )
			->once()
			->with( 'H:i:s' )
			->andReturn( '12:00:00' );

		Functions\expect( 'wc_get_logger' )
			->once()
			->andReturn( $mock_logger );

		Logger::log( 'Test message' );
	}

	public function test_log_uses_error_level_for_errors(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'error' )
			->once()
			->with( 'Something failed', array( 'source' => 'wcpos-vipps' ) );

		Functions\expect( 'get_option' )->andReturn( array( 'debug' => 'yes' ) );
		Functions\expect( 'current_time' )->andReturn( '12:00:00' );
		Functions\expect( 'wc_get_logger' )->andReturn( $mock_logger );

		Logger::log( 'Something failed', 'ERROR' );
	}

	public function test_log_does_nothing_when_disabled(): void {
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'debug' => 'no' ) );

		// wc_get_logger should never be called.
		Functions\expect( 'wc_get_logger' )->never();

		Logger::log( 'Should not be logged' );
	}

	public function test_log_buffers_when_order_id_provided(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )->once();

		Functions\expect( 'get_option' )->andReturn( array( 'debug' => 'yes' ) );
		Functions\expect( 'current_time' )->andReturn( '14:30:00' );
		Functions\expect( 'wc_get_logger' )->andReturn( $mock_logger );

		// Buffer calls get_transient and set_transient.
		Functions\expect( 'get_transient' )
			->once()
			->with( '_wcpos_vipps_log_42' )
			->andReturn( false );

		Functions\expect( 'set_transient' )
			->once()
			->with(
				'_wcpos_vipps_log_42',
				array( '14:30:00 [INFO] Order event' ),
				MINUTE_IN_SECONDS * 10
			);

		Logger::log( 'Order event', 'INFO', 42 );
	}

	// ---------------------------------------------------------------
	// flush
	// ---------------------------------------------------------------

	public function test_flush_returns_and_clears_buffer(): void {
		$entries = array(
			'12:00:00 [INFO] Created payment',
			'12:00:02 [INFO] Status: CREATED',
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( '_wcpos_vipps_log_99' )
			->andReturn( $entries );

		Functions\expect( 'delete_transient' )
			->once()
			->with( '_wcpos_vipps_log_99' );

		$result = Logger::flush( 99 );

		$this->assertSame( $entries, $result );
	}

	public function test_flush_returns_empty_array_when_no_buffer(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( '_wcpos_vipps_log_50' )
			->andReturn( false );

		$result = Logger::flush( 50 );

		$this->assertSame( array(), $result );
	}
}
