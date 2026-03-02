<?php

namespace WCPOS\WooCommercePOS\Vipps;

class AdminNotice {

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Check whether the push upgrade notice should be shown.
	 */
	public static function should_show( string $msn ): bool {
		if ( 'redirect' !== get_transient( 'wcpos_vipps_push_mode_' . $msn ) ) {
			return false;
		}

		return ! get_option( 'wcpos_vipps_push_notice_dismissed' );
	}

	/**
	 * Show the admin notice on the gateway settings page.
	 */
	public function maybe_show_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		if ( ! isset( $_GET['tab'], $_GET['section'] ) || 'checkout' !== $_GET['tab'] || 'wcpos_vipps' !== $_GET['section'] ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways['wcpos_vipps'] ?? null;

		if ( ! $gateway instanceof Gateway ) {
			return;
		}

		$prefix = 'yes' === $gateway->get_option( 'test_mode' ) ? 'test_' : '';
		$msn    = $gateway->get_option( $prefix . 'merchant_serial_number' );

		if ( ! self::should_show( $msn ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wcpos_vipps_dismiss_push_notice', '1' ),
			'wcpos_vipps_dismiss_push_notice'
		);

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Enable direct push for faster phone payments', 'wcpos-vipps' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Your account is currently using the Vipps landing page for phone payments. For a smoother checkout experience, contact Vipps to enable direct push notifications on your sales unit.', 'wcpos-vipps' ); ?></p>
			<p>
				<?php esc_html_e( 'Tell them: "I need PUSH_MESSAGE enabled on my MSN for use with a POS integration."', 'wcpos-vipps' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small">
					<?php esc_html_e( 'Dismiss', 'wcpos-vipps' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle notice dismissal.
	 */
	public function handle_dismiss(): void {
		if ( ! isset( $_GET['wcpos_vipps_dismiss_push_notice'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wcpos_vipps_dismiss_push_notice' ) ) {
			return;
		}

		update_option( 'wcpos_vipps_push_notice_dismissed', true );

		wp_safe_redirect( remove_query_arg( array( 'wcpos_vipps_dismiss_push_notice', '_wpnonce' ) ) );
		exit;
	}
}
