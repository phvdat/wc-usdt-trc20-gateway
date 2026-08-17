<?php
/**
 * Trait: TRON address encoding/decoding helpers.
 * Provides Base58Check encode/decode and hex normalization.
 */
trait WC_USDT_TRC20_Tron_Address {

    /**
     * Convert a hex-encoded TRON address (21 bytes = "41" + 20-byte pubkey hash,
     * no checksum) to Base58Check format (34-char TRON address starting with "T").
     *
     * TRON Base58Check = Base58( raw_21_bytes + first_4_bytes_of_sha256(sha256(raw_21_bytes)) )
     *
     * Returns empty string on failure.
     */
    private function tron_hex_to_base58( $hex ) {
        $hex = strtolower( preg_replace( '/^0x/i', '', trim( (string) $hex ) ) );
        if ( $hex === '' ) {
            return '';
        }
        if ( strlen( $hex ) % 2 ) {
            $hex = '0' . $hex;
        }
        // Must be exactly 21 bytes (42 hex chars) for a TRON address.
        if ( strlen( $hex ) !== 42 ) {
            return '';
        }

        // Append 4-byte SHA256d checksum.
        $checksum = substr( hash( 'sha256', hash( 'sha256', hex2bin( $hex ), true ), true ), 0, 4 );
        $payload  = hex2bin( $hex ) . $checksum;  // 25 bytes

        // Standard Base58 encode.
        $bytes  = array_values( unpack( 'C*', $payload ) );
        $zeros  = 0;
        foreach ( $bytes as $b ) {
            if ( $b !== 0 ) break;
            $zeros++;
        }

        $digits  = [ 0 ];
        foreach ( $bytes as $byte ) {
            $carry = $byte;
            for ( $j = 0; $j < count( $digits ); $j++ ) {
                $value       = $digits[ $j ] * 256 + $carry;
                $digits[ $j ] = $value % 58;
                $carry       = intdiv( $value, 58 );
            }
            while ( $carry > 0 ) {
                $digits[] = $carry % 58;
                $carry    = intdiv( $carry, 58 );
            }
        }

        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $out      = str_repeat( '1', $zeros );
        for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
            $out .= $alphabet[ $digits[ $i ] ];
        }
        return $out;
    }

    /**
     * Normalise any form of TRON address to Base58Check format.
     *
     * Accepts:
     *  - Base58Check (34 chars, starts with T) — returned as-is
     *  - 42-char hex with 41 prefix (full TRON internal format)
     *  - 40-char hex without prefix (EVM-style, from event logs)
     *
     * Returns empty string for unrecognised formats.
     */
    private function normalize_tron_address( $address ) {
        $address = trim( (string) $address );
        if ( $address === '' ) {
            return '';
        }
        // Already Base58Check.
        if ( $address[0] === 'T' && strlen( $address ) === 34 ) {
            return $address;
        }
        $hex = strtolower( preg_replace( '/^0x/i', '', $address ) );
        // Full 42-char hex with 41 prefix.
        if ( strlen( $hex ) === 42 && str_starts_with( $hex, '41' ) ) {
            return $this->tron_hex_to_base58( $hex );
        }
        // 40-char hex without prefix (prepend TRON network byte 0x41).
        if ( strlen( $hex ) === 40 && ctype_xdigit( $hex ) ) {
            return $this->tron_hex_to_base58( '41' . $hex );
        }
        return '';
    }

    /**
     * Convert an arbitrary-precision decimal hex string to a decimal string.
     * Used to decode uint256 values from ABI-encoded calldata without bcmath.
     */
    private function hex_to_decimal_string( $hex ) {
        $hex = ltrim( strtolower( preg_replace( '/[^0-9a-f]/', '', (string) $hex ) ), '0' );
        if ( $hex === '' ) {
            return '0';
        }
        $decimal = '0';
        foreach ( str_split( $hex ) as $digit ) {
            $decimal = $this->decimal_mul_small( $decimal, 16 );
            $decimal = $this->decimal_add_small( $decimal, hexdec( $digit ) );
        }
        return $decimal;
    }

    /** Multiply a decimal string by a small integer. */
    private function decimal_mul_small( $number, $multiplier ) {
        $carry = 0;
        $out   = '';
        for ( $i = strlen( $number ) - 1; $i >= 0; $i-- ) {
            $n     = ( (int) $number[ $i ] * $multiplier ) + $carry;
            $out   = (string) ( $n % 10 ) . $out;
            $carry = (int) floor( $n / 10 );
        }
        while ( $carry > 0 ) {
            $out   = (string) ( $carry % 10 ) . $out;
            $carry = (int) floor( $carry / 10 );
        }
        return ltrim( $out, '0' ) ?: '0';
    }

    /** Add a small integer to a decimal string. */
    private function decimal_add_small( $number, $add ) {
        $carry = (int) $add;
        $out   = '';
        for ( $i = strlen( $number ) - 1; $i >= 0; $i-- ) {
            $n     = (int) $number[ $i ] + $carry;
            $out   = (string) ( $n % 10 ) . $out;
            $carry = (int) floor( $n / 10 );
        }
        while ( $carry > 0 ) {
            $out   = (string) ( $carry % 10 ) . $out;
            $carry = (int) floor( $carry / 10 );
        }
        return ltrim( $out, '0' ) ?: '0';
    }
}
