<?php
/**
 * Trait: Automatic payment matching (cron scanner).
 * Scans pending orders and matches them against confirmed TRON transactions.
 *
 * Depends on: WC_USDT_TRC20_Tron_Address, WC_USDT_TRC20_Tron_Api.
 */
trait WC_USDT_TRC20_Payment_Matching {

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * Scan all pending USDT orders and attempt to match them with confirmed
     * on-chain transfers. Called once per cron tick.
     *
     * Because each order now carries a UNIQUE payment amount, matching is
     * always unambiguous: find a TX whose amount equals the order's stored
     * META_AMOUNT, within the order's time window.
     */
    public function scan_and_match() {
        $pending_orders = wc_get_orders( [
            'limit'          => 100,
            'status'         => [ 'pending', 'on-hold' ],
            'payment_method' => $this->id,
            'return'         => 'objects',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ] );

        if ( ! $pending_orders ) {
            return;
        }

        $now            = time();
        $oldest_allowed = $now - ( $this->timeout_minutes * 60 );
        $active_orders  = [];   // order_id => [ 'order', 'created', 'units' ]
        $scan_from_ts   = PHP_INT_MAX;

        foreach ( $pending_orders as $order ) {
            // Skip orders already matched.
            if ( $order->get_meta( self::META_TXID ) ) {
                continue;
            }

            $created = (int) $order->get_meta( self::META_CREATED );
            if ( ! $created ) {
                $date    = $order->get_date_created();
                $created = $date ? $date->getTimestamp() : $now;
            }

            // Expire timed-out orders.
            if ( $created < $oldest_allowed ) {
                $order->update_status( 'failed', __( 'USDT payment timed out.', 'wc-usdt-trc20' ) );
                continue;
            }

            $amount = $order->get_meta( self::META_AMOUNT );
            if ( ! $amount ) {
                continue;
            }

            $active_orders[ $order->get_id() ] = [
                'order'   => $order,
                'created' => $created,
                'units'   => $this->to_units( $amount ),
            ];

            if ( $created < $scan_from_ts ) {
                $scan_from_ts = $created;
            }
        }

        if ( ! $active_orders ) {
            return;
        }

        // Build a lookup: amount_units => order_id (unique — one order per amount).
        $units_to_order = [];
        foreach ( $active_orders as $oid => $data ) {
            $units_to_order[ $data['units'] ] = $oid;
        }

        $transactions = $this->fetch_transactions( $scan_from_ts );

        foreach ( $transactions as $tx ) {
            if ( ! $this->is_valid_inbound_usdt( $tx ) ) {
                continue;
            }

            $txid = isset( $tx['transaction_id'] ) ? sanitize_text_field( $tx['transaction_id'] ) : '';
            if ( ! $txid ) {
                continue;
            }

            if ( $this->txid_already_used( $txid ) ) {
                continue;
            }

            $tx_units = $this->to_units( $this->transaction_amount( $tx ) );
            if ( ! isset( $units_to_order[ $tx_units ] ) ) {
                // No active order expects this exact amount — ignore.
                continue;
            }

            $oid     = $units_to_order[ $tx_units ];
            $data    = $active_orders[ $oid ];
            $order   = $data['order'];
            $created = $data['created'];
            $ts      = $this->transaction_timestamp( $tx );
            $deadline = $created + ( $this->timeout_minutes * 60 );

            if ( ! $ts ) {
                continue;
            }

            // Timestamp must fall within the order's payment window.
            if ( $ts < $created || $ts > $deadline ) {
                $this->log( sprintf(
                    '[USDT][AUTO] TX %s timestamp %s outside window for order #%d [%s – %s]. Skipping.',
                    $txid,
                    gmdate( 'c', $ts ),
                    $oid,
                    gmdate( 'c', $created ),
                    gmdate( 'c', $deadline )
                ) );
                continue;
            }

            // Final duplicate guard before writing.
            if ( $this->txid_already_used( $txid ) ) {
                $this->log( '[USDT][AUTO] TX ' . $txid . ' claimed by another order before pairing. Skipping.' );
                continue;
            }

            $this->mark_paid( $order, $tx );

            // Remove from the lookup so we don't process the same order twice
            // if a duplicate TX somehow appears in the API response.
            unset( $units_to_order[ $tx_units ], $active_orders[ $oid ] );
        }
    }

    // -------------------------------------------------------------------------
    // Marking helpers
    // -------------------------------------------------------------------------

