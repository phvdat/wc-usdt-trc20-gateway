<?php
/**
 * Trait: TRON / TronGrid API calls.
 * Handles fetching the transaction list for the receiving wallet and
 * verifying a single transaction by its TXID.
 *
 * Depends on: WC_USDT_TRC20_Tron_Address (for address helpers).
 */
trait WC_USDT_TRC20_Tron_Api {

    // -------------------------------------------------------------------------
    // Network configuration
    // -------------------------------------------------------------------------

    /**
     * Return API base URL, USDT contract address, and explorer URL for the
     * currently configured network.
     *
     * @return array{api:string, usdt_contract:string, explorer:string}
     */
    private function network_config() {
        switch ( $this->network ) {
            case 'nile':
                return [
                    'api'           => 'https://nile.trongrid.io',
                    'usdt_contract' => 'TXYZopYRdj2D9XRtbG411XZZ3kM5VkAeBf',
                    'explorer'      => 'https://nile.tronscan.org',
                ];
            case 'shasta':
                return [
                    'api'           => 'https://api.shasta.trongrid.io',
                    'usdt_contract' => 'TDZDd58a44n5Bvg7pfpcdWhZpv7XSt9PsU',
                    'explorer'      => 'https://shasta.tronscan.org',
                ];
            case 'mainnet':
            default:
                return [
                    'api'           => 'https://api.trongrid.io',
                    'usdt_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    'explorer'      => 'https://tronscan.org',
                ];
        }
    }

    /**
     * Return the USDT TRC20 contract address for the configured network.
     */
    private function usdt_contract_address() {
        return $this->network_config()['usdt_contract'];
    }

    // -------------------------------------------------------------------------
    // Transaction list (cron / scan_and_match)
    // -------------------------------------------------------------------------

