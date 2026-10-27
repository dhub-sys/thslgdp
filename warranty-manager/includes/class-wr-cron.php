<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scheduled work: expiry reminders and data retention.
 *
 * Runs hourly through WP-Cron. On cPanel set DISABLE_WP_CRON and add a real
 * cron job that calls wp-cron.php (see README), so it runs without visitors.
 */
class WR_Cron {

	const HOOK      = 'wr_hourly';
	const BATCH     = 200;
	const MAX_TRIES = 3;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		if ( get_transient( 'wr_cron_lock' ) ) {
			return;
		}
		set_transient( 'wr_cron_lock', 1, 10 * MINUTE_IN_SECONDS );
		try {
			self::send_reminders();
			self::apply_retention();
			update_option( 'wr_cron_last_run', WR_Util::now(), false );
		} finally {
			delete_transient( 'wr_cron_lock' );
		}
	}

	/**
	 * For each active rule, notify warranties expiring within `days_before` days.
	 *
	 * Rules are processed from the shortest window up, and a warranty is skipped
	 * if it already got a reminder on the same channel/recipient from a rule with
	 * an equal or shorter window. So a warranty registered 5 days before expiry
	 * gets one reminder, not one per rule.
	 */
	public static function send_reminders(): void {
		global $wpdb;
		$rules_t = WR_DB::table( 'rules' );
		$log_t   = WR_DB::table( 'notification_log' );
		$today   = WR_Util::today();
		$sms_on  = (bool) WR_Settings::get( 'sms_enabled' );

		$rules = $wpdb->get_results( "SELECT * FROM $rules_t WHERE active = 1 ORDER BY days_before ASC, id ASC" );
		foreach ( $rules as $rule ) {
			if ( 'sms' === $rule->channel && ( ! $sms_on || 'customer' !== $rule->recipient ) ) {
				continue;
			}
			$until   = gmdate( 'Y-m-d', strtotime( $today . ' +' . (int) $rule->days_before . ' days' ) );
			$contact = 'email' === $rule->channel ? "w.email <> ''" : "w.phone <> ''";
			if ( 'admin' === $rule->recipient ) {
				$contact = '1=1';
			}
			$kind = 'admin' === $rule->recipient ? 'admin_digest' : 'reminder';

			$sql = WR_Warranties::base_select() . $wpdb->prepare(
				" WHERE w.status = 'active' AND w.anonymized_at IS NULL AND $contact
				AND w.expires_on >= %s AND w.expires_on <= %s
				AND NOT EXISTS (
					SELECT 1 FROM $log_t l JOIN $rules_t r ON r.id = l.rule_id
					WHERE l.warranty_id = w.id AND l.kind = %s AND l.status = 'sent'
					AND r.channel = %s AND r.recipient = %s AND r.days_before <= %d
				)
				AND ( SELECT COUNT(*) FROM $log_t f WHERE f.warranty_id = w.id AND f.rule_id = %d AND f.status = 'failed' ) < %d
				ORDER BY w.expires_on ASC LIMIT %d",
				$today,
				$until,
				$kind,
				$rule->channel,
				$rule->recipient,
				$rule->days_before,
				$rule->id,
				self::MAX_TRIES,
				self::BATCH
			);
			$rows = $wpdb->get_results( $sql );
			if ( ! $rows ) {
				continue;
			}
			if ( 'admin' === $rule->recipient ) {
				WR_Notifier::send_admin_digest( $rule, $rows );
				continue;
			}
			foreach ( $rows as $w ) {
				WR_Notifier::send_reminder( $w, $rule );
			}
		}
	}

	/**
	 * Anonymizes customers of warranties that expired more than N years ago.
	 */
	public static function apply_retention(): void {
		global $wpdb;
		if ( ! WR_Settings::get( 'retention_enabled' ) || get_option( 'wr_retention_last' ) === WR_Util::today() ) {
			return;
		}
		$years  = (int) WR_Settings::get( 'retention_years' );
		$cutoff = gmdate( 'Y-m-d', strtotime( WR_Util::today() . " -$years years" ) );
		$w      = WR_DB::table( 'warranties' );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $w WHERE anonymized_at IS NULL AND expires_on < %s LIMIT 1000", $cutoff ) );
		foreach ( $ids as $id ) {
			WR_Warranties::anonymize( (int) $id );
		}
		if ( $ids ) {
			WR_Audit::log( 'retention_anonymize', 'warranty', null, array( 'count' => count( $ids ), 'cutoff' => $cutoff ) );
		}
		if ( count( $ids ) < 1000 ) {
			update_option( 'wr_retention_last', WR_Util::today(), false );
		}
	}
}
