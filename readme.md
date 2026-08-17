# WooCommerce USDT TRC20 Direct Gateway v0.1.5

Direct USDT TRC20 payment gateway for WooCommerce. The plugin watches a configured TRON wallet and automatically marks matching orders as paid.

## Production configuration

Go to **WooCommerce → Settings → Payments → USDT TRC20**.

### Mainnet
- Network: **TRON Mainnet**
- Receiving wallet: your dedicated TRON merchant wallet address
- TronGrid API key: required for Mainnet
- Required confirmations: start with **19**
- Payment timeout: **60 minutes**
- Unique payment amount: **Enabled**
- Debug logging: enable while testing, then disable after stable operation

Never enter a seed phrase or private key into WordPress.

## Recommended server cron

For production, run WP-CLI cron every minute so payment detection does not depend on website traffic. The plugin settings page also displays these instructions.

Example crontab (adjust the WordPress path and WP-CLI path):

```cron
* * * * * cd /var/www/example.com && /usr/local/bin/wp cron event run --due-now >/dev/null 2>&1
```

Find WP-CLI with:

```bash
which wp
```

Check the plugin event:

```bash
wp cron event list | grep wc_usdt
```

Run the payment checker manually:

```bash
wp cron event run wc_usdt_trc20_check_payments
```

For production, if you set `DISABLE_WP_CRON` to `true`, use `wp cron event run --due-now` rather than running only the USDT event, so other WordPress scheduled tasks continue to run.

## Testnet

The gateway supports:
- Nile Testnet
- Shasta Testnet
- TRON Mainnet

Use a dedicated testnet wallet. Testnet assets have no real value.

## Payment flow

1. Customer checks out with USDT TRC20.
2. The plugin creates a unique USDT amount for the order.
3. Order becomes **On hold**.
4. Customer sends USDT to the configured wallet.
5. Cron calls TronGrid and scans confirmed TRC20 transfers.
6. The plugin verifies recipient, contract, amount, and TXID reuse.
7. The plugin calls WooCommerce `payment_complete()`.
8. WooCommerce moves the order to its normal paid status (normally **Processing**).
9. WooCommerce handles the normal Processing Order email according to its email settings.

## Current limitations

- One receiving wallet per configured gateway/network.
- Unique amount matching is used instead of per-order deposit addresses.
- Refunds are not automated.
- Wrong network/token/amount is not automatically recovered.
- Checkout Blocks support is not included yet.
- Production should add more robust payment expiry, underpayment/overpayment handling, and a customer-facing live payment status.


## v0.1.6

Adds QR code, copy buttons, and automatic browser payment-status polling on the order received page. The QR uses a TRON-style URI with the amount; wallet support for auto-filling amount varies, so copy buttons remain available. The MVP QR image is rendered through api.qrserver.com; for a privacy-focused production build, bundle a local QR generator.


## v0.1.7 QR compatibility

- QR now contains only the receiving TRON wallet address.
- Removed the generic `tron:...?...amount=` URI because mobile wallets do not consistently interpret it as a USDT payment request.
- Amount and network are displayed separately and can be entered/selected in the customer's wallet.
- Keeps Copy Address and Copy Amount as reliable fallbacks.


## v0.1.8 Binance QR helper

- Bundles the supplied Binance QR image as an optional payment-page helper.
- Adds an exact-amount copy button and explicit TRON/TRC20 instructions.
- The bundled QR is a fixed Binance link; it is NOT used as proof of payment.
- WooCommerce marks the order paid only when the configured receiving wallet receives and the plugin verifies the USDT TRC20 transaction.


## v0.1.9

- Added **Binance QR image URL** setting under WooCommerce > Settings > Payments > USDT TRC20.
- Checkout/thank-you payment UI now actually renders the configured Binance QR.
- Replaced the old generic `tron:...amount` QR with an address-only QR.
- Added prominent exact-amount display and Copy amount button.
- Added explicit TRON (TRC20) + USDT instructions.


## v0.2.0 — Clean payment UI

- Removed the old/duplicate QR presentation.
- Payment page now has two clean tabs: **Binance** and **Other wallets**.
- Binance tab uses the configured Binance QR image URL.
- Other wallets tab contains the receiving-address QR plus Copy Address / Copy Amount.
- Exact amount is shown once at the top and has a prominent Copy Amount button.
- Removed the bundled static Binance QR image; the store controls the QR through settings.


