<?php
/**
 * WooCommerce USDT TRC20 Payment Gateway – main gateway class.
 *
 * Behaviour is split into focused traits:
 *  - WC_USDT_TRC20_Tron_Address   – Base58/hex address helpers.
 *  - WC_USDT_TRC20_Tron_Api       – TronGrid API calls.
 *  - WC_USDT_TRC20_Payment_Matching – Cron scanner & order matching.
 *  - WC_USDT_TRC20_Ajax_Handlers  – AJAX endpoints.
 *  - WC_USDT_TRC20_Frontend       – Thank-you page, emails, assets.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/traits/trait-tron-address.php';
require_once __DIR__ . '/traits/trait-tron-api.php';
require_once __DIR__ . '/traits/trait-payment-matching.php';
require_once __DIR__ . '/traits/trait-ajax-handlers.php';
require_once __DIR__ . '/traits/trait-frontend.php';
require_once __DIR__ . '/traits/trait-admin-notification.php';

if ( ! class_exists( 'WC_Gateway_USDT_TRC20' ) ) {

class WC_Gateway_USDT_TRC20 extends WC_Payment_Gateway {

    use WC_USDT_TRC20_Tron_Address;
    use WC_USDT_TRC20_Tron_Api;
    use WC_USDT_TRC20_Payment_Matching;
    use WC_USDT_TRC20_Ajax_Handlers;
    use WC_USDT_TRC20_Frontend;
    use WC_USDT_TRC20_Admin_Notification;

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    const META_AMOUNT  = '_usdt_trc20_amount';
    const META_ADDRESS = '_usdt_trc20_address';
    const META_TXID    = '_usdt_trc20_txid';
    const META_CREATED = '_usdt_trc20_created';
    const META_MATCHED = '_usdt_trc20_matched';

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    public $id                 = 'usdt_trc20';
    public $has_fields         = false;
    public $method_title       = 'USDT TRC20';
    public $method_description = 'Accept USDT on TRON (TRC20) directly to your own wallet and automatically verify payments through TronGrid.';

    private $wallet_address    = '';
    private $trongrid_api_key  = '';
    private $network           = 'mainnet';
    private $timeout_minutes   = 60;
    private $debug             = false;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct() {
        $this->id   = 'usdt_trc20';
        $this->icon = '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title',       'Pay with USDT (TRC20)' );
        $this->description = $this->get_option( 'description', 'Send USDT on the TRON network (TRC20). Your order will be processed after the blockchain payment is confirmed.' );

        $this->wallet_address   = trim( $this->get_option( 'wallet_address',   '' ) );
        $this->trongrid_api_key = trim( $this->get_option( 'trongrid_api_key', '' ) );
        $this->network          = $this->get_option( 'network',          'mainnet' );
        $this->timeout_minutes  = max( 5, absint( $this->get_option( 'timeout_minutes',  60 ) ) );
        $this->debug            = $this->get_option( 'debug', 'no' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id,                        [ $this, 'thankyou_page' ] );
        add_action( 'woocommerce_email_after_order_table',                      [ $this, 'email_instructions' ], 10, 4 );
        add_action( 'wp_enqueue_scripts',                                       [ $this, 'enqueue_frontend_assets' ] );
    }

    // -------------------------------------------------------------------------
    // Admin settings
    // -------------------------------------------------------------------------

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable USDT TRC20',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'default'     => 'Pay with USDT (TRC20)',
                'description' => 'Shown to customers at checkout.',
            ],
            'description' => [
                'title'   => 'Description',
                'type'    => 'textarea',
                'default' => 'Pay with USDT on the TRON network (TRC20).',
            ],
            'binance_qr_url' => [
                'title'       => 'Binance QR image URL',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Optional. Upload the Binance QR image to Media Library, copy its File URL, and paste it here. Leave empty to hide the Binance section.',
                'desc_tip'    => true,
            ],
            'network' => [
                'title'       => 'Network',
                'type'        => 'select',
                'default'     => 'mainnet',
                'options'     => [
                    'mainnet' => 'TRON Mainnet',
                    'nile'    => 'Nile Testnet',
                    'shasta'  => 'Shasta Testnet',
                ],
                'description' => 'Use Nile or Shasta for development/testing. Testnet USDT has no real value.',
            ],
            'wallet_address' => [
                'title'       => 'Receiving wallet address',
                'type'        => 'text',
                'description' => 'TRON receiving address for the selected network. Do NOT enter a private key.',
                'desc_tip'    => true,
            ],
            'trongrid_api_key' => [
                'title'       => 'TronGrid API key',
                'type'        => 'password',
                'description' => 'Required on Mainnet. Testnet TronGrid endpoints currently do not require an API key.',
                'desc_tip'    => true,
            ],
            'timeout_minutes' => [
                'title'             => 'Payment timeout (minutes)',
                'type'              => 'number',
                'default'           => 60,
                'custom_attributes' => [ 'min' => 5, 'step' => 5 ],
            ],
            'debug' => [
                'title'   => 'Debug logging',
                'type'    => 'checkbox',
                'label'   => 'Enable WooCommerce logs',
                'default' => 'no',
            ],
            'cron_instructions' => [
                'title'       => 'Automatic payment checker',
                'type'        => 'title',
                'description' => $this->cron_instructions_html(),
            ],
        ];
    }

    /**
     * Return true when the gateway has the minimum configuration needed to
     * process payments.
     */
    public function is_configured() {
        return $this->wallet_address !== ''
            && ( $this->network !== 'mainnet' || $this->trongrid_api_key !== '' );
    }

    // -------------------------------------------------------------------------
    // Payment processing
    // -------------------------------------------------------------------------

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Unable to create the crypto payment.', 'wc-usdt-trc20' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        if ( strtoupper( $order->get_currency() ) !== 'USD' ) {
            wc_add_notice( __( 'USDT TRC20 checkout currently requires the store currency to be USD.', 'wc-usdt-trc20' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        if ( ! $this->is_configured() ) {
            wc_add_notice( __( 'Crypto payment is temporarily unavailable.', 'wc-usdt-trc20' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        // Generate the unique payment amount exactly once per order.
        // If META_AMOUNT already exists (e.g. customer is retrying payment),
        // keep the original value so the customer's instructions never change.
        $amount = $order->get_meta( self::META_AMOUNT );
        if ( ! $amount ) {
            $amount = $this->make_unique_amount( $order );
        }

        $order->update_meta_data( self::META_AMOUNT,  $amount );
        $order->update_meta_data( self::META_ADDRESS, $this->wallet_address );
        $order->update_meta_data( self::META_CREATED, time() );
        $order->save();

        $order->update_status( 'on-hold', __( 'Awaiting USDT TRC20 payment.', 'wc-usdt-trc20' ) );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    /**
     * Generate a unique USDT payment amount for this order.
     *
     * A 4-digit random suffix is appended at decimal places 3–6, making the
     * amount distinguishable on-chain while keeping the visible cents unchanged.
     *
     * Example: order total 8.99  →  stored unique amount 8.990017
     *
     * Rules:
     * - The WooCommerce order total is NEVER modified.
     * - META_AMOUNT is written once (in process_payment) and never changed
     *   afterwards; if it already exists this function is not called again.
     * - Every candidate is checked against all active (pending/on-hold) orders
     *   before being accepted, including the timestamp-based fallback.
     * - Trailing zeros beyond the 2nd decimal place are stripped; the 2nd
     *   decimal place is always kept so "8.990000" displays as "8.99".
     *
     * @param  WC_Order $order
     * @return string   Unique USDT amount string, e.g. "8.990017"
     */
    private function make_unique_amount( $order ) {
        // Truncate (not round) to 2 decimal places to match the displayed total.
        $base  = (float) $order->get_total();
        $whole = floor( $base * 100 ) / 100;      // e.g. 8.99
        $base2 = number_format( $whole, 2, '.', '' );  // "8.99"

        $max_tries = 20;

        for ( $i = 0; $i < $max_tries; $i++ ) {
            // 4-digit suffix occupying decimal places 3–6.
            // Range 0001–9999 ensures the suffix is never all-zeros (which
            // would make the unique amount equal to the bare order total).
            $suffix    = str_pad( (string) wp_rand( 1, 9999 ), 4, '0', STR_PAD_LEFT );
            $candidate = $this->normalise_usdt_amount( $base2 . $suffix );

            if ( ! $this->unique_amount_in_use( $candidate, $order->get_id() ) ) {
                return $candidate;
            }

            $this->log( sprintf(
                '[USDT] Amount %s already in use, retrying (%d/%d)',
                $candidate, $i + 1, $max_tries
            ) );
        }

        // Fallback: derive suffix from microseconds — still goes through the
        // collision check so there is no code path that skips it.
        $usec      = (int) round( microtime( true ) * 10000 ) % 10000;
        $ts_suffix = str_pad( (string) max( 1, $usec ), 4, '0', STR_PAD_LEFT );
        $fallback  = $this->normalise_usdt_amount( $base2 . $ts_suffix );

        if ( ! $this->unique_amount_in_use( $fallback, $order->get_id() ) ) {
            return $fallback;
        }

        // Absolute last resort: sequential scan through all 9999 suffixes.
        // Practically unreachable unless > 9998 orders are pending simultaneously.
        for ( $n = 1; $n <= 9999; $n++ ) {
            $candidate = $this->normalise_usdt_amount(
                $base2 . str_pad( (string) $n, 4, '0', STR_PAD_LEFT )
            );
            if ( ! $this->unique_amount_in_use( $candidate, $order->get_id() ) ) {
                $this->log( '[USDT] Exhaustive suffix scan needed; found unique amount: ' . $candidate );
                return $candidate;
            }
        }

        // Should never be reached.  Log a critical error and surface it.
        $this->log( '[USDT] CRITICAL: could not generate a unique amount for order #' . $order->get_id() );
        return $base2 . '0001';  // return something rather than crashing
    }

    /**
     * Normalise a raw 6-decimal amount string.
     *
     * Input:  "8.99" + "0017"  →  concat = "8.990017"
     * Output: "8.990017"  (trailing zeros stripped after 2nd decimal place)
     *
     * Input:  "8.99" + "1000"  →  concat = "8.991000"
     * Output: "8.991"          (not "8.9910" — both have the same micro-unit value)
     *
     * The 2nd decimal place is always preserved so the displayed amount never
     * loses the cents portion of the original order total.
     *
     * @param  string $raw  e.g. "8.990017"
     * @return string
     */
    private function normalise_usdt_amount( $raw ) {
        if ( strpos( $raw, '.' ) === false ) {
            return $raw . '.00';
        }
        [ $integer, $frac ] = explode( '.', $raw, 2 );
        $frac = substr( $frac, 0, 6 );                   // cap at 6 decimal places
        $frac = str_pad( $frac, 2, '0' );                // ensure at least 2 places
        $frac = rtrim( $frac, '0' );                      // strip trailing zeros
        $frac = str_pad( $frac, 2, '0' );                // but keep minimum 2 places
        return $integer . '.' . $frac;
    }

    /**
     * Check whether a given USDT amount is already assigned to another
     * active (pending or on-hold) order for this gateway.
     *
     * Comparison is done using to_units() (integer micro-USDT) so that
     * "8.991" and "8.9910" are treated as the same amount regardless of how
     * the value was stored in the database.
     *
     * Only the current order ($order_id) is excluded from the check.
     *
     * @param  string $amount   Candidate unique amount string.
     * @param  int    $order_id The current order ID (excluded from the check).
     * @return bool
     */
    private function unique_amount_in_use( $amount, $order_id ) {
        $target_units = $this->to_units( $amount );

        // Fetch all active USDT orders except the current one.
        // We pull IDs only and then compare amounts as integers to be safe
        // against any trailing-zero variants that may exist in the database.
        $active_orders = wc_get_orders( [
            'limit'          => -1,
            'status'         => [ 'pending', 'on-hold' ],
            'payment_method' => $this->id,
            'return'         => 'objects',
            'exclude'        => [ absint( $order_id ) ],
        ] );

        foreach ( $active_orders as $order ) {
            $stored = $order->get_meta( self::META_AMOUNT );
            if ( $stored !== '' && $stored !== false && $stored !== null ) {
                if ( $this->to_units( (string) $stored ) === $target_units ) {
                    return true;
                }
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Admin cron instructions HTML
    // -------------------------------------------------------------------------

    private function cron_instructions_html() {
        $home      = wp_parse_url( home_url(), PHP_URL_HOST );
        $site_path = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : '/var/www/html';
        $wp_path   = '/usr/local/bin/wp';

        ob_start();
        ?>
        <div style="max-width:900px;line-height:1.6;">
            <p><strong>Recommended for production:</strong> run WP-CLI cron from the server every minute. This prevents payment detection from depending on site traffic.</p>
            <p><strong>Current site:</strong> <?php echo esc_html( $home ?: 'your-site' ); ?><br>
               <strong>Detected WordPress path:</strong> <code><?php echo esc_html( $site_path ); ?></code></p>

            <p><strong>Recommended crontab command:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code>* * * * * cd <?php echo esc_html( $site_path ); ?> &amp;&amp; <?php echo esc_html( $wp_path ); ?> cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></pre>

            <p>If your <code>wp</code> executable is somewhere else, find it with <code>which wp</code> and replace <code><?php echo esc_html( $wp_path ); ?></code>.</p>

            <p><strong>Payment checker only:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code>cd <?php echo esc_html( $site_path ); ?> &amp;&amp; <?php echo esc_html( $wp_path ); ?> cron event run wc_usdt_trc20_check_payments</code></pre>

            <p><strong>Important:</strong> Do not disable WordPress cron unless you also configure a real server cron.</p>

            <p><strong>Check the scheduled event:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code><?php echo esc_html( $wp_path ); ?> cron event list | grep wc_usdt</code></pre>

            <p><strong>Debug:</strong> when Debug logging is enabled, check <em>WooCommerce &gt; Status &gt; Logs</em> for source <code>usdt-trc20</code>.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    /**
     * Write a message to the WooCommerce logger when debug mode is on.
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( ! $this->debug ) {
            return;
        }
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info( $message, [ 'source' => 'usdt-trc20' ] );
        }
    }
}

} // end class_exists guard
