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
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
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
