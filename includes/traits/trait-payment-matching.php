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

        $now             = time();
        $oldest_allowed  = $now - ( $this->timeout_minutes * 60 );
        $orders_by_amount = [];
        $order_created    = [];

        foreach ( $pending_orders as $order ) {
            if ( $order->get_meta( self::META_TXID ) ) {
                continue;
            }

            $created = (int) $order->get_meta( self::META_CREATED );
            if ( ! $created ) {
                $date    = $order->get_date_created();
                $created = $date ? $date->getTimestamp() : $now;
            }
            $order_created[ $order->get_id() ] = $created;

            if ( $created < $oldest_allowed ) {
                $order->update_status( 'failed', __( 'USDT payment timed out.', 'wc-usdt-trc20' ) );
                continue;
            }

            $amount = $order->get_meta( self::META_AMOUNT );
            if ( ! $amount ) {
                continue;
            }

            $key                      = $this->to_units( $amount );
            $orders_by_amount[ $key ][] = $order;
        }

        if ( ! $orders_by_amount ) {
            return;
        }

        $scan_from    = min( $order_created ?: [ $now ] );
        $transactions = $this->fetch_transactions( $scan_from );

        // Build map: amount_units => [ valid TX entries ]
        $txs_by_amount = [];

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

            $units = $this->to_units( $this->transaction_amount( $tx ) );
            if ( ! isset( $orders_by_amount[ $units ] ) ) {
                continue;
            }

            if ( ! $this->transaction_timestamp( $tx ) ) {
                continue;
            }

            $txs_by_amount[ $units ][] = $tx;
        }

        // Match per amount group
        foreach ( $txs_by_amount as $units => $txs ) {
            $this->match_amount_group( $units, $txs, $orders_by_amount, $order_created );
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to pair transactions with orders that share the same USDT amount.
     *
     * To remain deterministic:
     * - Both orders and transactions are sorted by timestamp ASC.
     * - If fewer TXs than orders exist in the group, none are matched (ambiguous).
     *
     * @param int   $units            Amount expressed in micro-USDT units.
     * @param array $txs              Candidate transactions for this amount.
     * @param array $orders_by_amount Map of amount_units => WC_Order[].
     * @param array $order_created    Map of order_id => creation timestamp.
     */
    private function match_amount_group( $units, $txs, $orders_by_amount, $order_created ) {
        $group_orders = $orders_by_amount[ $units ];

        $earliest_order_created = PHP_INT_MAX;
        foreach ( $group_orders as $o ) {
            $c = $order_created[ $o->get_id() ] ?? 0;
            if ( $c < $earliest_order_created ) {
                $earliest_order_created = $c;
            }
        }

        // Keep only TXs that arrived at or after the earliest order.
        $valid_txs = array_filter( $txs, function ( $tx ) use ( $earliest_order_created ) {
            return $this->transaction_timestamp( $tx ) >= $earliest_order_created;
        } );
        $valid_txs = array_values( $valid_txs );

        if ( ! $valid_txs ) {
            return;
        }

        $order_count = count( $group_orders );
        $tx_count    = count( $valid_txs );

        if ( $tx_count < $order_count ) {
            $this->log( sprintf(
                '[USDT][AUTO] Ambiguous amount group %s USDT: %d order(s) but only %d valid TX(s). Waiting.',
                $this->transaction_amount( $valid_txs[0] ),
                $order_count,
                $tx_count
            ) );
            return;
        }

        // Sort TXs ASC, orders ASC — pair by index.
        usort( $valid_txs, function ( $a, $b ) {
            return $this->transaction_timestamp( $a ) - $this->transaction_timestamp( $b );
        } );

        $sorted_orders = $group_orders;
        usort( $sorted_orders, function ( $a, $b ) use ( $order_created ) {
            return ( $order_created[ $a->get_id() ] ?? 0 ) - ( $order_created[ $b->get_id() ] ?? 0 );
        } );

        for ( $i = 0; $i < $order_count; $i++ ) {
            $candidate = $sorted_orders[ $i ];
            $tx        = $valid_txs[ $i ];
            $created   = $order_created[ $candidate->get_id() ] ?? 0;
            $ts        = $this->transaction_timestamp( $tx );
            $deadline  = $created + ( $this->timeout_minutes * 60 );

            if ( $ts < $created || $ts > $deadline ) {
                $this->log( sprintf(
                    '[USDT][AUTO] TX %s timestamp %s outside window for order #%d [%s – %s]. Skipping.',
                    sanitize_text_field( $tx['transaction_id'] ),
                    gmdate( 'c', $ts ),
                    $candidate->get_id(),
                    gmdate( 'c', $created ),
                    gmdate( 'c', $deadline )
                ) );
                continue;
            }

            $txid = sanitize_text_field( $tx['transaction_id'] );
            if ( $this->txid_already_used( $txid ) ) {
                $this->log( '[USDT][AUTO] TX ' . $txid . ' was claimed by another order before pairing. Skipping.' );
                continue;
            }

            $this->mark_paid( $candidate, $tx );
        }
    }

    /**
     * Validate a transaction against a specific order (used by TXID submission).
     *
     * @param  WC_Order $order
     * @param  array    $tx
     * @param  int      $created Unix timestamp when the order was created.
     * @return array{valid:bool, message:string}
     */
    private function validate_transaction_for_order( $order, $tx, $created ) {
        if ( ! $this->is_valid_inbound_usdt( $tx ) ) {
            return [
                'valid'   => false,
                'message' => __( 'This TXID is not a confirmed USDT TRC20 transfer to the store wallet.', 'wc-usdt-trc20' ),
            ];
        }

        $timestamp = $this->transaction_timestamp( $tx );
        if ( ! $timestamp || $timestamp < $created || $timestamp > ( $created + ( $this->timeout_minutes * 60 ) ) ) {
            return [
                'valid'   => false,
                'message' => __( "This transaction was not made within this order's payment window.", 'wc-usdt-trc20' ),
            ];
        }

        $expected = $this->to_units( $order->get_meta( self::META_AMOUNT ) );
        $actual   = $this->to_units( $this->transaction_amount( $tx ) );
        if ( $expected !== $actual ) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    __( 'Amount mismatch. This order expects %s USDT.', 'wc-usdt-trc20' ),
                    $order->get_meta( self::META_AMOUNT )
                ),
            ];
        }

        return [ 'valid' => true, 'message' => '' ];
    }

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
