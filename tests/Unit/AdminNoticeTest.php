<?php

namespace WCPOS\WooCommercePOS\Vipps\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\Vipps\AdminNotice;

class AdminNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( array(
			'__'            => function ( $text ) { return $text; },
			'esc_html'      => function ( $text ) { return $text; },
			'esc_html_e'    => function ( $text ) { echo $text; },
			'esc_url'       => function ( $text ) { return $text; },
			'esc_attr'      => function ( $text ) { return $text; },
			'wp_nonce_url'  => function ( $url ) { return $url . '&_wpnonce=fake'; },
			'add_action'    => null,
			'add_query_arg' => function () { return 'http://example.com'; },
		) );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_should_show_returns_false_when_push_mode(): void {
		Functions\expect( 'get_transient' )
			->with( 'wcpos_vipps_push_mode_msn123' )
			->andReturn( 'push' );

		$this->assertFalse( AdminNotice::should_show( 'msn123' ) );
	}

	public function test_should_show_returns_false_when_no_transient(): void {
		Functions\expect( 'get_transient' )
			->with( 'wcpos_vipps_push_mode_msn123' )
			->andReturn( false );

		$this->assertFalse( AdminNotice::should_show( 'msn123' ) );
	}

	public function test_should_show_returns_false_when_dismissed(): void {
		Functions\expect( 'get_transient' )
			->with( 'wcpos_vipps_push_mode_msn123' )
			->andReturn( 'redirect' );
		Functions\expect( 'get_option' )
			->with( 'wcpos_vipps_push_notice_dismissed_' . md5( 'msn123' ) )
			->andReturn( true );

		$this->assertFalse( AdminNotice::should_show( 'msn123' ) );
	}

	public function test_should_show_returns_true_when_redirect_and_not_dismissed(): void {
		Functions\expect( 'get_transient' )
			->with( 'wcpos_vipps_push_mode_msn123' )
			->andReturn( 'redirect' );
		Functions\expect( 'get_option' )
			->with( 'wcpos_vipps_push_notice_dismissed_' . md5( 'msn123' ) )
			->andReturn( false );

		$this->assertTrue( AdminNotice::should_show( 'msn123' ) );
	}
}