    /**
     * Mark an order as paid and record the matching transaction.
     *
     * @param WC_Order $order
     * @param array    $tx
     */
    private function mark_paid( $order, $tx ) {
        $txid   = sanitize_text_field( $tx['transaction_id'] );
        $amount = $this->transaction_amount( $tx );
        $from   = isset( $tx['from'] ) ? sanitize_text_field( $tx['from'] ) : '';

        $order->update_meta_data( self::META_TXID,    $txid );
        $order->update_meta_data( self::META_MATCHED, current_time( 'mysql' ) );
        $order->save();

        $order->payment_complete( $txid );
        $order->add_order_note( sprintf(
            'USDT TRC20 payment verified. Amount: %s USDT. TXID: %s. From: %s',
            $amount,
            $txid,
            $from
        ) );

        $this->log( 'Order #' . $order->get_id() . ' paid with TX ' . $txid );
    }

    /**
     * Check whether a TXID has already been used for any WooCommerce order.
     *
     * @param  string $txid
     * @return bool
     */
    private function txid_already_used( $txid ) {
        $orders = wc_get_orders( [
            'limit'      => 1,
            'meta_key'   => self::META_TXID,
            'meta_value' => $txid,
            'return'     => 'ids',
        ] );
        return ! empty( $orders );
    }

    /**
     * Find a WooCommerce order that has a given TXID stored in its meta.
     *
     * @param  string $txid
     * @return WC_Order|false
     */
    private function find_order_by_txid( $txid ) {
        $orders = wc_get_orders( [
            'limit'      => 10,
            'status'     => array_keys( wc_get_order_statuses() ),
            'meta_key'   => self::META_TXID,
            'meta_value' => $txid,
            'return'     => 'objects',
        ] );
        return ! empty( $orders ) ? $orders[0] : false;
    }

    // -------------------------------------------------------------------------
    // Transaction field accessors
    // -------------------------------------------------------------------------

    /**
     * Return true if $tx is a confirmed inbound USDT TRC20 transfer to the
     * configured wallet address.
     *
     * @param  mixed $tx
     * @return bool
     */
    private function is_valid_inbound_usdt( $tx ) {
        if ( ! is_array( $tx ) ) {
            return false;
        }
        $config = $this->network_config();
        $to     = isset( $tx['to'] ) ? $this->normalize_tron_address( (string) $tx['to'] ) : '';
        $token  = isset( $tx['token_info']['address'] ) ? (string) $tx['token_info']['address'] : '';
        $symbol = isset( $tx['token_info']['symbol'] ) ? strtoupper( (string) $tx['token_info']['symbol'] ) : '';

        return $to === $this->wallet_address
            && ( ! $token  || strcasecmp( $token, $config['usdt_contract'] ) === 0 )
            && ( ! $symbol || $symbol === 'USDT' )
            && ! empty( $tx['transaction_id'] );
    }

    /**
     * Extract the USDT amount (as a decimal string) from a transaction array.
     *
     * @param  array $tx
     * @return string e.g. "100.50"
     */
    private function transaction_amount( $tx ) {
        $value    = isset( $tx['value'] ) ? (string) $tx['value'] : '0';
        $decimals = isset( $tx['token_info']['decimals'] ) ? (int) $tx['token_info']['decimals'] : 6;

        if ( $decimals === 6 ) {
            $units = ltrim( $value, '0' );
            if ( $units === '' ) {
                return '0';
            }
            if ( strlen( $units ) <= 6 ) {
                return '0.' . str_pad( $units, 6, '0', STR_PAD_LEFT );
            }
            return substr( $units, 0, -6 ) . '.' . substr( $units, -6 );
        }

        return rtrim( rtrim( number_format( ( (float) $value ) / pow( 10, $decimals ), 8, '.', '' ), '0' ), '.' );
    }

    /**
     * Extract the transaction timestamp (Unix seconds) from a transaction array.
     *
     * @param  array $tx
     * @return int
     */
    private function transaction_timestamp( $tx ) {
        if ( isset( $tx['timestamp'] ) ) {
            return (int) $tx['timestamp'];
        }
        if ( isset( $tx['block_timestamp'] ) ) {
            return (int) floor( ( (int) $tx['block_timestamp'] ) / 1000 );
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // Amount helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a decimal USDT amount string to micro-USDT integer units.
     *
     * @param  string|float $amount e.g. "100.50" or "100.500000"
     * @return int                  e.g. 100500000
     */
    private function to_units( $amount ) {
        $value = trim( (string) $amount );
        if ( $value === '' ) {
            return 0;
        }
        $parts = explode( '.', $value, 2 );
        $whole = preg_replace( '/\D/', '', $parts[0] ?? '0' );
        $frac  = preg_replace( '/\D/', '', $parts[1] ?? '' );
        $frac  = str_pad( substr( $frac, 0, 6 ), 6, '0' );
        return (int) $whole * 1_000_000 + (int) $frac;
    }
}
