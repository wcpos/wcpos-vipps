# WCPOS Vipps MobilePay

A WooCommerce payment gateway for Vipps and MobilePay payments via QR codes and push notifications. Built for [WooCommerce POS](https://wcpos.com) (Pro) and standard WooCommerce web checkout.

## How It Works

This gateway uses the [Vipps MobilePay ePayment API](https://developer.vippsmobilepay.com/docs/APIs/epayment-api/) with two payment flows:

**QR Code** — Click "Generate QR Code" to display a QR code on screen. The customer scans it with the Vipps or MobilePay app and confirms payment.

**Push Notification** — Enter the customer's phone number and click "Send to Phone." Vipps sends a push notification to the customer's app for confirmation.

Both flows poll for payment status every 2 seconds (up to 5 minutes). When the payment is authorized, the order completes automatically.

## Installation

1. Download the latest release from the [releases page](https://github.com/wcpos/wcpos-vipps/releases)
2. Install via `WP Admin > Plugins > Add New > Upload Plugin`
3. Activate the plugin
4. Go to **WooCommerce > Settings > Payments > WCPOS Vipps MobilePay**
5. Enter your Vipps API credentials (see below)

### Development Install

```bash
git clone https://github.com/wcpos/wcpos-vipps.git wp-content/plugins/wcpos-vipps
cd wp-content/plugins/wcpos-vipps
composer install
```

## Configuration

You need credentials from the [Vipps MobilePay portal](https://portal.vippsmobilepay.com/):

| Credential | Description |
|------------|-------------|
| Merchant Serial Number | Your sales unit MSN |
| Client ID | OAuth client ID |
| Client Secret | OAuth client secret |
| Subscription Key | Ocp-Apim-Subscription-Key |

### Automatic Credential Import

If the official [Checkout with Vipps MobilePay](https://wordpress.org/plugins/woo-vipps/) plugin is already installed and configured, credentials are imported automatically on first activation.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Auto Capture | Enabled | Capture funds immediately after authorization. Disable for reserve-and-capture workflows. |
| Test Mode | Disabled | Route API calls to `apitest.vipps.no` using test credentials. |
| Debug Log | Disabled | Log payment events to `WooCommerce > Status > Logs` and show a live log panel on the checkout screen. |

## Usage

### POS Checkout (requires Pro)

This gateway is designed to work inside the [WooCommerce POS](https://wcpos.com) checkout. The payment interface renders on the order-pay page — the same page the POS loads for payment processing. No redirects, no popups, no external windows.

1. Enable the gateway in `WP Admin > WooCommerce POS > Settings > Checkout`
2. You don't need to enable it in WooCommerce Payments settings for POS-only use

### Web Checkout

The gateway also works on the standard WooCommerce checkout. Selecting "WCPOS Vipps MobilePay" and clicking "Place Order" creates the order and redirects to the order-pay page, where the QR code and push notification interface appears.

## Debug Logging

When **Debug Log** is enabled in the gateway settings:

- **WooCommerce Logs** — All API requests, responses, and errors are written to `WooCommerce > Status > Logs` with source `wcpos-vipps`
- **Live Log Panel** — A collapsible textarea appears on the checkout screen showing real-time payment events (both server-side API activity and client-side flow events)

Server-side log entries are buffered per-order in WordPress transients and delivered to the frontend via the existing polling AJAX responses — no extra requests.

## Supported Markets

| Region | Brand | Currencies |
|--------|-------|------------|
| Norway | Vipps | NOK |
| Denmark, Finland | MobilePay | DKK, EUR |

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- HTTPS (required by Vipps API)
- [WooCommerce POS](https://wcpos.com) Pro (for POS checkout)

## Architecture

```
wcpos-vipps.php              → Plugin bootstrap, registers gateway
includes/Gateway.php          → WC_Payment_Gateway subclass, settings, payment fields
includes/Api.php              → Vipps ePayment API client (auth, create, capture, refund, cancel)
includes/AjaxHandler.php      → AJAX endpoints for create_payment, check_status, cancel_payment
includes/Logger.php           → Debug logging with per-order transient buffer
assets/js/payment.js          → Frontend payment UI (jQuery)
assets/css/payment.css        → Payment interface styling (Vipps brand colors)
```

## License

GPL-3.0-or-later