## v0.2.1 — UI cleanup

- Fixed payment tabs by handling tab switching inline, independent of theme asset loading.
- Copy amount is now a small centered button below the amount.
- Copy address / Copy amount buttons are smaller and centered on their own row.
- Improved spacing, tab styling, QR sizing, address layout, and mobile responsiveness.


## v0.2.2 — Cache bust + QR restore

- Fixed frontend CSS/JS cache busting (previously assets were still enqueued as version 0.1.6).
- Restored address QR generation and copy-button behavior.
- Kept the clean two-tab payment UI and smaller centered buttons.


## v0.2.3 — Final payment flow cleanup

- Removed the address QR from **Other wallets**.
- Other wallets now show only the receiving address, Copy address, network, and Copy amount.
- Payment instructions remain on the post-checkout payment page only.
- Checkout remains a normal WooCommerce checkout with the USDT gateway option; no payment QR/instructions are injected above the checkout form.


## v0.2.4
- Payment page now polls order status and changes to **Payment successful!** when the order becomes paid/processing/completed.
- Improved active tab styling.
- Removed the duplicate Copy amount button from Other wallets.
- Added clear Network and Amount detail rows below the receiving address.


## v0.2.6 — Cleaner payment status and instructions

- Removed WooCommerce's repeated order overview from the USDT thank-you page.
- Payment amount is displayed without unnecessary trailing zeros (for example, `11.74 USDT`).
- Added animated waiting indicator while payment is pending.
- Payment status polling now treats `processing` and `completed` as successful even if the order's paid flag was not set.
- Added a concise payment summary with network and receiving address near the top, including Copy address.
- Moved the key USDT payment instructions to the top of customer emails.
- Email copy buttons are intentionally not added because most email clients block JavaScript clipboard actions.


## v0.2.7 — Direct TXID verification
- Removed the repeated quick-payment Network/Receiving address block from the payment page.
- Verify payment now queries the submitted TXID directly through TronGrid confirmed transaction events.
- Added clear validation for destination, USDT contract, exact amount, payment window, and TXID reuse.
- Payment amount is displayed to customers with 2 decimal places.


## v0.2.8 — Robust TXID verification

- TXID verification now falls back from TronGrid transaction events to direct TRON transaction/receipt APIs.
- Decodes `transfer(address,uint256)` directly from the submitted transaction when the event endpoint does not return the transfer.
- Checks successful/confirmed transaction, USDT contract, destination, amount, timestamp, and TXID reuse.
- Frontend now surfaces invalid server responses instead of masking them as a generic verification error.


## v0.2.9 — TXID debug logging

- Added detailed `[USDT][TXID]` WooCommerce logs for nonce, order, payment method, amount, API calls, HTTP status, response body, transaction decoding, and final verification.
- Direct TRON transaction verification logs both `gettransactionbyid` and `gettransactioninfobyid`.
- Exceptions are logged with file/line and a short trace.
- Existing auto-detection flow is left intact.


## v0.2.10 — Stability fix
- Restored missing amount/USDT helper methods used by the payment page and scanner.
- Added a class-existence guard to prevent duplicate `WC_Gateway_USDT_TRC20` fatal errors if the class file is loaded more than once.
- Removed stray activation/deactivation hook calls from the gateway class file.
- Removed GMP dependency from TRON address conversion used by TXID verification.
- Kept detailed `[USDT][TXID]` debug logging.


## v0.2.11 — Restored auto scanner and internal method integrity

- Restored the missing `fetch_transactions()` method used by cron.
- Restored inbound USDT validation, transaction amount/timestamp helpers, and TRON address normalization.
- Restored explicit USDT contract constants for Mainnet, Nile, and Shasta.
- Fixed TXID verifier timeout setting to use the configured `timeout_minutes`.
- Verified PHP syntax for the gateway and main plugin files.
- Verified all `$this->method()` calls resolve to plugin methods or inherited WooCommerce methods.

## v0.2.12 — Fix admin-ajax handler registration

- AJAX handlers are registered at plugin level, not inside the gateway constructor.
- Added static AJAX entrypoints that instantiate the gateway and delegate to instance handlers.
- Prevents `admin-ajax.php` from returning `0` when WooCommerce has not instantiated the payment gateway.
- Payment status polling and manual TXID verification use the same reliable registration path.
