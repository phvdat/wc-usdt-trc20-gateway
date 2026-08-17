<?php
/**
 * Trait: AJAX request handlers.
 * Handles the payment-status polling endpoint and the customer TXID
 * verification endpoint.
 *
 * Depends on: WC_USDT_TRC20_Tron_Address, WC_USDT_TRC20_Tron_Api,
 *             WC_USDT_TRC20_Payment_Matching.
 */
trait WC_USDT_TRC20_Ajax_Handlers {

    // -------------------------------------------------------------------------
    // Static dispatchers (hooked in the plugin bootstrap)
    // -------------------------------------------------------------------------

    /** @internal Called by wp_ajax_* hooks. */
    public static function ajax_payment_status() {
        ( new self() )->handle_ajax_payment_status();
    }

    /** @internal Called by wp_ajax_* hooks. */
    public static function ajax_verify_txid() {
        ( new self() )->handle_ajax_verify_txid();
    }

    // -------------------------------------------------------------------------
    // Payment-status polling
    // -------------------------------------------------------------------------

    /**
     * Return the current paid/unpaid status of a WooCommerce order.
     * Used by the front-end to update the thank-you page without a full reload.
     */
    private function handle_ajax_payment_status() {
        check_ajax_referer( 'wc_usdt_trc20_status', 'nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $order     = wc_get_order( $order_id );

        if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
            wp_send_json_error( [ 'message' => 'Invalid order.' ], 403 );
        }

        if ( $order->get_payment_method() !== $this->id ) {
            wp_send_json_error( [ 'message' => 'Invalid payment method.' ], 400 );
        }

        $status = $order->get_status();
        wp_send_json_success( [
            'status' => $status,
            'paid'   => $order->is_paid() || in_array( $status, [ 'processing', 'completed' ], true ),
            'txid'   => (string) $order->get_meta( self::META_TXID ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Customer TXID verification
    // -------------------------------------------------------------------------

    /**
     * Accept a customer-supplied TXID, verify it on the TRON blockchain, and
     * mark the order as paid if valid.
     */
    private function handle_ajax_verify_txid() {
        $this->log( '[USDT][TXID] Verify request started' );

        try {
            $nonce     = isset( $_POST['nonce'] )     ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )     : '';
            $order_id  = isset( $_POST['order_id'] )  ? absint( $_POST['order_id'] )                             : 0;
            $order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
            $txid      = isset( $_POST['txid'] )      ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['txid'] ) ) ) ) : '';

            $this->log( sprintf(
                '[USDT][TXID] Input order=%d txid=%s nonce_present=%s order_key_present=%s',
                $order_id,
                $txid ? substr( $txid, 0, 12 ) . '...' : '(empty)',
                $nonce     ? 'YES' : 'NO',
                $order_key ? 'YES' : 'NO'
            ) );

            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wc_usdt_trc20_verify_txid' ) ) {
                $this->log( '[USDT][TXID] FAIL: invalid nonce' );
                wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'wc-usdt-trc20' ) ], 400 );
            }

            if ( ! $order_id || ! $txid ) {
                $this->log( '[USDT][TXID] FAIL: missing order_id or txid' );
                wp_send_json_error( [ 'message' => __( 'Order ID or transaction ID is missing.', 'wc-usdt-trc20' ) ], 400 );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $this->log( '[USDT][TXID] FAIL: order not found' );
                wp_send_json_error( [ 'message' => __( 'Order not found.', 'wc-usdt-trc20' ) ], 404 );
            }

            $this->log( sprintf(
                '[USDT][TXID] Order loaded: #%d status=%s payment_method=%s total=%s',
                $order_id,
                $order->get_status(),
                $order->get_payment_method(),
                $order->get_total()
            ) );

            if ( $order_key && ! hash_equals( $order->get_order_key(), $order_key ) ) {
                $this->log( '[USDT][TXID] FAIL: order key mismatch' );
                wp_send_json_error( [ 'message' => __( 'Invalid order key.', 'wc-usdt-trc20' ) ], 403 );
            }

            if ( $order->get_payment_method() !== $this->id ) {
                $this->log( sprintf(
                    '[USDT][TXID] FAIL: payment method mismatch expected=%s actual=%s',
                    $this->id,
                    $order->get_payment_method()
                ) );
                wp_send_json_error( [ 'message' => __( 'Invalid payment method for this order.', 'wc-usdt-trc20' ) ], 400 );
            }

            // Already paid?
            if ( $order->is_paid() ) {
                $saved_tx = $order->get_meta( self::META_TXID );
                $this->log( sprintf(
                    '[USDT][TXID] Order already paid. saved_tx=%s',
                    $saved_tx ? substr( (string) $saved_tx, 0, 12 ) . '...' : '(none)'
                ) );
                if ( $saved_tx && hash_equals( strtolower( (string) $saved_tx ), $txid ) ) {
                    wp_send_json_success( [
                        'message' => __( 'Payment successful! Your payment has already been verified.', 'wc-usdt-trc20' ),
                        'status'  => $order->get_status(),
                    ] );
                }
                wp_send_json_error( [ 'message' => __( 'This order has already been paid.', 'wc-usdt-trc20' ) ], 400 );
            }

            $expected_amount = (float) $order->get_meta( self::META_AMOUNT );
            if ( $expected_amount <= 0 ) {
                $expected_amount = (float) $order->get_total();
            }

            $address  = trim( (string) $order->get_meta( self::META_ADDRESS ) );
            $created  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
            $timeout  = max( 1, (int) $this->get_option( 'timeout_minutes', $this->timeout_minutes ) );
            $deadline = $created + ( $timeout * 60 );

            $this->log( sprintf(
                '[USDT][TXID] Expected amount=%s address=%s created=%s deadline=%s network=%s',
                wc_format_decimal( $expected_amount, 6 ),
                $address,
                gmdate( 'c', $created ),
                gmdate( 'c', $deadline ),
                $this->network
            ) );

            // If cron already recorded this TX for the order, accept it.
            $saved_tx = strtolower( trim( (string) $order->get_meta( self::META_TXID ) ) );
            if ( $saved_tx && hash_equals( $saved_tx, $txid ) ) {
                $this->log( '[USDT][TXID] TXID matches transaction already saved by auto-check' );
                wp_send_json_success( [
                    'message' => __( 'Payment successful! Your payment has been detected and verified.', 'wc-usdt-trc20' ),
                    'status'  => $order->get_status(),
                ] );
            }

            $this->log( '[USDT][TXID] Calling direct transaction verification...' );
            $result = $this->verify_transaction_by_id( $txid, $address, $expected_amount, $created, $deadline );

            if ( is_wp_error( $result ) ) {
                $this->log( sprintf(
                    '[USDT][TXID] VERIFY FAIL code=%s message=%s',
                    $result->get_error_code(),
                    $result->get_error_message()
                ) );
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
            }

            $this->log( '[USDT][TXID] Blockchain verification passed' );

            // Prevent the same TX from being used for a different order.
            $used = $this->find_order_by_txid( $txid );
            if ( $used && (int) $used->get_id() !== $order_id ) {
                $this->log( sprintf( '[USDT][TXID] FAIL: TX already used by order #%d', $used->get_id() ) );
                wp_send_json_error( [ 'message' => __( 'This transaction has already been used for another order.', 'wc-usdt-trc20' ) ], 400 );
            }

            $order->update_meta_data( self::META_TXID, $txid );
            $order->payment_complete( $txid );
            $order->add_order_note( sprintf( 'USDT payment verified by customer with TX %s', $txid ) );
            $order->save();

            $this->log( sprintf( '[USDT][TXID] SUCCESS: Order #%d paid with TX %s', $order_id, $txid ) );

            wp_send_json_success( [
                'message' => __( 'Payment successful! Your payment has been detected and verified.', 'wc-usdt-trc20' ),
                'status'  => $order->get_status(),
            ] );

        } catch ( \Throwable $e ) {
            $this->log( sprintf(
                '[USDT][TXID] EXCEPTION %s: %s in %s:%d',
                get_class( $e ),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ) );
            wp_send_json_error( [ 'message' => __( 'Unable to verify this transaction. Check the WooCommerce log for details.', 'wc-usdt-trc20' ) ], 500 );
        }
    }
}
