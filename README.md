# WooCommerce POS - Vipps MobilePay

A WooCommerce payment gateway that accepts Vipps and MobilePay payments via QR codes and push notifications. Built for both point-of-sale (POS) and web checkout use cases.

## How It Works

This gateway uses the [Vipps MobilePay ePayment API](https://developer.vippsmobilepay.com/docs/APIs/epayment-api/) to offer two payment flows:

**QR Code (primary)** — The cashier or customer clicks "Generate QR Code." A QR code appears on screen. The customer scans it with the Vipps or MobilePay app on their phone and confirms the payment.

**Push Notification** — The cashier enters the customer's phone number and clicks "Send to Phone." Vipps sends a push notification to the customer's app. The customer opens it and confirms.

Both flows poll for payment status automatically. When the payment is confirmed, the order completes.

## Installation

1. Download or clone this repository into `wp-content/plugins/`
2. Run `composer install` in the plugin directory
3. Activate the plugin in WordPress admin
4. Go to **WooCommerce > Settings > Payments > Vipps MobilePay**
5. Enter your Vipps API credentials (see below)

## Configuration

You'll need credentials from the [Vipps MobilePay portal](https://portal.vippsmobilepay.com/):

- **Merchant Serial Number** — Your sales unit MSN
- **Client ID** — OAuth client ID
- **Client Secret** — OAuth client secret
- **Subscription Key** — Ocp-Apim-Subscription-Key

If the official [Vipps MobilePay for WooCommerce](https://github.com/vippsas/vipps-woocommerce) plugin is already installed and configured, credentials are imported automatically on first activation.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Auto Capture | Enabled | Capture funds immediately after authorization. Disable for reserve-and-capture workflows. |
| Test Mode | Disabled | Route API calls to `apitest.vipps.no` using test credentials. |

## Supported Markets

- **Vipps** — Norway, Sweden
- **MobilePay** — Denmark, Finland

Supported currencies: NOK, DKK, EUR.

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- HTTPS (required by Vipps API)

## POS Usage

This gateway is designed to work inside the [WooCommerce POS](https://wcpos.com) iframe. The payment interface renders on the order-pay page — the same page the POS loads for payment processing. No redirects, no popups, no external windows.

## Web Checkout Usage

On the standard WooCommerce checkout page, selecting "Vipps MobilePay" and clicking "Place Order" creates the order and redirects to the order-pay page, where the QR code and push notification interface appears.

## Development

```bash
composer install
```

## License

GPL-3.0-or-later
