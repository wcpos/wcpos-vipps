# Changelog

## [0.5.0] - 2026-03-04

### Added
- Custom return URL endpoint for WEB_REDIRECT payments — avoids WooCommerce's "order already paid" error when the customer returns from Vipps
- Atomic payment-creation lock with UUID ownership to prevent duplicate payments and race conditions
- Norwegian phone number normalization — handles `+47`, `0047`, spaces, and bare 8-digit local numbers
- `completed` flag in check_status response (true for CAPTURED/AUTHORIZED) so the frontend doesn't need to interpret Vipps states
- Token redaction in debug log output for redirect URLs
- Phone number PII masking in debug logs
- Null safety checks on API callers to prevent crashes when the gateway is misconfigured
- Type safety with string casts on order meta values and `is_array()` checks on API responses

### Changed
- Return URL for WEB_REDIRECT now uses a lightweight custom endpoint (`ReturnHandler`) instead of WooCommerce's checkout-pay page
- Phone validation error message is more descriptive

## [0.4.0] - 2026-03-04

### Added
- Phone flow setting in gateway admin (Direct Push / Web Redirect selector)
- Auto-detection of PUSH_MESSAGE support now persists to the gateway setting

### Removed
- AdminNotice class (replaced by the phone_flow setting)

### Changed
- Replaced transient-based push/redirect caching with a proper gateway setting

## [0.3.1] - 2026-03-03

### Fixed
- Clarified that the Enable checkbox is for the web store, not the POS

## [0.3.0] - 2026-03-02

### Added
- Auto-detect PUSH_MESSAGE support with WEB_REDIRECT fallback
- Admin notice when push is not enabled, with link to Vipps documentation

## [0.2.1] - 2026-03-01

### Fixed
- Log panel wiring in React app
- Removed unnecessary card wrapper from payment UI

## [0.2.0] - 2026-03-01

Initial release with QR code and push notification payment support.
