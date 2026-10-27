<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in a single option.
 */
class WR_Settings {

	const OPTION = 'wr_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			'default_duration_months' => 24,
			'start_mode'              => 'activation', // activation | sale_date
			'code_prefix'             => 'WR',
			'admin_email'             => get_option( 'admin_email' ),
			'check_page_url'          => '',
			'privacy_page_url'        => '',
			'turnstile_site_key'      => '',
			'turnstile_secret_key'    => '',
			'rate_limit_attempts'     => 10,
			'rate_limit_minutes'      => 15,
			'trust_cloudflare_ip'     => 0,
			'retention_enabled'       => 1,
			'retention_years'         => 6,
			'delete_data_on_uninstall' => 0,

			'activation_email'        => 1,
			'activation_sms'          => 1,

			'sms_enabled'             => 0,
			'sms_endpoint'            => '',
			'sms_method'              => 'POST',
			'sms_headers'             => "Content-Type: application/json\nAuthorization: Bearer YOUR_API_KEY",
			'sms_body_format'         => 'json', // json | form
			'sms_body'                => '{"to":"{to}","from":"{sender}","text":"{message}"}',
			'sms_sender'              => '',
			'sms_to_format'           => 'e164', // e164 (+3069...) | digits (3069...)

			'tpl_activation_subject'  => 'Η εγγύησή σας ενεργοποιήθηκε – {code}',
			'tpl_activation_body'     => "Αγαπητέ/ή {name},\n\nη εγγύηση του προϊόντος σας ενεργοποιήθηκε επιτυχώς.\n\nΚωδικός εγγύησης: {code}\nΠροϊόν: {product}\nΣειριακός αριθμός: {serial}\nΙσχύει από: {starts_on}\nΛήγει: {expires_on}\n\nΦυλάξτε αυτό το μήνυμα. Μπορείτε να ελέγξετε την εγγύησή σας οποιαδήποτε στιγμή εδώ: {check_url}\n\n{site_name}",
			'tpl_activation_sms'      => 'Η εγγυηση σας ενεργοποιηθηκε. Κωδικος: {code}. Ληγει: {expires_on}.',
			'tpl_reminder_subject'    => 'Η εγγύησή σας λήγει σε {days_left} ημέρες',
			'tpl_reminder_body'       => "Αγαπητέ/ή {name},\n\nσας υπενθυμίζουμε ότι η εγγύηση με κωδικό {code} για το προϊόν {product} (σειριακός {serial}) λήγει στις {expires_on}.\n\n{site_name}",
			'tpl_reminder_sms'        => 'Η εγγυηση {code} ληγει στις {expires_on}.',
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
		self::$cache = null;
	}

	/**
	 * Sanitize a submitted settings form.
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();

		$ints = array( 'default_duration_months', 'rate_limit_attempts', 'rate_limit_minutes', 'retention_years' );
		foreach ( $ints as $k ) {
			$out[ $k ] = max( 1, absint( $in[ $k ] ?? $d[ $k ] ) );
		}
		$bools = array( 'trust_cloudflare_ip', 'retention_enabled', 'delete_data_on_uninstall', 'activation_email', 'activation_sms', 'sms_enabled' );
		foreach ( $bools as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['start_mode']      = in_array( $in['start_mode'] ?? '', array( 'activation', 'sale_date' ), true ) ? $in['start_mode'] : 'activation';
		$out['sms_method']      = in_array( $in['sms_method'] ?? '', array( 'POST', 'GET' ), true ) ? $in['sms_method'] : 'POST';
		$out['sms_body_format'] = in_array( $in['sms_body_format'] ?? '', array( 'json', 'form' ), true ) ? $in['sms_body_format'] : 'json';
		$out['sms_to_format']   = in_array( $in['sms_to_format'] ?? '', array( 'e164', 'digits' ), true ) ? $in['sms_to_format'] : 'e164';
		$out['code_prefix']     = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['code_prefix'] ?? 'WR' ) ) ) ?: 'WR';
		$out['admin_email']     = sanitize_email( $in['admin_email'] ?? '' );

		foreach ( array( 'check_page_url', 'privacy_page_url', 'sms_endpoint' ) as $k ) {
			$out[ $k ] = esc_url_raw( trim( (string) ( $in[ $k ] ?? '' ) ) );
		}
		foreach ( array( 'turnstile_site_key', 'turnstile_secret_key', 'sms_sender', 'tpl_activation_subject', 'tpl_reminder_subject', 'tpl_activation_sms', 'tpl_reminder_sms' ) as $k ) {
			$out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'sms_headers', 'sms_body', 'tpl_activation_body', 'tpl_reminder_body' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( wp_unslash( $in[ $k ] ?? '' ) );
		}
		// The SMS body is a raw JSON/form template; sanitize_textarea_field keeps quotes and braces intact.
		return $out;
	}
}
