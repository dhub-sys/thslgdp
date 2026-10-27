<?php
defined( 'ABSPATH' ) || exit;

/**
 * Encryption at rest for customer personal data.
 *
 * Names, emails, phones, notes, IPs and user agents are stored encrypted
 * (XSalsa20-Poly1305 via libsodium, bundled with WordPress as sodium_compat).
 * Exact-match search uses keyed blind indexes (HMAC-SHA256), so a database dump
 * or SQL injection elsewhere on the site does not reveal customer data.
 *
 * The master key lives only in wp-config.php:
 *   define( 'WR_ENCRYPTION_KEY', '<base64 of 32 random bytes>' );
 * Without it the data cannot be read: keep a backup of the key separately
 * from the database backups.
 */
class WR_Crypto {

	const PREFIX = 'enc1:';

	private static ?array $keys = null;

	public static function ready(): bool {
		return null !== self::keys();
	}

	private static function keys(): ?array {
		if ( null === self::$keys ) {
			if ( ! defined( 'WR_ENCRYPTION_KEY' ) ) {
				return null;
			}
			$master = base64_decode( (string) WR_ENCRYPTION_KEY, true );
			if ( false === $master || 32 !== strlen( $master ) ) {
				return null;
			}
			self::$keys = array(
				'enc' => hash_hmac( 'sha256', 'wr-encryption', $master, true ),
				'idx' => hash_hmac( 'sha256', 'wr-blind-index', $master, true ),
			);
		}
		return self::$keys;
	}

	public static function generate_key(): string {
		return base64_encode( random_bytes( 32 ) );
	}

	public static function encrypt( ?string $plain ): string {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$k = self::keys();
		if ( ! $k ) {
			throw new RuntimeException( 'WR_ENCRYPTION_KEY is not configured.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $k['enc'] ) );
	}

	public static function decrypt( ?string $value ): string {
		$value = (string) $value;
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}
		$k   = self::keys();
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( ! $k || false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $k['enc'] );
		return false === $plain ? '' : $plain;
	}

	public static function is_encrypted( ?string $value ): bool {
		return str_starts_with( (string) $value, self::PREFIX );
	}

	/** Keyed hash for exact-match lookups. Empty input gives an empty index. */
	public static function index( string $value ): string {
		$k = self::keys();
		if ( '' === $value || ! $k ) {
			return '';
		}
		return hash_hmac( 'sha256', $value, $k['idx'] );
	}

	public static function email_index( string $email ): string {
		return self::index( mb_strtolower( trim( $email ) ) );
	}

	public static function phone_index( string $e164 ): string {
		return self::index( $e164 );
	}

	/**
	 * Blind index per word of a name, so "Παπαδόπουλος" finds "Γιώργος Παπαδόπουλος".
	 * Words are lowercased and stripped of Greek accents.
	 */
	public static function name_index( string $name ): string {
		$tokens = array();
		foreach ( self::name_tokens( $name ) as $t ) {
			$tokens[] = substr( self::index( 'n:' . $t ), 0, 16 );
		}
		return implode( ' ', array_unique( $tokens ) );
	}

	public static function name_tokens( string $name ): array {
		$name = mb_strtolower( $name, 'UTF-8' );
		$name = strtr(
			$name,
			array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ' )
		);
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		return array_values( array_filter( $parts, static fn( $p ) => mb_strlen( $p ) >= 2 ) );
	}
}
