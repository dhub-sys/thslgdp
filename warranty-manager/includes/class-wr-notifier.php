<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends email/SMS messages and records each attempt.
 */
class WR_Notifier {

	public static function init(): void {
		add_action( 'wr_warranty_activated', array( __CLASS__, 'send_activation' ) );
	}

	public static function vars( object $w ): array {
		$check = (string) WR_Settings::get( 'check_page_url' );
		return array(
			'{name}'       => $w->full_name,
			'{code}'       => $w->code,
			'{serial}'     => (string) $w->serial,
			'{product}'    => WR_Warranties::product_label( $w ),
			'{starts_on}'  => WR_Util::format_date( $w->starts_on ),
			'{expires_on}' => WR_Util::format_date( $w->expires_on ),
			'{days_left}'  => (string) max( 0, WR_Util::days_between( WR_Util::today(), $w->expires_on ) ),
			'{check_url}'  => $check ?: home_url( '/' ),
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Sends the activation confirmation by email and/or SMS.
	 *
	 * @return array channel => true|WP_Error
	 */
	public static function send_activation( object $w ): array {
		$s    = WR_Settings::all();
		$vars = self::vars( $w );
		$out  = array();
		if ( $s['activation_email'] && $w->email ) {
			$out['email'] = self::email( $w->email, strtr( $s['tpl_activation_subject'], $vars ), strtr( $s['tpl_activation_body'], $vars ) );
			self::log( (int) $w->id, null, 'activation', 'email', $w->email, $out['email'] );
		}
		if ( $s['activation_sms'] && $s['sms_enabled'] && $w->phone ) {
			$out['sms'] = WR_SMS::send( $w->phone, strtr( $s['tpl_activation_sms'], $vars ) );
			self::log( (int) $w->id, null, 'activation', 'sms', $w->phone, $out['sms'] );
		}
		return $out;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function send_reminder( object $w, object $rule ) {
		$s    = WR_Settings::all();
		$vars = self::vars( $w );
		if ( 'email' === $rule->channel ) {
			$res = self::email( $w->email, strtr( $s['tpl_reminder_subject'], $vars ), strtr( $s['tpl_reminder_body'], $vars ) );
			self::log( (int) $w->id, (int) $rule->id, 'reminder', 'email', $w->email, $res );
		} else {
			$res = WR_SMS::send( $w->phone, strtr( $s['tpl_reminder_sms'], $vars ) );
			self::log( (int) $w->id, (int) $rule->id, 'reminder', 'sms', $w->phone, $res );
		}
		return $res;
	}

	/**
	 * One summary email to the company for all warranties matched by an admin rule.
	 */
	public static function send_admin_digest( object $rule, array $warranties ): void {
		$to = (string) WR_Settings::get( 'admin_email' );
		if ( ! $to || ! $warranties ) {
			return;
		}
		$lines = array();
		foreach ( $warranties as $w ) {
			$lines[] = sprintf(
				'%s | %s | %s | %s | %s | %s | λήξη %s',
				$w->code,
				WR_Warranties::product_label( $w ),
				$w->serial,
				$w->full_name,
				$w->email,
				$w->phone,
				WR_Util::format_date( $w->expires_on )
			);
		}
		$subject = sprintf( 'Εγγυήσεις που λήγουν μέσα σε %d ημέρες (%d)', $rule->days_before, count( $warranties ) );
		$body    = "Οι παρακάτω εγγυήσεις λήγουν σύντομα:\n\n" . implode( "\n", $lines );
		$res     = self::email( $to, $subject, $body );
		foreach ( $warranties as $w ) {
			self::log( (int) $w->id, (int) $rule->id, 'admin_digest', 'email', $to, $res );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public static function email( string $to, string $subject, string $text ) {
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222">'
			. wpautop( make_clickable( esc_html( $text ) ) ) . '</div>';
		$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		return $sent ? true : new WP_Error( 'wr_mail', 'Η αποστολή email απέτυχε (wp_mail).' );
	}

	public static function log( ?int $warranty_id, ?int $rule_id, string $kind, string $channel, string $recipient, $result ): void {
		global $wpdb;
		$wpdb->insert(
			WR_DB::table( 'notification_log' ),
			array(
				'warranty_id' => $warranty_id,
				'rule_id'     => $rule_id,
				'kind'        => $kind,
				'channel'     => $channel,
				'recipient'   => $recipient,
				'status'      => true === $result ? 'sent' : 'failed',
				'error'       => is_wp_error( $result ) ? $result->get_error_message() : null,
				'sent_at'     => WR_Util::now(),
			)
		);
	}
}
WR_Notifier::init();
