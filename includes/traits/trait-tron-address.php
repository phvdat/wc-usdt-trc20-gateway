<?php
/**
 * Trait: TRON address encoding/decoding helpers.
 * Provides Base58Check encode/decode and hex normalization.
 */
trait WC_USDT_TRC20_Tron_Address {

    /**
     * Convert a TRON Base58Check address to its full hex representation.
     * Returns empty string on failure.
     */
    private function tron_base58_to_hex( $address ) {
        if ( ! $address ) {
            return '';
        }
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits   = [ 0 ];
        for ( $i = 0; $i < strlen( $address ); $i++ ) {
            $pos = strpos( $alphabet, $address[ $i ] );
            if ( $pos === false ) {
                return '';
            }
            $carry = $pos;
            for ( $j = 0; $j < count( $digits ); $j++ ) {
                $value      = $digits[ $j ] * 58 + $carry;
                $digits[ $j ] = $value & 0xff;
                $carry      = intdiv( $value, 256 );
            }
            while ( $carry > 0 ) {
                $digits[] = $carry & 0xff;
                $carry    = intdiv( $carry, 256 );
            }
        }
        $hex = '';
        for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
            $hex .= str_pad( dechex( $digits[ $i ] ), 2, '0', STR_PAD_LEFT );
        }
        $leading = 0;
        while ( $leading < strlen( $address ) && $address[ $leading ] === '1' ) {
            $leading++;
        }
        return str_repeat( '00', $leading ) . $hex;
    }

    /**
     * Convert a hex-encoded TRON address to Base58Check format.
     * Returns empty string on failure.
     */
    private function tron_hex_to_base58( $hex ) {
        $hex = preg_replace( '/^0x/i', '', trim( $hex ) );
        if ( $hex === '' ) {
            return '';
        }
        if ( strlen( $hex ) % 2 ) {
            $hex = '0' . $hex;
        }
        $bytes = [];
        for ( $i = 0; $i < strlen( $hex ); $i += 2 ) {
            $bytes[] = hexdec( substr( $hex, $i, 2 ) );
        }
        $zeros = 0;
        while ( $zeros < count( $bytes ) && $bytes[ $zeros ] === 0 ) {
            $zeros++;
        }
        $digits = [ 0 ];
        foreach ( $bytes as $byte ) {
            $carry = $byte;
            for ( $j = 0; $j < count( $digits ); $j++ ) {
                $value      = $digits[ $j ] * 256 + $carry;
                $digits[ $j ] = $value % 58;
                $carry      = intdiv( $value, 58 );
            }
            while ( $carry > 0 ) {
                $digits[] = $carry % 58;
                $carry    = intdiv( $carry, 58 );
            }
        }
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $out      = '';
        for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
            $out .= $alphabet[ $digits[ $i ] ];
        }
        return str_repeat( '1', $zeros ) . ltrim( $out, '1' );
    }

    /**
     * Normalise any form of TRON address (Base58, hex with/without 0x prefix)
     * to Base58Check. Returns empty string for unrecognised formats.
     */
    private function normalize_tron_address( $address ) {
        $address = trim( (string) $address );
        if ( $address === '' ) {
            return '';
        }
        // Already Base58.
        if ( $address[0] === 'T' && strlen( $address ) === 34 ) {
            return $address;
        }
        $hex = strtolower( preg_replace( '/^0x/i', '', $address ) );
        if ( strlen( $hex ) === 42 && str_starts_with( $hex, '41' ) ) {
            return $this->tron_hex_to_base58( $hex );
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
