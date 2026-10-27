<?php
defined( 'ABSPATH' ) || exit;

/**
 * Generic HTTP SMS gateway. Works with most providers (Yuboto, Apifon, Routee,
 * Twilio, ...) by configuring endpoint, headers and a body template.
 *
 * Placeholders in headers/body: {to}, {message}, {sender}, {api_key}.
 * {api_key} is taken from the WR_SMS_API_KEY constant in wp-config.php so the
 * key does not have to live in the database.
 */
class WR_SMS {

	/**
	 * @return true|WP_Error
	 */
	public static function send( string $to_e164, string $message ) {
		$s = WR_Settings::all();
		if ( empty( $s['sms_enabled'] ) || empty( $s['sms_endpoint'] ) ) {
			return new WP_Error( 'wr_sms_disabled', 'Η αποστολή SMS δεν είναι ενεργοποιημένη.' );
		}
		$to   = 'digits' === $s['sms_to_format'] ? ltrim( $to_e164, '+' ) : $to_e164;
		$vars = array(
			'{to}'      => $to,
			'{message}' => $message,
			'{sender}'  => (string) $s['sms_sender'],
			'{api_key}' => defined( 'WR_SMS_API_KEY' ) ? (string) WR_SMS_API_KEY : '',
		);

		$headers = array();
		foreach ( preg_split( '/\r?\n/', (string) $s['sms_headers'] ) as $line ) {
			if ( str_contains( $line, ':' ) ) {
				[ $k, $v ]             = array_map( 'trim', explode( ':', $line, 2 ) );
				$headers[ $k ] = strtr( $v, $vars );
			}
		}

		$body = (string) $s['sms_body'];
		if ( 'json' === $s['sms_body_format'] ) {
			$escaped = array_map( static fn( $v ) => substr( wp_json_encode( (string) $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 1, -1 ), $vars );
			$body    = strtr( $body, $escaped );
		} else {
			$body = strtr( $body, array_map( 'rawurlencode', $vars ) );
			if ( ! isset( $headers['Content-Type'] ) ) {
				$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			}
		}

		$url  = $s['sms_endpoint'];
		$args = array(
			'method'  => $s['sms_method'],
			'headers' => $headers,
			'timeout' => 15,
		);
		if ( 'GET' === $s['sms_method'] ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $body;
		} else {
			$args['body'] = $body;
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wr_sms_http', sprintf( 'HTTP %d: %s', $code, substr( wp_remote_retrieve_body( $res ), 0, 300 ) ) );
		}
		return true;
	}
}
