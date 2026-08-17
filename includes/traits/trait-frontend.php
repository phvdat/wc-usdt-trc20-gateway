<?php
/**
 * Trait: Front-end rendering.
 * Handles the thank-you page, email instructions, and asset enqueue.
 */
trait WC_USDT_TRC20_Frontend {

    // -------------------------------------------------------------------------
    // Asset enqueue
    // -------------------------------------------------------------------------

    /**
     * Enqueue CSS and JS on the order-received (thank-you) page, but only for
     * orders that use this gateway.
     */
    public function enqueue_frontend_assets() {
        if ( ! is_order_received_page() ) {
            return;
        }
        $order_id = absint( get_query_var( 'order-received' ) );
        if ( ! $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $assets_url = plugins_url( 'assets/', WC_USDT_TRC20_PLUGIN_FILE );

        wp_enqueue_style(
            'wc-usdt-trc20',
            $assets_url . 'usdt-trc20.css',
            [],
            '0.2.12'
        );
        wp_enqueue_script(
            'wc-usdt-trc20',
            $assets_url . 'usdt-trc20.js',
            [],
            '0.2.12',
            true
        );
        wp_localize_script( 'wc-usdt-trc20', 'WCUSDTTRC20', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'wc_usdt_trc20_status' ),
            'orderId'     => $order_id,
            'orderKey'    => $order->get_order_key(),
            'interval'    => 8000,
            'verifyNonce' => wp_create_nonce( 'wc_usdt_trc20_verify_txid' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Thank-you page
    // -------------------------------------------------------------------------

    /**
     * Render the USDT payment card on the WooCommerce order-received page.
     *
     * @param int $order_id
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $amount         = $order->get_meta( self::META_AMOUNT );
        $display_amount = $this->format_display_amount( $amount );
        $address        = $order->get_meta( self::META_ADDRESS );
        if ( ! $amount || ! $address ) {
            return;
        }

        $txid           = $order->get_meta( self::META_TXID );
        $network_label  = $this->get_network_label();
        $binance_qr_url = trim( (string) $this->get_option( 'binance_qr_url', '' ) );
        ?>
        <section id="wc-usdt-trc20-payment" class="wc-usdt-trc20-payment">
          <div class="wc-usdt-trc20-card">
            <h2><?php echo esc_html__( 'USDT Payment', 'wc-usdt-trc20' ); ?></h2>

            <div class="wc-usdt-trc20-status" data-state="<?php echo $txid ? 'paid' : 'waiting'; ?>">
              <div class="wc-usdt-trc20-waiting-indicator" aria-hidden="true"><i></i><i></i><i></i></div>
              <strong class="wc-usdt-trc20-status-title">
                <?php echo $txid
                    ? esc_html__( 'Payment successful!', 'wc-usdt-trc20' )
                    : esc_html__( 'Waiting for payment', 'wc-usdt-trc20' ); ?>
              </strong>
              <span class="wc-usdt-trc20-status-message">
                <?php echo $txid
                    ? esc_html__( 'Your payment has been detected and verified. Your order is being processed.', 'wc-usdt-trc20' )
                    : esc_html__( 'We will automatically detect your payment.', 'wc-usdt-trc20' ); ?>
              </span>
              <?php if ( $txid ) : ?>
                <code class="wc-usdt-trc20-txid"><?php echo esc_html( $txid ); ?></code>
              <?php endif; ?>
            </div>

            <div class="wc-usdt-trc20-amount-box">
              <span><?php echo esc_html__( 'Send exactly', 'wc-usdt-trc20' ); ?></span>
              <strong><?php echo esc_html( $display_amount ); ?> USDT</strong>
              <button type="button" class="button wc-usdt-trc20-copy-amount" data-copy="<?php echo esc_attr( $amount ); ?>">
                <?php echo esc_html__( 'Copy amount', 'wc-usdt-trc20' ); ?>
              </button>
              <small><?php echo esc_html__( 'Please send the exact amount shown above.', 'wc-usdt-trc20' ); ?></small>
            </div>

            <div class="wc-usdt-trc20-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Payment method', 'wc-usdt-trc20' ); ?>">
                <button type="button" class="wc-usdt-trc20-tab is-active" role="tab"
                        aria-selected="true"
                        aria-controls="wc-usdt-tab-wallet"
                        data-tab="wallet">
                    <?php echo esc_html__( 'Pay with Crypto', 'wc-usdt-trc20' ); ?>
                </button>

                <?php if ( $binance_qr_url ) : ?>
                    <button type="button" class="wc-usdt-trc20-tab" role="tab"
                            aria-selected="false"
                            aria-controls="wc-usdt-tab-binance"
                            data-tab="binance">
                        <?php echo esc_html__( 'Binance', 'wc-usdt-trc20' ); ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ( $binance_qr_url ) : ?>
              <div id="wc-usdt-tab-binance"
                  class="wc-usdt-trc20-tab-panel"
                  data-panel="binance"
                  role="tabpanel">
                <h3><?php echo esc_html__( 'Pay with Binance', 'wc-usdt-trc20' ); ?></h3>
                <p class="wc-usdt-trc20-instruction">
                  <?php echo esc_html__( 'Scan the QR with the Binance app. Make sure the payment is sent as USDT on TRON (TRC20) for the exact amount shown above.', 'wc-usdt-trc20' ); ?>
                </p>
                <img class="wc-usdt-trc20-binance-qr"
                     src="<?php echo esc_url( $binance_qr_url ); ?>"
                     alt="<?php echo esc_attr__( 'Binance payment QR', 'wc-usdt-trc20' ); ?>">
              </div>
            <?php endif; ?>

            <div id="wc-usdt-tab-wallet"
                class="wc-usdt-trc20-tab-panel is-active"
                data-panel="wallet"
                role="tabpanel">
              <h3><?php echo esc_html__( 'Pay with Crypto', 'wc-usdt-trc20' ); ?></h3>
              <p class="wc-usdt-trc20-instruction">
                <?php echo esc_html__( 'Send USDT to the receiving address below using the TRON (TRC20) network.', 'wc-usdt-trc20' ); ?>
              </p>

              <div class="wc-usdt-trc20-address-box">
                <label><?php echo esc_html__( 'Receiving address', 'wc-usdt-trc20' ); ?></label>
                <code><?php echo esc_html( $address ); ?></code>
                <button type="button" class="button" data-copy="<?php echo esc_attr( $address ); ?>">
                  <?php echo esc_html__( 'Copy address', 'wc-usdt-trc20' ); ?>
                </button>
              </div>

              <div class="wc-usdt-trc20-payment-details">
                <div class="wc-usdt-trc20-detail-row">
                  <span><?php echo esc_html__( 'Network', 'wc-usdt-trc20' ); ?></span>
                  <strong><?php echo esc_html( $network_label ); ?> — USDT (TRC20)</strong>
                </div>
                <div class="wc-usdt-trc20-detail-row">
                  <span><?php echo esc_html__( 'Amount', 'wc-usdt-trc20' ); ?></span>
                  <strong><?php echo esc_html( $display_amount ); ?> USDT</strong>
                </div>
              </div>
              <?php if ( ! $txid ) : ?>
                <div class="wc-usdt-trc20-verify">
                  <h3><?php echo esc_html__( 'Already paid?', 'wc-usdt-trc20' ); ?></h3>
                  <p><?php echo esc_html__( 'If you have completed the transfer, paste your Transaction ID (TXID) below. We will verify it directly on the TRON blockchain.', 'wc-usdt-trc20' ); ?></p>
                  <label for="wc-usdt-trc20-txid-input"><?php echo esc_html__( 'Transaction ID (TXID)', 'wc-usdt-trc20' ); ?></label>
                  <input id="wc-usdt-trc20-txid-input" type="text" autocomplete="off" spellcheck="false"
                        placeholder="<?php echo esc_attr__( 'Paste your TRON transaction ID', 'wc-usdt-trc20' ); ?>">
                  <button type="button" class="button wc-usdt-trc20-verify-button">
                    <?php echo esc_html__( 'Verify payment', 'wc-usdt-trc20' ); ?>
                  </button>
                  <div class="wc-usdt-trc20-verify-result" aria-live="polite"></div>
                </div>
              <?php endif; ?>
            </div>

            <p class="wc-usdt-trc20-warning">
              <strong><?php echo esc_html__( 'Important:', 'wc-usdt-trc20' ); ?></strong>
              <?php echo esc_html__( 'Send USDT on TRON (TRC20) only. Sending another token or using another network will not be recognized automatically.', 'wc-usdt-trc20' ); ?>
            </p>

          </div>
        </section>
        <?php
    }

    // -------------------------------------------------------------------------
    // Email instructions
    // -------------------------------------------------------------------------

    /**
     * Append USDT payment instructions to WooCommerce order emails.
     *
     * @param WC_Order $order
     * @param bool     $sent_to_admin
     * @param bool     $plain_text
     * @param WC_Email $email
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $amount         = $order->get_meta( self::META_AMOUNT );
        $display_amount = $this->format_display_amount( $amount );
        $address        = $order->get_meta( self::META_ADDRESS );
        if ( ! $amount || ! $address ) {
            return;
        }

        $network_label = $this->get_network_label();

        if ( $plain_text ) {
            echo "\n" . __( 'USDT TRC20 Payment', 'wc-usdt-trc20' ) . "\n";
            // translators: 1: USDT amount, 2: network label
            echo sprintf( __( 'Send exactly %1$s USDT on %2$s to:', 'wc-usdt-trc20' ), $display_amount, $network_label ) . "\n";
            echo $address . "\n\n";
            echo __( 'Receiving address: copy this address exactly. Network: USDT (TRC20).', 'wc-usdt-trc20' ) . "\n\n";
            return;
        }

        echo '<div style="margin:0 0 22px;padding:18px 20px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">';
        echo '<h2 style="margin:0 0 10px;font-size:20px;">' . esc_html__( 'USDT TRC20 Payment', 'wc-usdt-trc20' ) . '</h2>';
        // translators: 1: USDT amount, 2: network label
        echo '<p style="margin:0 0 12px;line-height:1.6;">' . esc_html( sprintf( __( 'Send exactly %1$s USDT on %2$s to:', 'wc-usdt-trc20' ), $display_amount, $network_label ) ) . '</p>';
        echo '<p style="margin:0 0 10px;"><strong>' . esc_html__( 'Receiving address', 'wc-usdt-trc20' ) . '</strong></p>';
        echo '<p style="margin:0 0 10px;"><code style="display:block;padding:12px;background:#fff;border:1px solid #ddd;border-radius:6px;word-break:break-all;font-size:13px;">' . esc_html( $address ) . '</code></p>';
        echo '<p style="margin:0;font-size:12px;color:#666;">' . esc_html__( 'Network: USDT (TRC20). Please copy the address exactly and send the exact amount shown above.', 'wc-usdt-trc20' ) . '</p>';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Shared display helpers
    // -------------------------------------------------------------------------

    /**
     * Format a USDT amount for display, stripping trailing zeros.
     *
     * @param  string|float $amount
     * @return string
     */
    private function format_display_amount( $amount ) {
        return rtrim( rtrim( number_format( (float) $amount, 6, '.', '' ), '0' ), '.' );
    }

    /**
     * Return a human-readable label for the currently configured network.
     *
     * @return string
     */
    private function get_network_label() {
        switch ( $this->network ) {
            case 'nile':    return 'TRON (Nile Testnet)';
            case 'shasta':  return 'TRON (Shasta Testnet)';
            default:        return 'TRON Mainnet';
        }
    }
}