    /**
     * Fetch the most recent confirmed USDT TRC20 inbound transactions for the
     * configured wallet address via TronGrid's TRC20 history endpoint.
     *
     * @param  int   $from_timestamp Unix timestamp (seconds). Only transactions
     *                               at or after this time are returned.
     * @return array List of raw TronGrid transaction objects.
     */
    private function fetch_transactions( $from_timestamp ) {
        $config  = $this->network_config();
        $address = trim( $this->wallet_address );
        if ( ! $address ) {
            $this->log( '[USDT][AUTO] Missing receiving wallet' );
            return [];
        }

        $url = trailingslashit( $config['api'] ) . 'v1/accounts/' . rawurlencode( $address ) . '/transactions/trc20';
        $url = add_query_arg(
            [
                'limit'            => 200,
                'only_confirmed'   => 'true',
                'min_timestamp'    => max( 0, (int) $from_timestamp * 1000 ),
                'order_by'         => 'block_timestamp,asc',
                'contract_address' => $config['usdt_contract'],
            ],
            $url
        );

        $headers = [ 'Accept' => 'application/json' ];
        if ( $this->trongrid_api_key ) {
            $headers['TRON-PRO-API-KEY'] = $this->trongrid_api_key;
        }

        $this->log( '[USDT][AUTO] GET ' . $url );
        $response = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => $headers ] );

        if ( is_wp_error( $response ) ) {
            $this->log( '[USDT][AUTO] API error: ' . $response->get_error_message() );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $this->log( '[USDT][AUTO] HTTP ' . $code . ' response=' . substr( $body, 0, 2000 ) );

        if ( $code < 200 || $code >= 300 ) {
            return [];
        }

        $json = json_decode( $body, true );
        if ( ! is_array( $json ) || empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
            return [];
        }

        $result = [];
        foreach ( $json['data'] as $tx ) {
            $token  = isset( $tx['token_info']['address'] ) ? (string) $tx['token_info']['address'] : '';
            $symbol = isset( $tx['token_info']['symbol'] ) ? strtoupper( (string) $tx['token_info']['symbol'] ) : '';
            $to     = isset( $tx['to'] ) ? $this->normalize_tron_address( (string) $tx['to'] ) : '';
            $txid   = isset( $tx['transaction_id'] ) ? strtolower( (string) $tx['transaction_id'] ) : '';

            if ( ! $txid || $to !== $address ) {
                continue;
            }
            if ( $token && strcasecmp( $token, $config['usdt_contract'] ) !== 0 ) {
                continue;
            }
            if ( $symbol && $symbol !== 'USDT' ) {
                continue;
            }

            $result[] = $tx;
        }

        $this->log( '[USDT][AUTO] Parsed ' . count( $result ) . ' matching USDT transfers' );
        return $result;
    }

    // -------------------------------------------------------------------------
    // Single-transaction verification (customer TXID submission)
    // -------------------------------------------------------------------------

    /**
     * Validate a single TRON transaction by its TXID against the expected
     * address, amount, and time window.
     *
     * Uses gettransactionbyid + gettransactioninfobyid to decode the raw
     * calldata directly — no dependency on the paginated events endpoint.
     *
     * @param  string $txid
     * @param  string $address         Expected receiving address (Base58).
     * @param  float  $expected_amount Expected USDT amount.
     * @param  int    $created         Order creation Unix timestamp.
     * @param  int    $deadline        Payment deadline Unix timestamp.
     * @return true|WP_Error
     */
    private function verify_transaction_by_id( $txid, $address, $expected_amount, $created, $deadline ) {
        $base    = $this->network_config()['api'];
        $headers = [];
        $api_key = trim( (string) $this->get_option( 'trongrid_api_key', '' ) );
        if ( $api_key ) {
            $headers['TRON-PRO-API-KEY'] = $api_key;
        }

        $this->log( '[USDT][TXID] Direct API base=' . $base );

        // --- gettransactionbyid ---
        $tx_url  = $base . '/wallet/gettransactionbyid';
        $response = wp_remote_post( $tx_url, [
            'timeout' => 20,
            'headers' => array_merge( $headers, [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [ 'value' => $txid ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'Unable to reach TRON API: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $this->log( sprintf( '[USDT][TXID] gettransactionbyid HTTP=%d body=%s', $status, substr( $body, 0, 1500 ) ) );
        $tx = json_decode( $body, true );

        if ( ! is_array( $tx ) || empty( $tx['txID'] ) ) {
            return new WP_Error( 'tx_not_found', 'Transaction not found on the selected TRON network.' );
        }

        // --- gettransactioninfobyid ---
        $info_url      = $base . '/wallet/gettransactioninfobyid';
        $info_response = wp_remote_post( $info_url, [
            'timeout' => 20,
            'headers' => array_merge( $headers, [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [ 'value' => $txid ] ),
        ] );

        if ( is_wp_error( $info_response ) ) {
            return new WP_Error( 'api_error', 'Unable to reach TRON transaction info API.' );
        }

        $info_body = wp_remote_retrieve_body( $info_response );
        $this->log( sprintf( '[USDT][TXID] gettransactioninfobyid HTTP=%d body=%s', wp_remote_retrieve_response_code( $info_response ), substr( $info_body, 0, 1500 ) ) );
        $info = json_decode( $info_body, true );

        if ( ! is_array( $info ) || empty( $info['id'] ) ) {
            return new WP_Error( 'tx_info_not_found', 'Transaction information is not available yet.' );
        }

        if ( ! empty( $info['result'] ) && strtoupper( (string) $info['result'] ) !== 'SUCCESS' ) {
            return new WP_Error( 'tx_failed', 'This transaction was not successful on the TRON network.' );
        }

        // --- Decode transfer calldata ---
        $contract  = $this->usdt_contract_address();
        $contracts = isset( $tx['raw_data']['contract'] ) && is_array( $tx['raw_data']['contract'] )
            ? $tx['raw_data']['contract'] : [];

        $found = false;
        foreach ( $contracts as $c ) {
            $type  = $c['type'] ?? '';
            $param = $c['parameter']['value'] ?? [];

            if ( $type !== 'TriggerSmartContract' ) {
                continue;
            }

            $data = strtolower( (string) ( $param['data'] ?? '' ) );
            if ( strpos( $data, 'a9059cbb' ) !== 0 ) {
                continue;
            }

            $to_hex     = substr( $data, 8, 64 );
            $amount_hex = substr( $data, 72, 64 );
            if ( strlen( $to_hex ) !== 64 || strlen( $amount_hex ) !== 64 ) {
                continue;
            }

            $to         = $this->tron_hex_to_base58( '41' . substr( $to_hex, 24 ) );
            $amount_raw = hexdec( substr( $amount_hex, 48 ) );

            // contract_address in raw_data is hex (e.g. "41eca9bc...").
            // Normalise both sides to Base58 for a reliable comparison.
            $contract_address_hex  = $param['contract_address'] ?? '';
            $contract_address_b58  = $this->normalize_tron_address( $contract_address_hex );

            $this->log( sprintf(
                '[USDT][TXID] Decoded: to=%s contract_b58=%s expected_address=%s expected_contract=%s',
                $to, $contract_address_b58, $address, $contract
            ) );

            if ( ! $to || $to !== $address ) {
                continue;
            }
            if ( $contract && strcasecmp( $contract_address_b58, $contract ) !== 0 ) {
                continue;
            }

            $actual = ( (float) $amount_raw ) / 1_000_000;
            $this->log( sprintf( '[USDT][TXID] Transfer candidate to=%s amount=%s contract=%s', $to, $actual, $contract_address_b58 ) );

            if ( abs( $actual - $expected_amount ) > 0.000001 ) {
                return new WP_Error(
                    'amount_mismatch',
                    sprintf( 'Amount mismatch. This order expects %s USDT.', wc_format_decimal( $expected_amount, 6 ) )
                );
            }

            $timestamp_ms = isset( $tx['raw_data']['timestamp'] ) ? (int) $tx['raw_data']['timestamp'] : 0;
            $timestamp    = (int) floor( $timestamp_ms / 1000 );
            $this->log( sprintf(
                '[USDT][TXID] Timestamp=%s order_created=%s deadline=%s',
                gmdate( 'c', $timestamp ),
                gmdate( 'c', $created ),
                gmdate( 'c', $deadline )
            ) );

            if ( $timestamp < $created ) {
                return new WP_Error( 'too_early', 'This transaction was sent before the order was created.' );
            }
            if ( $timestamp > $deadline ) {
                return new WP_Error( 'expired', 'This transaction was sent after the payment timeout.' );
            }

            $found = true;
            break;
        }

        if ( ! $found ) {
            return new WP_Error(
                'transfer_not_found',
                'No matching USDT TRC20 transfer to the store wallet was found in this transaction.'
            );
        }

        return true;
    }
}
