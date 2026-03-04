<?php

namespace WCPOS\WooCommercePOS\Vipps;

/**
 * Handles the return URL for Vipps WEB_REDIRECT payments.
 *
 * WooCommerce's default checkout-pay URL shows an "order already paid" error
 * when the customer returns from Vipps. This handler provides a lightweight
 * landing page that confirms payment and auto-closes the popup window.
 */
class ReturnHandler {

	public function __construct() {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_return' ) );
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'wcpos_vipps_return';
		$vars[] = 'wcpos_vipps_order_id';
		$vars[] = 'wcpos_vipps_token';
		return $vars;
	}

	public function handle_return(): void {
		if ( ! get_query_var( 'wcpos_vipps_return' ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'wcpos_vipps_order_id' ) );
		$token    = sanitize_text_field( (string) get_query_var( 'wcpos_vipps_token' ) );

		if ( ! $order_id || ! $token ) {
			status_header( 400 );
			wp_die( 'Bad request.', 'Bad request', array( 'response' => 400 ) );
		}

		if ( ! hash_equals( AjaxHandler::generate_token( $order_id ), $token ) ) {
			status_header( 403 );
			wp_die( 'Forbidden.', 'Forbidden', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			status_header( 404 );
			wp_die( 'Order not found.', 'Not found', array( 'response' => 404 ) );
		}

		$this->render_return_page( $order );
		exit;
	}

	private function render_return_page( \WC_Order $order ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$order_number = esc_html( $order->get_order_number() );
		$home_url     = wp_json_encode( home_url( '/' ) );

		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex,nofollow">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Payment complete', 'wcpos-vipps' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; padding: 24px; background: #fff; }
		.box { max-width: 520px; margin: 40px auto; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
		h1 { margin: 0 0 8px; font-size: 22px; }
		p { margin: 0 0 16px; color: #374151; }
		.muted { color: #6b7280; font-size: 13px; }
	</style>
</head>
<body>
	<div class="box">
		<h1><?php esc_html_e( 'Payment complete', 'wcpos-vipps' ); ?></h1>
		<p><?php esc_html_e( 'You can close this window and return to the checkout.', 'wcpos-vipps' ); ?></p>
		<p class="muted"><?php printf( esc_html__( 'Order: #%s', 'wcpos-vipps' ), $order_number ); ?></p>
	</div>
	<script>
		setTimeout(function () {
			window.close();
			setTimeout(function () {
				if (!window.closed) {
					window.location.href = <?php echo $home_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode handles escaping ?>;
				}
			}, 400);
		}, 700);
	</script>
</body>
</html>
		<?php
	}
}
