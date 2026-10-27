<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rate limiting, CAPTCHA and signed form tokens for the public forms.
 */
class WR_Security {

	/**
	 * Counts an attempt for the visitor's IP and returns false once over the limit.
	 */
	public static function hit( string $bucket ): bool {
		$ip = WR_Util::client_ip();
		if ( '' === $ip ) {
			return true;
		}
		$key    = 'wr_rl_' . md5( $bucket . '|' . $ip );
		$window = (int) WR_Settings::get( 'rate_limit_minutes' ) * MINUTE_IN_SECONDS;
		$data   = get_transient( $key );
		if ( ! is_array( $data ) || $data['until'] < time() ) {
			$data = array(
				'count' => 0,
				'until' => time() + $window,
			);
		}
		++$data['count'];
		set_transient( $key, $data, max( 1, $data['until'] - time() ) );
		return $data['count'] <= (int) WR_Settings::get( 'rate_limit_attempts' );
	}

	public static function captcha_enabled(): bool {
		return '' !== (string) WR_Settings::get( 'turnstile_site_key' ) && '' !== (string) WR_Settings::get( 'turnstile_secret_key' );
	}

	public static function captcha_html(): string {
		if ( ! self::captcha_enabled() ) {
			return '';
		}
		wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) );
		return '<div class="cf-turnstile" data-sitekey="' . esc_attr( WR_Settings::get( 'turnstile_site_key' ) ) . '" data-language="el"></div>';
	}

	public static function verify_captcha(): bool {
		if ( ! self::captcha_enabled() ) {
			return true;
		}
		$token = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) );
		if ( '' === $token ) {
			return false;
		}
		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => WR_Settings::get( 'turnstile_secret_key' ),
					'response' => $token,
					'remoteip' => WR_Util::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['success'] );
	}

	/**
	 * A tamper-proof, expiring token carrying data between form steps.
	 */
	public static function sign( array $data, int $ttl = 1800 ): string {
		$data['exp'] = time() + $ttl;
		$payload     = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'wr' );
	}

	public static function verify( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		[ $payload, $sig ] = $parts;
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'wr' ), $sig ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $payload, '-_', '+/' ) ), true );
		if ( ! is_array( $data ) || ( $data['exp'] ?? 0 ) < time() ) {
			return null;
		}
		return $data;
	}
}
