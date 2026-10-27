<?php
defined( 'ABSPATH' ) || exit;

/**
 * Activation, upgrades, roles.
 */
class WR_Install {

	public static function activate(): void {
		self::install();
		WR_Cron::schedule();
	}

	public static function deactivate(): void {
		WR_Cron::unschedule();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'wr_db_version' ) !== WR_DB_VERSION ) {
			self::install();
		}
		if ( WR_Crypto::ready() && '1' !== get_option( 'wr_pii_encrypted' ) ) {
			self::encrypt_existing();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// v1 -> v2: personal data columns become ciphertext (TEXT); their old plain indexes must go first.
		$warr = WR_DB::table( 'warranties' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $warr ) ) ) {
			foreach ( array( 'email', 'phone' ) as $index ) {
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM $warr WHERE Key_name = %s", $index ) ) ) {
					$wpdb->query( "ALTER TABLE $warr DROP INDEX $index" );
				}
			}
		}
		dbDelta( WR_DB::schema() );

		self::add_caps();
		self::seed();
		self::protect_private_dir();

		if ( false === get_option( WR_Settings::OPTION ) ) {
			add_option( WR_Settings::OPTION, WR_Settings::defaults(), '', false );
		}
		update_option( 'wr_db_version', WR_DB_VERSION );
	}

	/**
	 * wr_view: dashboard, lists, details.
	 * wr_edit: edit customer details, manual activation, resend messages.
	 * wr_manage: imports, products, terms, rules, settings, exports, anonymization, audit log.
	 */
	public static function add_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'wr_view', 'wr_edit', 'wr_manage' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		if ( ! get_role( 'wr_support' ) ) {
			add_role(
				'wr_support',
				'Εξυπηρέτηση εγγυήσεων',
				array(
					'read'    => true,
					'wr_view' => true,
					'wr_edit' => true,
				)
			);
		}
	}

	private static function seed(): void {
		global $wpdb;
		$terms = WR_DB::table( 'terms' );
		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $terms" ) ) {
			WR_Terms::publish( '1.0', '<p>[Συμπληρώστε εδώ τους όρους εγγύησης από το μενού Εγγυήσεις → Όροι εγγύησης.]</p>', 0 );
		}
		$rules = WR_DB::table( 'rules' );
		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $rules" ) ) {
			$now = WR_Util::now();
			foreach ( array( array( 30, 'email', 'customer' ), array( 30, 'sms', 'customer' ), array( 30, 'email', 'admin' ) ) as $r ) {
				$wpdb->insert(
					$rules,
					array(
						'days_before' => $r[0],
						'channel'     => $r[1],
						'recipient'   => $r[2],
						'active'      => 1,
						'created_at'  => $now,
					)
				);
			}
		}
	}

	/**
	 * Encrypts personal data stored before encryption existed (or before the key
	 * was configured). Idempotent: already encrypted values are skipped.
	 */
	public static function encrypt_existing(): void {
		global $wpdb;
		$w = WR_DB::table( 'warranties' );
		$c = WR_DB::table( 'consents' );
		$l = WR_DB::table( 'notification_log' );
		$p = WR_Crypto::PREFIX . '%';

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, full_name, email, phone, notes FROM $w WHERE (full_name <> '' AND full_name NOT LIKE %s) OR (email <> '' AND email NOT LIKE %s) OR (phone <> '' AND phone NOT LIKE %s) OR (notes <> '' AND notes NOT LIKE %s)", $p, $p, $p, $p ) ) as $r ) {
			$plain = array();
			foreach ( WR_Warranties::PII as $f ) {
				$plain[ $f ] = WR_Crypto::decrypt( (string) $r->$f );
			}
			$wpdb->update( $w, WR_Warranties::encrypt_pii( $plain ), array( 'id' => $r->id ) );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, ip_address, user_agent FROM $c WHERE (ip_address <> '' AND ip_address NOT LIKE %s) OR (user_agent <> '' AND user_agent NOT LIKE %s)", $p, $p ) ) as $r ) {
			$wpdb->update(
				$c,
				array(
					'ip_address' => WR_Crypto::encrypt( WR_Crypto::decrypt( (string) $r->ip_address ) ),
					'user_agent' => WR_Crypto::encrypt( WR_Crypto::decrypt( (string) $r->user_agent ) ),
				),
				array( 'id' => $r->id )
			);
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, recipient FROM $l WHERE recipient <> '' AND recipient NOT LIKE %s", $p ) ) as $r ) {
			$wpdb->update( $l, array( 'recipient' => WR_Crypto::encrypt( $r->recipient ) ), array( 'id' => $r->id ) );
		}
		update_option( 'wr_pii_encrypted', '1', false );
	}

	/** Directory for temporary import files, closed to web access. */
	public static function private_dir(): string {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'wr-private';
	}

	public static function protect_private_dir(): void {
		$dir = self::private_dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( "$dir/.htaccess" ) ) {
			file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( "$dir/index.php" ) ) {
			file_put_contents( "$dir/index.php", "<?php\n// Silence.\n" );
		}
	}
}
