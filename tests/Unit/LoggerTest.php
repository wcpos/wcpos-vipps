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

	public function test_log_passes_message_to_wc_logger(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )
			->once()
			->with( 'Test message', array( 'source' => 'wcpos-vipps' ) );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wcpos_vipps_logging', true )
			->andReturn( true );

		Functions\expect( 'wc_get_logger' )
			->once()
			->andReturn( $mock_logger );

		Logger::log( 'Test message' );
	}

	public function test_log_serializes_arrays(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )
			->once()
			->withArgs( function ( $message, $context ) {
				return str_contains( $message, 'key' )
					&& str_contains( $message, 'value' )
					&& $context === array( 'source' => 'wcpos-vipps' );
			} );

		Functions\expect( 'apply_filters' )->andReturn( true );
		Functions\expect( 'wc_get_logger' )->andReturn( $mock_logger );

		Logger::log( array( 'key' => 'value' ) );
	}

	public function test_log_serializes_objects(): void {
		$mock_logger = \Mockery::mock();
		$mock_logger->shouldReceive( 'info' )
			->once()
			->withArgs( function ( $message, $context ) {
				return str_contains( $message, 'foo' )
					&& $context === array( 'source' => 'wcpos-vipps' );
			} );

		Functions\expect( 'apply_filters' )->andReturn( true );
		Functions\expect( 'wc_get_logger' )->andReturn( $mock_logger );

		$obj = new \stdClass();
		$obj->foo = 'bar';
		Logger::log( $obj );
	}

	public function test_log_does_nothing_when_filter_returns_false(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wcpos_vipps_logging', true )
			->andReturn( false );

		// wc_get_logger should never be called.
		Functions\expect( 'wc_get_logger' )->never();

		Logger::log( 'Should not be logged' );
	}
}
