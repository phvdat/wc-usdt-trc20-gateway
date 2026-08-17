# Changelog

## 0.1.5
- Added an in-plugin server cron/WP-CLI setup guide to the gateway settings.
- Added exact example commands for checking and manually running the payment checker.
- Documented recommended `--due-now` production cron behavior.
- Documented production Mainnet configuration and WooCommerce processing email behavior.

## 0.1.4
- Fixed checkout fatal error caused by gateway meta constants being referenced before definition.
- Fixed Nile/Shasta/Mainnet TronGrid endpoint selection.
- Fixed network-specific USDT contract verification.

## 0.1.6
- Added QR payment UI.
- Added copy address/amount buttons.
- Added automatic payment status polling.


## v0.2.7 — Direct TXID verification
- Removed the repeated quick-payment Network/Receiving address block from the payment page.
- Verify payment now queries the submitted TXID directly through TronGrid confirmed transaction events.
- Added clear validation for destination, USDT contract, exact amount, payment window, and TXID reuse.
- Payment amount is displayed to customers with 2 decimal places.


## 0.2.8
- Added direct TRON transaction/receipt fallback for TXID verification.
- Added ABI decoding for TRC20 Transfer transactions.
- Improved verifier error visibility.
