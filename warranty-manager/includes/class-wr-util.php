<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small stateless helpers.
 */
class WR_Util {

	/** Characters used in warranty codes: no 0/O, 1/I/L to avoid confusion. */
	const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	public static function now(): string {
		return current_time( 'mysql' );
	}

	public static function today(): string {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Adds months to a Y-m-d date, clamping to the last day of the target month
	 * (31 Jan + 1 month = 28/29 Feb, not 3 Mar).
	 */
	public static function add_months( string $date, int $months ): string {
		$d      = new DateTimeImmutable( $date );
		$first  = $d->modify( 'first day of this month' )->modify( ( $months >= 0 ? '+' : '' ) . $months . ' months' );
		$day    = min( (int) $d->format( 'j' ), (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->format( 'Y-m-d' );
	}

	public static function days_between( string $from, string $to ): int {
		return (int) ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->format( '%r%a' );
	}

	public static function format_date( ?string $date ): string {
		if ( ! $date ) {
			return '';
		}
		$ts = strtotime( $date );
		return $ts ? gmdate( 'd/m/Y', $ts ) : '';
	}

	/**
	 * Matching key for a serial: uppercase letters and digits only, so that
	 * "ab-123 45" typed by a customer matches "AB12345" from the import.
	 */
	public static function serial_key( string $serial ): string {
		$s = strtr( mb_strtoupper( $serial, 'UTF-8' ), self::GREEK_LOOKALIKES );
		return preg_replace( '/[^A-Z0-9\x{0391}-\x{03A9}]/u', '', $s );
	}

	/**
	 * Greek capitals that look like Latin ones. Customers typing on a Greek
	 * keyboard ("ΑΒ123") must still match serials/codes stored in Latin ("AB123").
	 */
	const GREEK_LOOKALIKES = array(
		'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M',
		'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X',
		'Ά' => 'A', 'Έ' => 'E', 'Ή' => 'H', 'Ί' => 'I', 'Ό' => 'O', 'Ύ' => 'Y', 'Ϊ' => 'I', 'Ϋ' => 'Y',
	);

	public static function clean_serial( string $serial ): string {
		return mb_strtoupper( trim( preg_replace( '/\s+/u', ' ', $serial ) ), 'UTF-8' );
	}

	/**
	 * Normalizes a phone number to E.164. Greek numbers without country code
	 * (10 digits starting with 2 or 69) get +30. Returns '' when invalid.
	 */
	public static function normalize_phone( string $phone ): string {
		$p = preg_replace( '/[^\d+]/', '', $phone );
		if ( str_starts_with( $p, '00' ) ) {
			$p = '+' . substr( $p, 2 );
		}
		if ( ! str_starts_with( $p, '+' ) ) {
			if ( preg_match( '/^(2\d{9}|69\d{8})$/', $p ) ) {
				$p = '+30' . $p;
			} elseif ( preg_match( '/^30(2\d{9}|69\d{8})$/', $p ) ) {
				$p = '+' . $p;
			} else {
				return '';
			}
		}
		return preg_match( '/^\+[1-9]\d{7,14}$/', $p ) ? $p : '';
	}

	public static function is_greek_mobile_or_foreign( string $e164 ): bool {
		return ! str_starts_with( $e164, '+30' ) || str_starts_with( $e164, '+3069' );
	}

	public static function generate_code(): string {
		$prefix = (string) WR_Settings::get( 'code_prefix' );
		$chars  = '';
		$max    = strlen( self::CODE_ALPHABET ) - 1;
		for ( $i = 0; $i < 8; $i++ ) {
			$chars .= self::CODE_ALPHABET[ random_int( 0, $max ) ];
		}
		return $prefix . '-' . substr( $chars, 0, 4 ) . '-' . substr( $chars, 4 );
	}

	public static function normalize_code( string $code ): string {
		$code = preg_replace( '/[^A-Z0-9]/', '', strtr( mb_strtoupper( $code, 'UTF-8' ), self::GREEK_LOOKALIKES ) );
		$pre  = (string) WR_Settings::get( 'code_prefix' );
		if ( str_starts_with( $code, $pre ) && strlen( $code ) === strlen( $pre ) + 8 ) {
			return $pre . '-' . substr( $code, strlen( $pre ), 4 ) . '-' . substr( $code, strlen( $pre ) + 4 );
		}
		return $code;
	}

	public static function client_ip(): string {
		if ( WR_Settings::get( 'trust_cloudflare_ip' ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} else {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function user_agent(): string {
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 );
	}

	/** "Γιώργος Παπαδόπουλος" -> "Γ*** Π***" for public display. */
	public static function mask_name( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' !== $p ) {
				$out[] = mb_substr( $p, 0, 1 ) . '***';
			}
		}
		return implode( ' ', $out );
	}

	/** Parses dates from imports: Excel serial numbers, d/m/Y, d-m-Y, d.m.Y, Y-m-d. */
	public static function parse_date( $value ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^\d{4,5}(\.\d+)?$/', $value ) ) {
			$days = (int) floor( (float) $value );
			if ( $days > 20000 && $days < 80000 ) {
				return ( new DateTimeImmutable( '1899-12-30' ) )->modify( "+$days days" )->format( 'Y-m-d' );
			}
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m ) ) {
			[ , $y, $mo, $d ] = $m;
		} elseif ( preg_match( '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $value, $m ) ) {
			[ , $d, $mo, $y ] = $m;
			if ( strlen( $y ) === 2 ) {
				$y = '20' . $y;
			}
		} else {
			return null;
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}
}
