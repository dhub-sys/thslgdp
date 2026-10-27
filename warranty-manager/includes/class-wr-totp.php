<?php
defined( 'ABSPATH' ) || exit;

/**
 * Time-based one-time passwords (RFC 6238), compatible with Google
 * Authenticator, Microsoft Authenticator, Authy, 1Password etc.
 */
class WR_TOTP {

	const PERIOD = 30;
	const DIGITS = 6;
	const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function new_secret(): string {
		return self::base32_encode( random_bytes( 20 ) );
	}

	/**
	 * Returns the matched time step, or null. Accepts one step of clock drift.
	 * A step equal to or older than $last_step is rejected (no code reuse).
	 */
	public static function verify( string $secret, string $code, int $last_step = 0 ): ?int {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) !== self::DIGITS ) {
			return null;
		}
		$key = self::base32_decode( $secret );
		$now = intdiv( time(), self::PERIOD );
		foreach ( array( 0, -1, 1 ) as $d ) {
			$step = $now + $d;
			if ( $step > $last_step && hash_equals( self::code( $key, $step ), $code ) ) {
				return $step;
			}
		}
		return null;
	}

	public static function code( string $key, int $step ): string {
		$hash   = hash_hmac( 'sha1', pack( 'J', $step ), $key, true );
		$offset = ord( $hash[19] ) & 0x0f;
		$num    = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) | ( ord( $hash[ $offset + 1 ] ) << 16 ) | ( ord( $hash[ $offset + 2 ] ) << 8 ) | ord( $hash[ $offset + 3 ] );
		return str_pad( (string) ( $num % ( 10 ** self::DIGITS ) ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	public static function uri( string $secret, string $account, string $issuer ): string {
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&period=' . self::PERIOD . '&digits=' . self::DIGITS;
	}

	public static function base32_encode( string $bin ): string {
		$bits = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::B32[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$b32  = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $c ) {
			$bits .= str_pad( decbin( strpos( self::B32, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( strlen( $byte ) === 8 ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}
}
