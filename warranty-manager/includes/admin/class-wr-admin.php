<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin screens and actions.
 */
class WR_Admin {

	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_wr_import_chunk', array( __CLASS__, 'ajax_import_chunk' ) );

		$actions = array(
			'warranty_update', 'warranty_extend', 'warranty_void', 'warranty_restore', 'warranty_resend', 'warranty_anonymize',
			'warranty_manual', 'warranties_export', 'import_upload', 'import_revert', 'serial_add', 'serial_status',
			'product_save', 'product_delete', 'terms_publish', 'rule_save', 'rule_toggle', 'rule_delete', 'settings_save', 'test_send', 'run_cron', 'twofa_reset',
		);
		foreach ( $actions as $a ) {
			add_action( 'admin_post_wr_' . $a, array( __CLASS__, 'action_' . $a ) );
		}
	}

	public static function menu(): void {
		add_menu_page( 'Εγγυήσεις', 'Εγγυήσεις', 'wr_view', 'wr-dashboard', array( __CLASS__, 'page_dashboard' ), 'dashicons-shield-alt', 56 );
		add_submenu_page( 'wr-dashboard', 'Επισκόπηση', 'Επισκόπηση', 'wr_view', 'wr-dashboard', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'wr-dashboard', 'Εγγυήσεις', 'Εγγυήσεις', 'wr_view', 'wr-warranties', array( __CLASS__, 'page_warranties' ) );
		add_submenu_page( 'wr-dashboard', 'Σειριακοί αριθμοί', 'Σειριακοί αριθμοί', 'wr_view', 'wr-serials', array( __CLASS__, 'page_serials' ) );
		add_submenu_page( 'wr-dashboard', 'Εισαγωγή από Excel', 'Εισαγωγή από Excel', 'wr_manage', 'wr-import', array( __CLASS__, 'page_import' ) );
		add_submenu_page( 'wr-dashboard', 'Προϊόντα', 'Προϊόντα', 'wr_manage', 'wr-products', array( __CLASS__, 'page_products' ) );
		add_submenu_page( 'wr-dashboard', 'Όροι εγγύησης', 'Όροι εγγύησης', 'wr_manage', 'wr-terms', array( __CLASS__, 'page_terms' ) );
		add_submenu_page( 'wr-dashboard', 'Ειδοποιήσεις', 'Ειδοποιήσεις', 'wr_manage', 'wr-notifications', array( __CLASS__, 'page_notifications' ) );
		add_submenu_page( 'wr-dashboard', 'Ρυθμίσεις', 'Ρυθμίσεις', 'wr_manage', 'wr-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'wr-dashboard', 'Ιστορικό ενεργειών', 'Ιστορικό ενεργειών', 'wr_manage', 'wr-audit', array( __CLASS__, 'page_audit' ) );
		add_submenu_page( 'wr-dashboard', 'Ασφάλεια & πρόσβαση', 'Ασφάλεια & πρόσβαση', 'wr_manage', 'wr-security', array( __CLASS__, 'page_security' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'wr-' ) ) {
			return;
		}
		wp_enqueue_style( 'wr-admin', WR_URL . 'assets/admin.css', array(), WR_VERSION );
		wp_enqueue_script( 'wr-admin', WR_URL . 'assets/admin.js', array(), WR_VERSION, true );
		wp_localize_script(
			'wr-admin',
			'WR_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wr_import_chunk' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'wr_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'wr_' . $action );
	}

	private static function form_open( string $action, string $extra = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'
			. '<input type="hidden" name="action" value="wr_' . esc_attr( $action ) . '">'
			. wp_nonce_field( 'wr_' . $action, '_wpnonce', true, false );
	}

	private static function guard( string $action, string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.', 403 );
		}
		check_admin_referer( 'wr_' . $action );
	}

	private static function notice( string $msg, string $type = 'success' ): void {
		set_transient( 'wr_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
	}

	private static function back( string $fallback ): void {
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ?: $fallback );
		exit;
	}

	public static function notices(): void {
		$n = get_transient( 'wr_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'wr_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
	}

	private static function req( string $key, string $default = '' ): string {
		return isset( $_REQUEST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) ) : $default;
	}

	private static function pagination( int $total, int $paged ): string {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return '';
		}
		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		return '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
	}

	private static function badge( string $status ): string {
		return '<span class="wr-badge wr-badge--' . esc_attr( $status ) . '">' . esc_html( WR_Warranties::status_label( $status ) ) . '</span>';
	}

	private static function products(): array {
		global $wpdb;
		$p = WR_DB::table( 'products' );
		return $wpdb->get_results( "SELECT * FROM $p ORDER BY code" );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_dashboard(): void {
		global $wpdb;
		$w     = WR_DB::table( 'warranties' );
		$s     = WR_DB::table( 'serials' );
		$today = WR_Util::today();
		$in30  = gmdate( 'Y-m-d', strtotime( "$today +30 days" ) );

		$serials_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $s" );
		$serials_avail = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $s WHERE status = 'available'" );
		$active        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE status = 'active' AND expires_on >= %s", $today ) );
		$expired       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE status = 'active' AND expires_on < %s", $today ) );
		$expiring      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE status = 'active' AND expires_on BETWEEN %s AND %s", $today, $in30 ) );
		$void          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w WHERE status = 'void'" );
		$activated     = $serials_total - $serials_avail;
		$rate          = $serials_total ? round( 100 * $activated / $serials_total, 1 ) : 0;

		echo '<div class="wrap wr-wrap"><h1>Εγγυήσεις – Επισκόπηση</h1>';
		self::cron_warning();
		echo '<div class="wr-cards">';
		$cards = array(
			array( 'Ενεργές εγγυήσεις', $active, self::url( 'wr-warranties', array( 'status' => 'active' ) ) ),
			array( 'Λήγουν σε 30 ημέρες', $expiring, self::url( 'wr-warranties', array( 'status' => 'expiring' ) ) ),
			array( 'Ληγμένες', $expired, self::url( 'wr-warranties', array( 'status' => 'expired' ) ) ),
			array( 'Ακυρωμένες', $void, self::url( 'wr-warranties', array( 'status' => 'void' ) ) ),
			array( 'Σειριακοί στο σύστημα', $serials_total, self::url( 'wr-serials' ) ),
			array( 'Ποσοστό ενεργοποίησης', $rate . '%', self::url( 'wr-serials', array( 'status' => 'available' ) ) ),
		);
		foreach ( $cards as $c ) {
			printf( '<a class="wr-card" href="%s"><span>%s</span><strong>%s</strong></a>', esc_url( $c[2] ), esc_html( $c[0] ), esc_html( is_int( $c[1] ) ? number_format_i18n( $c[1] ) : $c[1] ) );
		}
		echo '</div>';

		// Activations per month, last 12 months.
		$from = gmdate( 'Y-m-01', strtotime( "$today -11 months" ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS ym, COUNT(*) AS n FROM $w WHERE created_at >= %s GROUP BY ym", $from ), OBJECT_K );
		$max  = 1;
		$data = array();
		for ( $i = 11; $i >= 0; $i-- ) {
			$ym          = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', strtotime( $today ) ) . " -$i months" ) );
			$data[ $ym ] = isset( $rows[ $ym ] ) ? (int) $rows[ $ym ]->n : 0;
			$max         = max( $max, $data[ $ym ] );
		}
		echo '<div class="wr-panels"><div class="wr-panel"><h2>Ενεργοποιήσεις ανά μήνα</h2><div class="wr-bars">';
		foreach ( $data as $ym => $n ) {
			printf(
				'<div class="wr-bar" title="%1$s: %2$d"><span class="wr-bar__val">%2$d</span><span class="wr-bar__fill" style="height:%3$d%%"></span><span class="wr-bar__lbl">%4$s</span></div>',
				esc_attr( $ym ),
				$n,
				(int) round( 100 * $n / $max ),
				esc_html( substr( $ym, 5 ) . '/' . substr( $ym, 2, 2 ) )
			);
		}
		echo '</div></div>';

		$soon = WR_Warranties::fetch_all( $wpdb->prepare( WR_Warranties::base_select() . " WHERE w.status = 'active' AND w.expires_on BETWEEN %s AND %s ORDER BY w.expires_on LIMIT 10", $today, $in30 ) );
		echo '<div class="wr-panel"><h2>Λήγουν σύντομα</h2>';
		if ( $soon ) {
			echo '<table class="widefat striped"><thead><tr><th>Κωδικός</th><th>Πελάτης</th><th>Προϊόν</th><th>Λήξη</th></tr></thead><tbody>';
			foreach ( $soon as $r ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( self::url( 'wr-warranties', array( 'id' => $r->id ) ) ),
					esc_html( $r->code ),
					esc_html( $r->full_name ),
					esc_html( WR_Warranties::product_label( $r ) ),
					esc_html( WR_Util::format_date( $r->expires_on ) )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Καμία εγγύηση δεν λήγει τις επόμενες 30 ημέρες.</p>';
		}
		echo '</div></div>';

		$by_product = $wpdb->get_results(
			"SELECT p.code, p.name, COUNT(s.id) AS total, SUM(s.status = 'activated') AS activated
			FROM $s s LEFT JOIN " . WR_DB::table( 'products' ) . ' p ON p.id = s.product_id
			GROUP BY s.product_id ORDER BY total DESC LIMIT 20'
		);
		if ( $by_product ) {
			echo '<div class="wr-panel"><h2>Ανά προϊόν</h2><table class="widefat striped"><thead><tr><th>Προϊόν</th><th>Σειριακοί</th><th>Ενεργοποιημένοι</th><th>%</th></tr></thead><tbody>';
			foreach ( $by_product as $r ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s%%</td></tr>',
					esc_html( $r->name ? $r->name . ' (' . $r->code . ')' : ( $r->code ?: '— χωρίς μοντέλο —' ) ),
					esc_html( number_format_i18n( (int) $r->total ) ),
					esc_html( number_format_i18n( (int) $r->activated ) ),
					esc_html( $r->total ? round( 100 * $r->activated / $r->total, 1 ) : 0 )
				);
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	private static function cron_warning(): void {
		if ( ! current_user_can( 'wr_manage' ) ) {
			return;
		}
		$last = get_option( 'wr_cron_last_run' );
		if ( ! $last || strtotime( $last ) < strtotime( WR_Util::now() ) - 3 * HOUR_IN_SECONDS ) {
			echo '<div class="notice notice-warning"><p>Οι αυτόματες ειδοποιήσεις δεν έχουν τρέξει τις τελευταίες 3 ώρες'
				. ( $last ? ' (τελευταία εκτέλεση: ' . esc_html( $last ) . ')' : '' )
				. '. Βεβαιωθείτε ότι έχετε ορίσει cron job στο cPanel (δείτε το README του plugin).</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Warranties                                                          */
	/* ------------------------------------------------------------------ */

	private static function warranty_where( array &$args ): string {
		global $wpdb;
		$where  = array( '1=1' );
		$today  = WR_Util::today();
		$status = self::req( 'status' );
		if ( 'active' === $status ) {
			$where[] = $wpdb->prepare( "w.status = 'active' AND w.expires_on >= %s", $today );
		} elseif ( 'expired' === $status ) {
			$where[] = $wpdb->prepare( "w.status = 'active' AND w.expires_on < %s", $today );
		} elseif ( 'expiring' === $status ) {
			$where[] = $wpdb->prepare( "w.status = 'active' AND w.expires_on BETWEEN %s AND %s", $today, gmdate( 'Y-m-d', strtotime( "$today +30 days" ) ) );
		} elseif ( 'void' === $status ) {
			$where[] = "w.status = 'void'";
		}
		$product = absint( self::req( 'product' ) );
		if ( $product ) {
			$where[] = $wpdb->prepare( 'w.product_id = %d', $product );
		}
		$q = self::req( 's' );
		if ( '' !== $q ) {
			// Personal data is encrypted, so it is searched by exact blind index:
			// full email, full phone number, or whole words of the name.
			$or = array(
				$wpdb->prepare( 'w.code = %s', WR_Util::normalize_code( $q ) ),
				$wpdb->prepare( 's.serial_key = %s', WR_Util::serial_key( $q ) ?: '-' ),
			);
			if ( is_email( $q ) ) {
				$or[] = $wpdb->prepare( 'w.email_idx = %s', WR_Crypto::email_index( $q ) );
			}
			$phone = WR_Util::normalize_phone( $q );
			if ( $phone ) {
				$or[] = $wpdb->prepare( 'w.phone_idx = %s', WR_Crypto::phone_index( $phone ) );
			}
			$tokens = WR_Crypto::name_tokens( $q );
			if ( $tokens && ! is_email( $q ) ) {
				$and = array();
				foreach ( $tokens as $t ) {
					$and[] = $wpdb->prepare( 'w.name_idx LIKE %s', '%' . substr( WR_Crypto::index( 'n:' . $t ), 0, 16 ) . '%' );
				}
				$or[] = '(' . implode( ' AND ', $and ) . ')';
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$args = compact( 'status', 'product', 'q' );
		return implode( ' AND ', $where );
	}

	public static function page_warranties(): void {
		if ( isset( $_GET['id'] ) ) {
			self::page_warranty( absint( $_GET['id'] ) );
			return;
		}
		if ( 'new' === self::req( 'view' ) && current_user_can( 'wr_edit' ) ) {
			self::page_manual_activation();
			return;
		}
		global $wpdb;
		$args  = array();
		$where = self::warranty_where( $args );
		$paged = max( 1, absint( self::req( 'paged', '1' ) ) );
		$w     = WR_DB::table( 'warranties' );
		$s     = WR_DB::table( 'serials' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w w LEFT JOIN $s s ON s.id = w.serial_id WHERE $where" );
		$rows  = WR_Warranties::fetch_all( WR_Warranties::base_select() . " WHERE $where ORDER BY w.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ( ( $paged - 1 ) * self::PER_PAGE ) );

		echo '<div class="wrap wr-wrap"><h1 class="wp-heading-inline">Εγγυήσεις</h1>';
		if ( current_user_can( 'wr_edit' ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( 'wr-warranties', array( 'view' => 'new' ) ) ) . '">Χειροκίνητη ενεργοποίηση</a>';
		}
		echo '<hr class="wp-header-end">';

		echo '<form method="get" class="wr-filters"><input type="hidden" name="page" value="wr-warranties">';
		echo '<input type="search" name="s" value="' . esc_attr( $args['q'] ) . '" placeholder="Κωδικός, σειριακός, email, κινητό ή όνομα" size="40" title="Email και κινητό αναζητούνται ολόκληρα· το όνομα με ολόκληρες λέξεις (π.χ. επώνυμο).">';
		echo '<select name="status"><option value="">Όλες οι καταστάσεις</option>';
		foreach ( array( 'active' => 'Ενεργές', 'expiring' => 'Λήγουν σε 30 ημέρες', 'expired' => 'Ληγμένες', 'void' => 'Ακυρωμένες' ) as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['status'], $k, false ), esc_html( $v ) );
		}
		echo '</select><select name="product"><option value="">Όλα τα προϊόντα</option>';
		foreach ( self::products() as $p ) {
			printf( '<option value="%d"%s>%s</option>', (int) $p->id, selected( $args['product'], (int) $p->id, false ), esc_html( $p->name ?: $p->code ) );
		}
		echo '</select> <button class="button">Αναζήτηση</button></form>';

		if ( current_user_can( 'wr_manage' ) ) {
			echo '<details class="wr-export"><summary>Εξαγωγή σε Excel (CSV)</summary>' . self::form_open( 'warranties_export' ); // phpcs:ignore
			foreach ( array( 'status' => $args['status'], 'product' => $args['product'] ?: '', 's' => $args['q'] ) as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
			}
			echo '<p>Η εξαγωγή περιέχει στοιχεία πελατών χωρίς απόκρυψη. Επιβεβαιώστε με τον κωδικό 2FA. Η ενέργεια καταγράφεται και ειδοποιείται το email της εταιρείας.</p>';
			echo '<p><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required placeholder="Κωδικός 2FA" class="wr-otp"> <button class="button">Εξαγωγή ' . esc_html( number_format_i18n( $total ) ) . ' εγγυήσεων</button></p></form></details>';
		}

		printf( '<p>%s εγγυήσεις</p>', esc_html( number_format_i18n( $total ) ) );
		echo '<table class="widefat striped"><thead><tr><th>Κωδικός</th><th>Σειριακός</th><th>Προϊόν</th><th>Πελάτης</th><th>Email</th><th>Κινητό</th><th>Έναρξη</th><th>Λήξη</th><th>Κατάσταση</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( self::url( 'wr-warranties', array( 'id' => $r->id ) ) ),
				esc_html( $r->code ),
				esc_html( $r->serial ),
				esc_html( WR_Warranties::product_label( $r ) ),
				esc_html( $r->anonymized_at ? '(ανωνυμοποιημένο)' : $r->full_name ),
				esc_html( WR_Util::mask_email( $r->email ) ),
				esc_html( WR_Util::mask_phone( $r->phone ) ),
				esc_html( WR_Util::format_date( $r->starts_on ) ),
				esc_html( WR_Util::format_date( $r->expires_on ) ),
				self::badge( WR_Warranties::effective_status( $r ) ) // phpcs:ignore
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="9">Δεν βρέθηκαν εγγυήσεις.</td></tr>';
		}
		echo '</tbody></table>' . self::pagination( $total, $paged ) . '</div>'; // phpcs:ignore
	}

	private static function page_warranty( int $id ): void {
		global $wpdb;
		$w = WR_Warranties::get( $id );
		if ( ! $w ) {
			echo '<div class="wrap"><h1>Η εγγύηση δεν βρέθηκε</h1></div>';
			return;
		}
		WR_Audit::log( 'warranty_view', 'warranty', $id );
		$status = WR_Warranties::effective_status( $w );
		$back   = self::url( 'wr-warranties' );

		echo '<div class="wrap wr-wrap"><p><a href="' . esc_url( $back ) . '">&larr; Όλες οι εγγυήσεις</a></p>';
		echo '<h1>' . esc_html( $w->code ) . ' ' . self::badge( $status ) . '</h1>'; // phpcs:ignore
		echo '<div class="wr-panels"><div class="wr-panel"><h2>Στοιχεία εγγύησης</h2><table class="form-table wr-dl">';
		$rows = array(
			'Σειριακός αριθμός'     => $w->serial,
			'Προϊόν'                => WR_Warranties::product_label( $w ),
			'Ημ/νία πώλησης (αρχείο)' => WR_Util::format_date( $w->sale_date ),
			'Έναρξη'                => WR_Util::format_date( $w->starts_on ),
			'Λήξη'                  => WR_Util::format_date( $w->expires_on ),
			'Ενεργοποίηση'          => $w->created_at . ( 'admin' === $w->source ? ' (χειροκίνητα)' : ' (από το site)' ),
			'Ανωνυμοποίηση'         => (string) $w->anonymized_at,
		);
		foreach ( $rows as $k => $v ) {
			if ( '' !== (string) $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
			}
		}
		echo '</table>';

		if ( current_user_can( 'wr_manage' ) && ! $w->anonymized_at ) {
			echo '<h3>Αλλαγή λήξης</h3>' . self::form_open( 'warranty_extend' ); // phpcs:ignore
			echo '<input type="hidden" name="id" value="' . (int) $id . '"><input type="date" name="expires_on" value="' . esc_attr( $w->expires_on ) . '" required> ';
			echo '<input type="text" name="reason" placeholder="Αιτιολογία" required size="30"> <button class="button">Αποθήκευση</button></form>';

			echo '<h3>Κατάσταση</h3>';
			if ( 'void' === $w->status ) {
				echo self::form_open( 'warranty_restore' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><input type="text" name="reason" placeholder="Αιτιολογία" required size="30"> <button class="button">Επαναφορά εγγύησης</button></form>'; // phpcs:ignore
			} else {
				echo self::form_open( 'warranty_void', 'data-confirm="Να ακυρωθεί η εγγύηση;"' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><input type="text" name="reason" placeholder="Αιτιολογία" required size="30"> <button class="button button-link-delete">Ακύρωση εγγύησης</button></form>'; // phpcs:ignore
			}
		}
		echo '</div>';

		echo '<div class="wr-panel"><h2>Στοιχεία πελάτη</h2>';
		if ( $w->anonymized_at ) {
			echo '<p>Τα προσωπικά στοιχεία έχουν ανωνυμοποιηθεί.</p>';
		} elseif ( current_user_can( 'wr_edit' ) ) {
			echo self::form_open( 'warranty_update' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><table class="form-table">'; // phpcs:ignore
			echo '<tr><th><label for="full_name">Ονοματεπώνυμο</label></th><td><input class="regular-text" id="full_name" name="full_name" value="' . esc_attr( $w->full_name ) . '" required></td></tr>';
			echo '<tr><th><label for="email">Email</label></th><td><input class="regular-text" type="email" id="email" name="email" value="' . esc_attr( $w->email ) . '" required></td></tr>';
			echo '<tr><th><label for="phone">Κινητό</label></th><td><input class="regular-text" id="phone" name="phone" value="' . esc_attr( $w->phone ) . '" required></td></tr>';
			echo '<tr><th><label for="notes">Σημειώσεις</label></th><td><textarea class="large-text" rows="3" id="notes" name="notes">' . esc_textarea( (string) $w->notes ) . '</textarea></td></tr>';
			echo '</table><p><button class="button button-primary">Αποθήκευση</button></p></form>';
			echo self::form_open( 'warranty_resend' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><button class="button">Επαναποστολή επιβεβαίωσης</button></form>'; // phpcs:ignore
		} else {
			echo '<table class="form-table wr-dl"><tr><th>Ονοματεπώνυμο</th><td>' . esc_html( $w->full_name ) . '</td></tr><tr><th>Email</th><td>' . esc_html( $w->email ) . '</td></tr><tr><th>Κινητό</th><td>' . esc_html( $w->phone ) . '</td></tr></table>';
		}
		if ( current_user_can( 'wr_manage' ) && ! $w->anonymized_at ) {
			echo '<h3>GDPR</h3>' . self::form_open( 'warranty_anonymize', 'data-confirm="Τα προσωπικά στοιχεία θα διαγραφούν οριστικά. Συνέχεια;"' ); // phpcs:ignore
			echo '<input type="hidden" name="id" value="' . (int) $id . '"><button class="button button-link-delete">Ανωνυμοποίηση στοιχείων πελάτη</button></form>';
		}
		echo '</div></div>';

		echo '<div class="wr-panel"><h2>Αποδοχή όρων</h2><table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Όροι</th><th>Hash κειμένου</th><th>IP</th><th>Συσκευή</th><th>Καταχώριση</th></tr></thead><tbody>';
		foreach ( WR_Warranties::consents( $id ) as $c ) {
			$by = $c->recorded_by ? get_userdata( (int) $c->recorded_by ) : null;
			printf(
				'<tr><td>%s</td><td><a href="%s">έκδοση %s</a></td><td><code>%s…</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $c->accepted_at ),
				esc_url( self::url( 'wr-terms', array( 'view' => $c->terms_id ) ) ),
				esc_html( $c->terms_version ),
				esc_html( substr( $c->terms_hash, 0, 16 ) ),
				esc_html( (string) $c->ip_address ),
				esc_html( (string) $c->user_agent ),
				esc_html( $by ? 'από ' . $by->display_name : 'από τον πελάτη' )
			);
		}
		echo '</tbody></table></div>';

		$log = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WR_DB::table( 'notification_log' ) . ' WHERE warranty_id = %d ORDER BY id DESC LIMIT 50', $id ) );
		echo '<div class="wr-panel"><h2>Μηνύματα</h2>';
		self::render_notification_log( $log );
		echo '</div>';

		if ( current_user_can( 'wr_manage' ) ) {
			$audit = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WR_DB::table( 'audit_log' ) . " WHERE object_type = 'warranty' AND object_id = %d AND action <> 'warranty_view' ORDER BY id DESC LIMIT 50", $id ) );
			echo '<div class="wr-panel"><h2>Ιστορικό αλλαγών</h2>';
			self::render_audit( $audit );
			echo '</div>';
		}
		echo '</div>';
	}

	private static function page_manual_activation(): void {
		$terms = WR_Terms::current();
		echo '<div class="wrap wr-wrap"><p><a href="' . esc_url( self::url( 'wr-warranties' ) ) . '">&larr; Όλες οι εγγυήσεις</a></p><h1>Χειροκίνητη ενεργοποίηση</h1>';
		echo '<p>Για ενεργοποιήσεις τηλεφωνικά ή στο κατάστημα. Ο πελάτης πρέπει να έχει ενημερωθεί για τους όρους εγγύησης (έκδοση ' . esc_html( $terms->version ?? '—' ) . ') και την πολιτική απορρήτου.</p>';
		echo self::form_open( 'warranty_manual' ) . '<table class="form-table">'; // phpcs:ignore
		echo '<tr><th><label for="serial">Σειριακός αριθμός</label></th><td><input class="regular-text" id="serial" name="serial" required></td></tr>';
		echo '<tr><th><label for="full_name">Ονοματεπώνυμο</label></th><td><input class="regular-text" id="full_name" name="full_name" required></td></tr>';
		echo '<tr><th><label for="email">Email</label></th><td><input class="regular-text" type="email" id="email" name="email" required></td></tr>';
		echo '<tr><th><label for="phone">Κινητό</label></th><td><input class="regular-text" id="phone" name="phone" required></td></tr>';
		echo '<tr><th>Όροι</th><td><label><input type="checkbox" name="terms" value="1" required> Ο πελάτης ενημερώθηκε και αποδέχθηκε τους όρους εγγύησης</label></td></tr>';
		echo '<tr><th>Μηνύματα</th><td><label><input type="checkbox" name="notify" value="1" checked> Αποστολή κωδικού εγγύησης στον πελάτη</label></td></tr>';
		echo '</table><p><button class="button button-primary">Ενεργοποίηση</button></p></form></div>';
	}

	public static function action_warranty_manual(): void {
		self::guard( 'warranty_manual', 'wr_edit' );
		$fallback = self::url( 'wr-warranties', array( 'view' => 'new' ) );
		$serial   = WR_Warranties::find_available_serial( self::req( 'serial' ) );
		$email    = sanitize_email( self::req( 'email' ) );
		$phone    = WR_Util::normalize_phone( self::req( 'phone' ) );
		$name     = self::req( 'full_name' );
		$terms    = WR_Terms::current();
		if ( ! $serial ) {
			self::notice( 'Ο σειριακός δεν υπάρχει ή έχει ήδη ενεργοποιηθεί/ακυρωθεί.', 'error' );
		} elseif ( '' === $name || ! is_email( $email ) || '' === $phone ) {
			self::notice( 'Ελέγξτε ονοματεπώνυμο, email και κινητό.', 'error' );
		} elseif ( empty( $_POST['terms'] ) || ! $terms ) {
			self::notice( 'Απαιτείται η αποδοχή των όρων.', 'error' );
		} else {
			if ( empty( $_POST['notify'] ) ) {
				remove_action( 'wr_warranty_activated', array( 'WR_Notifier', 'send_activation' ) );
			}
			$w = WR_Warranties::activate(
				(int) $serial->id,
				array(
					'full_name' => $name,
					'email'     => $email,
					'phone'     => $phone,
				),
				array(
					'terms'       => $terms,
					'ip'          => null,
					'user_agent'  => null,
					'recorded_by' => get_current_user_id(),
				),
				'admin'
			);
			if ( is_wp_error( $w ) ) {
				self::notice( 'Η ενεργοποίηση απέτυχε.', 'error' );
			} else {
				WR_Audit::log( 'warranty_manual', 'warranty', (int) $w->id, array( 'serial' => $serial->serial ) );
				self::notice( 'Η εγγύηση ενεργοποιήθηκε: ' . $w->code );
				wp_safe_redirect( self::url( 'wr-warranties', array( 'id' => $w->id ) ) );
				exit;
			}
		}
		wp_safe_redirect( $fallback );
		exit;
	}

	public static function action_warranty_update(): void {
		self::guard( 'warranty_update', 'wr_edit' );
		$id    = absint( $_POST['id'] ?? 0 );
		$w     = WR_Warranties::get( $id );
		$email = sanitize_email( self::req( 'email' ) );
		$phone = WR_Util::normalize_phone( self::req( 'phone' ) );
		if ( ! $w || $w->anonymized_at || ! is_email( $email ) || '' === $phone || '' === self::req( 'full_name' ) ) {
			self::notice( 'Ελέγξτε ονοματεπώνυμο, email και κινητό.', 'error' );
			self::back( self::url( 'wr-warranties' ) );
		}
		$new = array(
			'full_name' => self::req( 'full_name' ),
			'email'     => $email,
			'phone'     => $phone,
			'notes'     => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		);
		$changes = array();
		foreach ( $new as $k => $v ) {
			if ( (string) $w->$k !== $v ) {
				$changes[ $k ] = array( (string) $w->$k, $v );
			}
		}
		WR_Warranties::update( $id, $new );
		if ( $changes ) {
			// Field names only: the audit log must not become a second copy of personal data.
			WR_Audit::log( 'warranty_update', 'warranty', $id, array( 'fields' => array_keys( $changes ) ) );
		}
		self::notice( 'Τα στοιχεία αποθηκεύτηκαν.' );
		self::back( self::url( 'wr-warranties', array( 'id' => $id ) ) );
	}

	public static function action_warranty_extend(): void {
		self::guard( 'warranty_extend', 'wr_manage' );
		$id   = absint( $_POST['id'] ?? 0 );
		$w    = WR_Warranties::get( $id );
		$date = WR_Util::parse_date( self::req( 'expires_on' ) );
		if ( ! $w || ! $date || '' === self::req( 'reason' ) ) {
			self::notice( 'Μη έγκυρη ημερομηνία ή κενή αιτιολογία.', 'error' );
		} else {
			WR_Warranties::update( $id, array( 'expires_on' => $date ) );
			WR_Audit::log( 'warranty_extend', 'warranty', $id, array( 'from' => $w->expires_on, 'to' => $date, 'reason' => self::req( 'reason' ) ) );
			self::notice( 'Η ημερομηνία λήξης άλλαξε.' );
		}
		self::back( self::url( 'wr-warranties', array( 'id' => $id ) ) );
	}

	public static function action_warranty_void(): void {
		self::set_warranty_status( 'warranty_void', 'void' );
	}

	public static function action_warranty_restore(): void {
		self::set_warranty_status( 'warranty_restore', 'active' );
	}

	private static function set_warranty_status( string $action, string $status ): void {
		self::guard( $action, 'wr_manage' );
		$id = absint( $_POST['id'] ?? 0 );
		if ( WR_Warranties::get( $id ) && '' !== self::req( 'reason' ) ) {
			WR_Warranties::update( $id, array( 'status' => $status ) );
			WR_Audit::log( $action, 'warranty', $id, array( 'reason' => self::req( 'reason' ) ) );
			self::notice( 'void' === $status ? 'Η εγγύηση ακυρώθηκε.' : 'Η εγγύηση επανήλθε σε ενεργή.' );
		} else {
			self::notice( 'Συμπληρώστε αιτιολογία.', 'error' );
		}
		self::back( self::url( 'wr-warranties', array( 'id' => $id ) ) );
	}

	public static function action_warranty_resend(): void {
		self::guard( 'warranty_resend', 'wr_edit' );
		$id = absint( $_POST['id'] ?? 0 );
		$w  = WR_Warranties::get( $id );
		if ( $w && ! $w->anonymized_at ) {
			$res    = WR_Notifier::send_activation( $w );
			$failed = array_keys( array_filter( $res, 'is_wp_error' ) );
			WR_Audit::log( 'warranty_resend', 'warranty', $id );
			self::notice( $failed ? 'Αποτυχία αποστολής: ' . implode( ', ', $failed ) . '. Δείτε τα μηνύματα παρακάτω.' : 'Η επιβεβαίωση στάλθηκε ξανά.', $failed ? 'error' : 'success' );
		}
		self::back( self::url( 'wr-warranties', array( 'id' => $id ) ) );
	}

	public static function action_warranty_anonymize(): void {
		self::guard( 'warranty_anonymize', 'wr_manage' );
		$id = absint( $_POST['id'] ?? 0 );
		WR_Warranties::anonymize( $id );
		WR_Audit::log( 'warranty_anonymize', 'warranty', $id );
		self::notice( 'Τα προσωπικά στοιχεία ανωνυμοποιήθηκαν.' );
		self::back( self::url( 'wr-warranties', array( 'id' => $id ) ) );
	}

	public static function action_warranties_export(): void {
		self::guard( 'warranties_export', 'wr_manage' );
		if ( ! WR_Access::check_code( (string) wp_unslash( $_POST['code'] ?? '' ), 'export' ) ) {
			self::notice( 'Λάθος κωδικός 2FA. Η εξαγωγή δεν έγινε.', 'error' );
			self::back( self::url( 'wr-warranties' ) );
		}
		global $wpdb;
		$args  = array();
		$where = self::warranty_where( $args );
		$rows  = WR_Warranties::fetch_all( WR_Warranties::base_select() . " WHERE $where ORDER BY w.id DESC" );
		WR_Audit::log( 'warranties_export', 'warranty', null, array_merge( $args, array( 'rows' => count( $rows ) ) ) );
		$user = wp_get_current_user();
		$to   = (string) WR_Settings::get( 'admin_email' );
		if ( $to ) {
			wp_mail( $to, '[Εγγυήσεις] Εξαγωγή στοιχείων πελατών', sprintf( "Ο χρήστης %s εξήγαγε %d εγγυήσεις με στοιχεία πελατών.\nIP: %s\nΏρα: %s", $user->user_login, count( $rows ), WR_Util::client_ip(), WR_Util::now() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="warranties-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads Greek correctly.
		fputcsv( $out, array( 'Κωδικός', 'Σειριακός', 'Κωδ. προϊόντος', 'Προϊόν', 'Ονοματεπώνυμο', 'Email', 'Κινητό', 'Έναρξη', 'Λήξη', 'Κατάσταση', 'Πηγή', 'Ενεργοποίηση' ), ';', '"', '' );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array( $r->code, $r->serial, $r->product_code, $r->product_name, $r->full_name, $r->email, $r->phone, WR_Util::format_date( $r->starts_on ), WR_Util::format_date( $r->expires_on ), WR_Warranties::status_label( WR_Warranties::effective_status( $r ) ), $r->source, $r->created_at )
				),
				';',
				'"',
				''
			);
		}
		fclose( $out );
		exit;
	}

	/** Prevents spreadsheet formula injection from customer-entered values. */
	public static function csv_safe( $v ): string {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/* ------------------------------------------------------------------ */
	/* Serials                                                             */
	/* ------------------------------------------------------------------ */

	public static function page_serials(): void {
		global $wpdb;
		$s      = WR_DB::table( 'serials' );
		$p      = WR_DB::table( 'products' );
		$w      = WR_DB::table( 'warranties' );
		$where  = array( '1=1' );
		$q      = self::req( 's' );
		$status = self::req( 'status' );
		$batch  = absint( self::req( 'batch' ) );
		if ( '' !== $q ) {
			$where[] = $wpdb->prepare( 's.serial_key LIKE %s', '%' . $wpdb->esc_like( WR_Util::serial_key( $q ) ) . '%' );
		}
		if ( in_array( $status, array( 'available', 'activated', 'void' ), true ) ) {
			$where[] = $wpdb->prepare( 's.status = %s', $status );
		}
		if ( $batch ) {
			$where[] = $wpdb->prepare( 's.batch_id = %d', $batch );
		}
		$where = implode( ' AND ', $where );
		$paged = max( 1, absint( self::req( 'paged', '1' ) ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $s s WHERE $where" );
		$rows  = $wpdb->get_results(
			"SELECT s.*, p.code AS product_code, p.name AS product_name, w.id AS warranty_id, w.code AS warranty_code
			FROM $s s LEFT JOIN $p p ON p.id = s.product_id LEFT JOIN $w w ON w.serial_id = s.id
			WHERE $where ORDER BY s.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ( ( $paged - 1 ) * self::PER_PAGE )
		);

		echo '<div class="wrap wr-wrap"><h1 class="wp-heading-inline">Σειριακοί αριθμοί</h1>';
		if ( current_user_can( 'wr_manage' ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( 'wr-import' ) ) . '">Εισαγωγή από Excel</a>';
		}
		echo '<hr class="wp-header-end">';
		echo '<form method="get" class="wr-filters"><input type="hidden" name="page" value="wr-serials">';
		if ( $batch ) {
			echo '<input type="hidden" name="batch" value="' . (int) $batch . '"><strong>Εισαγωγή #' . (int) $batch . '</strong> ';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Σειριακός"><select name="status"><option value="">Όλες οι καταστάσεις</option>';
		foreach ( array( 'available' => 'Διαθέσιμοι', 'activated' => 'Ενεργοποιημένοι', 'void' => 'Ακυρωμένοι' ) as $k => $v ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $status, $k, false ), esc_html( $v ) );
		}
		echo '</select> <button class="button">Αναζήτηση</button></form>';

		printf( '<p>%s σειριακοί</p>', esc_html( number_format_i18n( $total ) ) );
		echo '<table class="widefat striped"><thead><tr><th>Σειριακός</th><th>Προϊόν</th><th>Ημ/νία πώλησης</th><th>Κατάσταση</th><th>Εγγύηση</th><th>Εισαγωγή</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$action = '';
			if ( current_user_can( 'wr_manage' ) && 'activated' !== $r->status ) {
				$to     = 'void' === $r->status ? 'available' : 'void';
				$action = sprintf( '<a href="%s">%s</a>', esc_url( self::action_url( 'serial_status', array( 'id' => $r->id, 'to' => $to ) ) ), 'void' === $to ? 'Ακύρωση' : 'Επαναφορά' );
			}
			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->serial ),
				esc_html( $r->product_name ?: (string) $r->product_code ),
				esc_html( WR_Util::format_date( $r->sale_date ) ),
				self::badge( $r->status ), // phpcs:ignore
				$r->warranty_id ? '<a href="' . esc_url( self::url( 'wr-warranties', array( 'id' => $r->warranty_id ) ) ) . '">' . esc_html( $r->warranty_code ) . '</a>' : '',
				$r->batch_id ? '#' . (int) $r->batch_id : 'χειροκίνητα',
				$action // phpcs:ignore
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Δεν βρέθηκαν σειριακοί.</td></tr>';
		}
		echo '</tbody></table>' . self::pagination( $total, $paged ); // phpcs:ignore

		if ( current_user_can( 'wr_manage' ) ) {
			echo '<h2>Προσθήκη σειριακού</h2>' . self::form_open( 'serial_add' ); // phpcs:ignore
			echo '<input name="serial" placeholder="Σειριακός" required> <select name="product_id"><option value="">— Προϊόν —</option>';
			foreach ( self::products() as $pr ) {
				printf( '<option value="%d">%s</option>', (int) $pr->id, esc_html( $pr->name ?: $pr->code ) );
			}
			echo '</select> <label>Ημ/νία πώλησης <input type="date" name="sale_date"></label> <button class="button">Προσθήκη</button></form>';
		}
		echo '</div>';
	}

	public static function action_serial_add(): void {
		self::guard( 'serial_add', 'wr_manage' );
		global $wpdb;
		$serial = WR_Util::clean_serial( self::req( 'serial' ) );
		$key    = WR_Util::serial_key( $serial );
		if ( '' === $key || mb_strlen( $serial ) > 64 ) {
			self::notice( 'Μη έγκυρος σειριακός.', 'error' );
			self::back( self::url( 'wr-serials' ) );
		}
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . WR_DB::table( 'serials' ) . " (serial, serial_key, product_id, sale_date, status, created_at) VALUES (%s, %s, NULLIF(%d, 0), NULLIF(%s, ''), 'available', %s)",
				$serial,
				$key,
				absint( $_POST['product_id'] ?? 0 ),
				(string) WR_Util::parse_date( self::req( 'sale_date' ) ),
				WR_Util::now()
			)
		);
		if ( $ok ) {
			WR_Audit::log( 'serial_add', 'serial', (int) $wpdb->insert_id, array( 'serial' => $serial ) );
			self::notice( 'Ο σειριακός προστέθηκε.' );
		} else {
			self::notice( 'Ο σειριακός υπάρχει ήδη.', 'error' );
		}
		self::back( self::url( 'wr-serials' ) );
	}

	public static function action_serial_status(): void {
		self::guard( 'serial_status', 'wr_manage' );
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		$to = 'void' === self::req( 'to' ) ? 'void' : 'available';
		$from = 'void' === $to ? 'available' : 'void';
		$n  = $wpdb->update( WR_DB::table( 'serials' ), array( 'status' => $to ), array( 'id' => $id, 'status' => $from ) );
		if ( $n ) {
			WR_Audit::log( 'void' === $to ? 'serial_void' : 'serial_restore', 'serial', $id );
			self::notice( 'void' === $to ? 'Ο σειριακός ακυρώθηκε και δεν μπορεί να ενεργοποιηθεί.' : 'Ο σειριακός είναι ξανά διαθέσιμος.' );
		}
		self::back( self::url( 'wr-serials' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	public static function page_import(): void {
		global $wpdb;
		$batch_id = absint( self::req( 'batch' ) );
		echo '<div class="wrap wr-wrap"><h1>Εισαγωγή σειριακών από Excel</h1>';

		if ( $batch_id && ( $b = WR_Importer::get_batch( $batch_id ) ) ) {
			echo '<div class="wr-panel" id="wr-import" data-batch="' . (int) $b->id . '" data-status="' . esc_attr( $b->status ) . '">';
			echo '<h2>Εισαγωγή #' . (int) $b->id . ' – ' . esc_html( $b->filename ) . '</h2>';
			echo '<div class="wr-progress"><div class="wr-progress__bar" style="width:' . (int) ( $b->total_rows ? 100 * $b->processed / $b->total_rows : 0 ) . '%"></div></div>';
			echo '<p class="wr-import-stats">';
			printf(
				'Γραμμές: <b data-k="processed">%d</b> / <b>%d</b> · Νέοι: <b data-k="inserted">%d</b> · Υπήρχαν ήδη: <b data-k="duplicates">%d</b> · Με σφάλμα: <b data-k="invalid">%d</b>',
				(int) $b->processed,
				(int) $b->total_rows,
				(int) $b->inserted,
				(int) $b->duplicates,
				(int) $b->invalid
			);
			echo '</p><p class="wr-import-status"></p><ul class="wr-import-errors">';
			foreach ( json_decode( (string) $b->errors, true ) ?: array() as $err ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			}
			echo '</ul><p><a class="button" href="' . esc_url( self::url( 'wr-serials', array( 'batch' => $b->id ) ) ) . '">Προβολή σειριακών της εισαγωγής</a> <a class="button" href="' . esc_url( self::url( 'wr-import' ) ) . '">Νέα εισαγωγή</a></p></div></div>';
			return;
		}

		echo '<div class="wr-panel"><p>Ανεβάστε αρχείο <b>.xlsx</b> ή <b>.csv</b>. Διαβάζεται το πρώτο φύλλο. Αν η πρώτη γραμμή έχει επικεφαλίδες, αναγνωρίζονται οι στήλες:</p><ul class="ul-disc">';
		echo '<li><b>Σειριακός</b> (υποχρεωτικό) – π.χ. «Serial», «SN», «Σειριακός»</li>';
		echo '<li><b>Μοντέλο</b> (προαιρετικό) – π.χ. «Model», «Μοντέλο», «Κωδικός», «SKU»</li>';
		echo '<li><b>Ημερομηνία πώλησης</b> (προαιρετικό) – π.χ. «Date», «Ημερομηνία»</li></ul>';
		echo '<p>Χωρίς επικεφαλίδες, η στήλη A θεωρείται σειριακός, η B μοντέλο και η C ημερομηνία. Σειριακοί που υπάρχουν ήδη παραλείπονται.</p>';
		echo self::form_open( 'import_upload', 'enctype="multipart/form-data"' ); // phpcs:ignore
		echo '<p><input type="file" name="file" accept=".xlsx,.csv" required></p>';
		echo '<p><label><input type="checkbox" name="auto_products" value="1" checked> Δημιουργία νέων προϊόντων για άγνωστα μοντέλα (με προεπιλεγμένη διάρκεια ' . (int) WR_Settings::get( 'default_duration_months' ) . ' μηνών)</label></p>';
		echo '<p><button class="button button-primary">Ανέβασμα & εισαγωγή</button> <span class="description">Μέγιστο μέγεθος: ' . esc_html( size_format( wp_max_upload_size() ) ) . '</span></p></form></div>';

		$batches = $wpdb->get_results( 'SELECT * FROM ' . WR_DB::table( 'batches' ) . ' ORDER BY id DESC LIMIT 30' );
		if ( $batches ) {
			echo '<h2>Προηγούμενες εισαγωγές</h2><table class="widefat striped"><thead><tr><th>#</th><th>Αρχείο</th><th>Ημ/νία</th><th>Χρήστης</th><th>Γραμμές</th><th>Νέοι</th><th>Υπήρχαν</th><th>Σφάλματα</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
			$labels = array( 'pending' => 'Σε εξέλιξη', 'done' => 'Ολοκληρώθηκε', 'reverted' => 'Αναιρέθηκε', 'failed' => 'Απέτυχε' );
			foreach ( $batches as $b ) {
				$u      = get_userdata( (int) $b->user_id );
				$revert = 'reverted' !== $b->status ? sprintf( '<a href="%s" data-confirm="Θα διαγραφούν οι σειριακοί αυτής της εισαγωγής που δεν έχουν ενεργοποιηθεί. Συνέχεια;">Αναίρεση</a>', esc_url( self::action_url( 'import_revert', array( 'id' => $b->id ) ) ) ) : '';
				printf(
					'<tr><td><a href="%s">%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
					esc_url( self::url( 'wr-import', array( 'batch' => $b->id ) ) ),
					(int) $b->id,
					esc_html( $b->filename ),
					esc_html( $b->created_at ),
					esc_html( $u ? $u->display_name : '' ),
					(int) $b->total_rows,
					(int) $b->inserted,
					(int) $b->duplicates,
					(int) $b->invalid,
					esc_html( $labels[ $b->status ] ?? $b->status ),
					$revert // phpcs:ignore
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function action_import_upload(): void {
		self::guard( 'import_upload', 'wr_manage' );
		$res = WR_Importer::start( $_FILES['file'] ?? array(), ! empty( $_POST['auto_products'] ) ); // phpcs:ignore
		if ( is_wp_error( $res ) ) {
			self::notice( $res->get_error_message(), 'error' );
			wp_safe_redirect( self::url( 'wr-import' ) );
		} else {
			wp_safe_redirect( self::url( 'wr-import', array( 'batch' => $res ) ) );
		}
		exit;
	}

	public static function ajax_import_chunk(): void {
		check_ajax_referer( 'wr_import_chunk' );
		if ( ! current_user_can( 'wr_manage' ) ) {
			wp_send_json_error( null, 403 );
		}
		$b = WR_Importer::process_chunk( absint( $_POST['batch'] ?? 0 ) );
		if ( ! $b ) {
			wp_send_json_error( null, 404 );
		}
		wp_send_json_success(
			array(
				'status'     => $b->status,
				'total'      => (int) $b->total_rows,
				'processed'  => (int) $b->processed,
				'inserted'   => (int) $b->inserted,
				'duplicates' => (int) $b->duplicates,
				'invalid'    => (int) $b->invalid,
				'errors'     => json_decode( (string) $b->errors, true ) ?: array(),
			)
		);
	}

	public static function action_import_revert(): void {
		self::guard( 'import_revert', 'wr_manage' );
		$r = WR_Importer::revert( absint( $_GET['id'] ?? 0 ) );
		self::notice( sprintf( 'Διαγράφηκαν %d σειριακοί. %d παρέμειναν επειδή έχουν ήδη ενεργοποιηθεί ή ακυρωθεί.', $r['deleted'], $r['kept'] ) );
		wp_safe_redirect( self::url( 'wr-import' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Products                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_products(): void {
		global $wpdb;
		$p    = WR_DB::table( 'products' );
		$s    = WR_DB::table( 'serials' );
		$rows = $wpdb->get_results( "SELECT p.*, (SELECT COUNT(*) FROM $s s WHERE s.product_id = p.id) AS serials FROM $p p ORDER BY p.code" );
		$edit = absint( self::req( 'edit' ) );
		$cur  = $edit ? WR_Warranties::get_product( $edit ) : null;

		echo '<div class="wrap wr-wrap"><h1>Προϊόντα</h1><p>Η διάρκεια εγγύησης ορίζεται ανά προϊόν. Αλλαγή διάρκειας ισχύει για νέες ενεργοποιήσεις· οι υπάρχουσες εγγυήσεις δεν αλλάζουν.</p>';
		echo '<div class="wr-panels"><div class="wr-panel"><table class="widefat striped"><thead><tr><th>Κωδικός</th><th>Όνομα</th><th>Διάρκεια</th><th>Σειριακοί</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$del = ! (int) $r->serials ? ' | <a href="' . esc_url( self::action_url( 'product_delete', array( 'id' => $r->id ) ) ) . '" data-confirm="Διαγραφή προϊόντος;">Διαγραφή</a>' : '';
			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%d μήνες</td><td>%s</td><td><a href="%s">Επεξεργασία</a>%s</td></tr>',
				esc_html( $r->code ),
				esc_html( $r->name ),
				(int) $r->duration_months,
				esc_html( number_format_i18n( (int) $r->serials ) ),
				esc_url( self::url( 'wr-products', array( 'edit' => $r->id ) ) ),
				$del // phpcs:ignore
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Δεν υπάρχουν προϊόντα. Προστίθενται εδώ ή αυτόματα κατά την εισαγωγή.</td></tr>';
		}
		echo '</tbody></table></div><div class="wr-panel"><h2>' . ( $cur ? 'Επεξεργασία προϊόντος' : 'Νέο προϊόν' ) . '</h2>';
		echo self::form_open( 'product_save' ) . '<input type="hidden" name="id" value="' . (int) ( $cur->id ?? 0 ) . '"><table class="form-table">'; // phpcs:ignore
		echo '<tr><th><label for="code">Κωδικός / μοντέλο</label></th><td><input id="code" name="code" class="regular-text" required value="' . esc_attr( $cur->code ?? '' ) . '"></td></tr>';
		echo '<tr><th><label for="name">Όνομα</label></th><td><input id="name" name="name" class="regular-text" value="' . esc_attr( $cur->name ?? '' ) . '"></td></tr>';
		echo '<tr><th><label for="duration">Διάρκεια εγγύησης (μήνες)</label></th><td><input id="duration" type="number" min="1" max="600" name="duration_months" required value="' . (int) ( $cur->duration_months ?? WR_Settings::get( 'default_duration_months' ) ) . '"></td></tr>';
		echo '</table><p><button class="button button-primary">Αποθήκευση</button>' . ( $cur ? ' <a class="button" href="' . esc_url( self::url( 'wr-products' ) ) . '">Ακύρωση</a>' : '' ) . '</p></form></div></div></div>';
	}

	public static function action_product_save(): void {
		self::guard( 'product_save', 'wr_manage' );
		global $wpdb;
		$id   = absint( $_POST['id'] ?? 0 );
		$data = array(
			'code'            => mb_substr( self::req( 'code' ), 0, 64 ),
			'name'            => mb_substr( self::req( 'name' ), 0, 191 ),
			'duration_months' => max( 1, min( 600, absint( $_POST['duration_months'] ?? 24 ) ) ),
		);
		if ( '' === $data['code'] ) {
			self::notice( 'Ο κωδικός είναι υποχρεωτικός.', 'error' );
		} else {
			$t  = WR_DB::table( 'products' );
			$ok = $id ? $wpdb->update( $t, $data, array( 'id' => $id ) ) : $wpdb->insert( $t, $data + array( 'created_at' => WR_Util::now() ) );
			if ( false === $ok ) {
				self::notice( 'Υπάρχει ήδη προϊόν με αυτόν τον κωδικό.', 'error' );
			} else {
				WR_Audit::log( 'product_save', 'product', $id ?: (int) $wpdb->insert_id, $data );
				self::notice( 'Το προϊόν αποθηκεύτηκε.' );
			}
		}
		wp_safe_redirect( self::url( 'wr-products' ) );
		exit;
	}

	public static function action_product_delete(): void {
		self::guard( 'product_delete', 'wr_manage' );
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WR_DB::table( 'serials' ) . ' WHERE product_id = %d', $id ) ) ) {
			$wpdb->delete( WR_DB::table( 'products' ), array( 'id' => $id ) );
			WR_Audit::log( 'product_delete', 'product', $id );
			self::notice( 'Το προϊόν διαγράφηκε.' );
		}
		wp_safe_redirect( self::url( 'wr-products' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                               */
	/* ------------------------------------------------------------------ */

	public static function page_terms(): void {
		$view = absint( self::req( 'view' ) );
		echo '<div class="wrap wr-wrap"><h1>Όροι εγγύησης</h1>';
		if ( $view && ( $t = WR_Terms::get( $view ) ) ) {
			echo '<p><a href="' . esc_url( self::url( 'wr-terms' ) ) . '">&larr; Όροι εγγύησης</a></p>';
			printf( '<h2>Έκδοση %s</h2><p>Δημοσίευση: %s · SHA-256: <code>%s</code></p>', esc_html( $t->version ), esc_html( $t->published_at ), esc_html( $t->content_hash ) );
			echo '<div class="wr-panel wr-terms-view">' . wp_kses_post( wpautop( $t->content ) ) . '</div></div>';
			return;
		}

		$cur = WR_Terms::current();
		echo '<p>Κάθε αλλαγή δημοσιεύεται ως νέα έκδοση. Οι παλιές εκδόσεις διατηρούνται αμετάβλητες, ώστε να αποδεικνύεται ποιο κείμενο αποδέχθηκε κάθε πελάτης.</p>';
		echo self::form_open( 'terms_publish', 'data-confirm="Να δημοσιευθεί νέα έκδοση όρων; Θα ισχύει για τις νέες ενεργοποιήσεις."' ); // phpcs:ignore
		echo '<p><label>Αριθμός νέας έκδοσης <input name="version" required value="' . esc_attr( self::next_version( $cur->version ?? '0' ) ) . '"></label></p>';
		wp_editor( $cur->content ?? '', 'wr_terms_content', array( 'textarea_name' => 'content', 'textarea_rows' => 18, 'media_buttons' => false ) );
		echo '<p><button class="button button-primary">Δημοσίευση νέας έκδοσης</button></p></form>';

		echo '<h2>Εκδόσεις</h2><table class="widefat striped"><thead><tr><th>Έκδοση</th><th>Δημοσίευση</th><th>Από</th><th>SHA-256</th><th></th></tr></thead><tbody>';
		foreach ( WR_Terms::all() as $t ) {
			$u = get_userdata( (int) $t->published_by );
			printf(
				'<tr><td><a href="%s">%s</a>%s</td><td>%s</td><td>%s</td><td><code>%s…</code></td><td></td></tr>',
				esc_url( self::url( 'wr-terms', array( 'view' => $t->id ) ) ),
				esc_html( $t->version ),
				$t->is_current ? ' <span class="wr-badge wr-badge--active">τρέχουσα</span>' : '',
				esc_html( $t->published_at ),
				esc_html( $u ? $u->display_name : '—' ),
				esc_html( substr( $t->content_hash, 0, 16 ) )
			);
		}
		echo '</tbody></table></div>';
	}

	private static function next_version( string $v ): string {
		if ( preg_match( '/^(\d+)\.(\d+)$/', $v, $m ) ) {
			return $m[1] . '.' . ( (int) $m[2] + 1 );
		}
		return $v . '-1';
	}

	public static function action_terms_publish(): void {
		self::guard( 'terms_publish', 'wr_manage' );
		$content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
		$version = mb_substr( self::req( 'version' ), 0, 32 );
		if ( '' === trim( wp_strip_all_tags( $content ) ) || '' === $version ) {
			self::notice( 'Συμπληρώστε έκδοση και κείμενο.', 'error' );
		} else {
			$id = WR_Terms::publish( $version, $content, get_current_user_id() );
			WR_Audit::log( 'terms_publish', 'terms', $id, array( 'version' => $version ) );
			self::notice( 'Δημοσιεύθηκε η έκδοση ' . $version . '.' );
		}
		wp_safe_redirect( self::url( 'wr-terms' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Notifications                                                       */
	/* ------------------------------------------------------------------ */

	public static function page_notifications(): void {
		global $wpdb;
		$rules = $wpdb->get_results( 'SELECT * FROM ' . WR_DB::table( 'rules' ) . ' ORDER BY recipient, channel, days_before DESC' );
		echo '<div class="wrap wr-wrap"><h1>Ειδοποιήσεις λήξης</h1>';
		self::cron_warning();
		echo '<p>Ορίστε πόσες ημέρες πριν τη λήξη θα ειδοποιείται ο πελάτης ή η εταιρεία. Ο έλεγχος τρέχει κάθε ώρα. Αν ένας πελάτης ενεργοποιήσει εγγύηση που λήγει σε λιγότερες ημέρες από έναν κανόνα, λαμβάνει μόνο μία ειδοποίηση. Για την εταιρεία στέλνεται ένα συγκεντρωτικό email.</p>';
		$next = wp_next_scheduled( WR_Cron::HOOK );
		echo '<p>Τελευταία εκτέλεση: <b>' . esc_html( get_option( 'wr_cron_last_run' ) ?: '—' ) . '</b> · Επόμενη: <b>' . esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) : '—' ) . '</b> ';
		echo '<a class="button button-small" href="' . esc_url( self::action_url( 'run_cron' ) ) . '">Εκτέλεση τώρα</a></p>';

		echo '<div class="wr-panels"><div class="wr-panel"><h2>Κανόνες</h2><table class="widefat striped"><thead><tr><th>Ημέρες πριν</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
		foreach ( $rules as $r ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a href="%s" data-confirm="Διαγραφή κανόνα;">Διαγραφή</a></td></tr>',
				(int) $r->days_before,
				'sms' === $r->channel ? 'SMS' : 'Email',
				'admin' === $r->recipient ? 'Εταιρεία' : 'Πελάτης',
				$r->active ? 'Ενεργός' : 'Ανενεργός',
				esc_url( self::action_url( 'rule_toggle', array( 'id' => $r->id ) ) ),
				$r->active ? 'Απενεργοποίηση' : 'Ενεργοποίηση',
				esc_url( self::action_url( 'rule_delete', array( 'id' => $r->id ) ) )
			);
		}
		echo '</tbody></table>';
		if ( ! WR_Settings::get( 'sms_enabled' ) ) {
			echo '<p class="description">Οι κανόνες SMS δεν εκτελούνται μέχρι να ενεργοποιηθεί η αποστολή SMS στις Ρυθμίσεις.</p>';
		}
		echo '</div><div class="wr-panel"><h2>Νέος κανόνας</h2>' . self::form_open( 'rule_save' ); // phpcs:ignore
		echo '<p><label><input type="number" name="days_before" min="0" max="3650" value="30" required style="width:6em"> ημέρες πριν τη λήξη</label></p>';
		echo '<p><select name="channel"><option value="email">Email</option><option value="sms">SMS</option></select> ';
		echo '<select name="recipient"><option value="customer">στον πελάτη</option><option value="admin">στην εταιρεία (μόνο email)</option></select></p>';
		echo '<p><button class="button button-primary">Προσθήκη</button></p></form></div></div>';

		$failed_only = '1' === self::req( 'failed' );
		$log         = $wpdb->get_results( 'SELECT * FROM ' . WR_DB::table( 'notification_log' ) . ( $failed_only ? " WHERE status = 'failed'" : '' ) . ' ORDER BY id DESC LIMIT 200' );
		echo '<h2>Πρόσφατα μηνύματα</h2><p><a href="' . esc_url( self::url( 'wr-notifications' ) ) . '">Όλα</a> | <a href="' . esc_url( self::url( 'wr-notifications', array( 'failed' => 1 ) ) ) . '">Μόνο αποτυχημένα</a></p>';
		self::render_notification_log( $log, true );
		echo '</div>';
	}

	private static function render_notification_log( array $log, bool $with_link = false ): void {
		$kinds = array( 'activation' => 'Επιβεβαίωση', 'reminder' => 'Υπενθύμιση λήξης', 'admin_digest' => 'Σύνοψη εταιρείας', 'test' => 'Δοκιμή' );
		echo '<table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Τύπος</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Αποτέλεσμα</th>' . ( $with_link ? '<th>Εγγύηση</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $log as $l ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>%s</tr>',
				esc_html( $l->sent_at ),
				esc_html( $kinds[ $l->kind ] ?? $l->kind ),
				'sms' === $l->channel ? 'SMS' : 'Email',
				esc_html( WR_Crypto::decrypt( $l->recipient ) ),
				'sent' === $l->status ? '<span class="wr-badge wr-badge--active">Στάλθηκε</span>' : '<span class="wr-badge wr-badge--void">Απέτυχε</span> ' . esc_html( (string) $l->error ),
				$with_link ? '<td>' . ( $l->warranty_id ? '<a href="' . esc_url( self::url( 'wr-warranties', array( 'id' => $l->warranty_id ) ) ) . '">#' . (int) $l->warranty_id . '</a>' : '' ) . '</td>' : ''
			);
		}
		if ( ! $log ) {
			echo '<tr><td colspan="6">Δεν υπάρχουν μηνύματα.</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function action_rule_save(): void {
		self::guard( 'rule_save', 'wr_manage' );
		global $wpdb;
		$channel   = 'sms' === self::req( 'channel' ) ? 'sms' : 'email';
		$recipient = 'admin' === self::req( 'recipient' ) ? 'admin' : 'customer';
		if ( 'admin' === $recipient ) {
			$channel = 'email';
		}
		$data = array(
			'days_before' => min( 3650, absint( $_POST['days_before'] ?? 30 ) ),
			'channel'     => $channel,
			'recipient'   => $recipient,
			'active'      => 1,
			'created_at'  => WR_Util::now(),
		);
		$wpdb->insert( WR_DB::table( 'rules' ), $data );
		WR_Audit::log( 'rule_save', 'rule', (int) $wpdb->insert_id, $data );
		self::notice( 'Ο κανόνας προστέθηκε.' );
		wp_safe_redirect( self::url( 'wr-notifications' ) );
		exit;
	}

	public static function action_rule_toggle(): void {
		self::guard( 'rule_toggle', 'wr_manage' );
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . WR_DB::table( 'rules' ) . ' SET active = 1 - active WHERE id = %d', $id ) );
		WR_Audit::log( 'rule_save', 'rule', $id, 'toggle' );
		wp_safe_redirect( self::url( 'wr-notifications' ) );
		exit;
	}

	public static function action_rule_delete(): void {
		self::guard( 'rule_delete', 'wr_manage' );
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		$wpdb->delete( WR_DB::table( 'rules' ), array( 'id' => $id ) );
		WR_Audit::log( 'rule_delete', 'rule', $id );
		self::notice( 'Ο κανόνας διαγράφηκε.' );
		wp_safe_redirect( self::url( 'wr-notifications' ) );
		exit;
	}

	public static function action_run_cron(): void {
		self::guard( 'run_cron', 'wr_manage' );
		delete_transient( 'wr_cron_lock' );
		WR_Cron::run();
		self::notice( 'Ο έλεγχος ειδοποιήσεων εκτελέστηκε.' );
		wp_safe_redirect( self::url( 'wr-notifications' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_settings(): void {
		$s = WR_Settings::all();
		echo '<div class="wrap wr-wrap"><h1>Ρυθμίσεις εγγυήσεων</h1>';
		echo self::form_open( 'settings_save' ); // phpcs:ignore

		echo '<h2>Γενικά</h2><table class="form-table">';
		self::field_number( 'default_duration_months', 'Προεπιλεγμένη διάρκεια εγγύησης (μήνες)', $s, 'Για σειριακούς χωρίς προϊόν και για νέα προϊόντα από εισαγωγή.' );
		echo '<tr><th>Έναρξη εγγύησης</th><td><label><input type="radio" name="s[start_mode]" value="activation"' . checked( $s['start_mode'], 'activation', false ) . '> Από την ημερομηνία ενεργοποίησης</label><br>';
		echo '<label><input type="radio" name="s[start_mode]" value="sale_date"' . checked( $s['start_mode'], 'sale_date', false ) . '> Από την ημερομηνία πώλησης του αρχείου εισαγωγής (αν υπάρχει, αλλιώς από την ενεργοποίηση)</label></td></tr>';
		self::field_text( 'code_prefix', 'Πρόθεμα κωδικού εγγύησης', $s, 'π.χ. WR → WR-7K3M-Q9XP' );
		self::field_text( 'admin_email', 'Email εταιρείας για ειδοποιήσεις', $s );
		self::field_text( 'check_page_url', 'URL σελίδας «Έλεγχος εγγύησης»', $s, 'Η σελίδα με το shortcode [warranty_check]. Μπαίνει στα μηνύματα ως {check_url}.' );
		self::field_text( 'privacy_page_url', 'URL πολιτικής απορρήτου', $s, 'Αν μείνει κενό, χρησιμοποιείται η σελίδα απορρήτου του WordPress.' );
		echo '</table>';

		echo '<h2>Ασφάλεια</h2><table class="form-table">';
		self::field_text( 'turnstile_site_key', 'Cloudflare Turnstile – Site key', $s, 'Δωρεάν CAPTCHA από dash.cloudflare.com → Turnstile. Αν μείνει κενό, η φόρμα λειτουργεί μόνο με όριο προσπαθειών.' );
		self::field_text( 'turnstile_secret_key', 'Cloudflare Turnstile – Secret key', $s );
		self::field_number( 'rate_limit_attempts', 'Μέγιστες προσπάθειες ανά IP', $s );
		self::field_number( 'rate_limit_minutes', 'μέσα σε (λεπτά)', $s );
		self::field_check( 'trust_cloudflare_ip', 'Το site βρίσκεται πίσω από Cloudflare (χρήση CF-Connecting-IP)', $s );
		echo '</table>';

		echo '<h2>GDPR</h2><table class="form-table">';
		self::field_check( 'retention_enabled', 'Αυτόματη ανωνυμοποίηση στοιχείων πελατών μετά τη λήξη', $s );
		self::field_number( 'retention_years', 'Έτη μετά τη λήξη', $s, 'Συμβουλευτείτε τον νομικό σας για τη σωστή διάρκεια.' );
		self::field_check( 'delete_data_on_uninstall', 'Διαγραφή όλων των δεδομένων κατά την απεγκατάσταση του plugin', $s );
		echo '</table>';

		echo '<h2>Μηνύματα</h2><p>Διαθέσιμα πεδία: <code>{name}</code> <code>{code}</code> <code>{serial}</code> <code>{product}</code> <code>{starts_on}</code> <code>{expires_on}</code> <code>{days_left}</code> <code>{check_url}</code> <code>{site_name}</code></p><table class="form-table">';
		self::field_check( 'activation_email', 'Αποστολή email με τον κωδικό εγγύησης', $s );
		self::field_check( 'activation_sms', 'Αποστολή SMS με τον κωδικό εγγύησης', $s );
		self::field_text( 'tpl_activation_subject', 'Επιβεβαίωση – θέμα email', $s );
		self::field_textarea( 'tpl_activation_body', 'Επιβεβαίωση – κείμενο email', $s );
		self::field_text( 'tpl_activation_sms', 'Επιβεβαίωση – SMS', $s, 'Ελληνικά χωρίς τόνους ή με κεφαλαία χωράνε περισσότεροι χαρακτήρες ανά SMS.' );
		self::field_text( 'tpl_reminder_subject', 'Υπενθύμιση – θέμα email', $s );
		self::field_textarea( 'tpl_reminder_body', 'Υπενθύμιση – κείμενο email', $s );
		self::field_text( 'tpl_reminder_sms', 'Υπενθύμιση – SMS', $s );
		echo '</table>';

		echo '<h2>Πάροχος SMS</h2><p>Γενική σύνδεση HTTP που ταιριάζει στους περισσότερους παρόχους (Yuboto, Apifon, Routee, Twilio κ.ά.). Πεδία: <code>{to}</code> <code>{message}</code> <code>{sender}</code> <code>{api_key}</code>. Το <code>{api_key}</code> διαβάζεται από τη σταθερά <code>WR_SMS_API_KEY</code> στο wp-config.php.</p><table class="form-table">';
		self::field_check( 'sms_enabled', 'Ενεργοποίηση αποστολής SMS', $s );
		self::field_text( 'sms_endpoint', 'URL API', $s );
		echo '<tr><th>Μέθοδος / μορφή</th><td><select name="s[sms_method]"><option' . selected( $s['sms_method'], 'POST', false ) . '>POST</option><option' . selected( $s['sms_method'], 'GET', false ) . '>GET</option></select> ';
		echo '<select name="s[sms_body_format]"><option value="json"' . selected( $s['sms_body_format'], 'json', false ) . '>JSON</option><option value="form"' . selected( $s['sms_body_format'], 'form', false ) . '>Form / query string</option></select> ';
		echo '<select name="s[sms_to_format]"><option value="e164"' . selected( $s['sms_to_format'], 'e164', false ) . '>Αριθμός ως +3069…</option><option value="digits"' . selected( $s['sms_to_format'], 'digits', false ) . '>Αριθμός ως 3069…</option></select></td></tr>';
		self::field_textarea( 'sms_headers', 'Headers (ένα ανά γραμμή)', $s, 3 );
		self::field_textarea( 'sms_body', 'Σώμα αιτήματος', $s, 3 );
		self::field_text( 'sms_sender', 'Αποστολέας (sender ID)', $s, 'Έως 11 λατινικοί χαρακτήρες, π.χ. το όνομα της εταιρείας.' );
		echo '</table><p><button class="button button-primary">Αποθήκευση ρυθμίσεων</button></p></form>';

		echo '<h2>Δοκιμαστική αποστολή</h2>' . self::form_open( 'test_send' ); // phpcs:ignore
		echo '<p><select name="channel"><option value="email">Email</option><option value="sms">SMS</option></select> <input name="to" placeholder="email ή κινητό" required> <button class="button">Αποστολή δοκιμής</button></p></form>';

		echo '<h2>Shortcodes</h2><p><code>[warranty_activate]</code> – φόρμα ενεργοποίησης εγγύησης<br><code>[warranty_check]</code> – έλεγχος εγγύησης με κωδικό και σειριακό</p></div>';
	}

	private static function field_text( string $k, string $label, array $s, string $help = '' ): void {
		printf(
			'<tr><th><label for="wr-%1$s">%2$s</label></th><td><input class="regular-text" id="wr-%1$s" name="s[%1$s]" value="%3$s">%4$s</td></tr>',
			esc_attr( $k ),
			esc_html( $label ),
			esc_attr( (string) $s[ $k ] ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private static function field_number( string $k, string $label, array $s, string $help = '' ): void {
		printf(
			'<tr><th><label for="wr-%1$s">%2$s</label></th><td><input type="number" min="1" class="small-text" id="wr-%1$s" name="s[%1$s]" value="%3$d">%4$s</td></tr>',
			esc_attr( $k ),
			esc_html( $label ),
			(int) $s[ $k ],
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private static function field_check( string $k, string $label, array $s ): void {
		printf( '<tr><th></th><td><label><input type="checkbox" name="s[%1$s]" value="1"%2$s> %3$s</label></td></tr>', esc_attr( $k ), checked( (int) $s[ $k ], 1, false ), esc_html( $label ) );
	}

	private static function field_textarea( string $k, string $label, array $s, int $rows = 8 ): void {
		printf( '<tr><th><label for="wr-%1$s">%2$s</label></th><td><textarea class="large-text code" rows="%4$d" id="wr-%1$s" name="s[%1$s]">%3$s</textarea></td></tr>', esc_attr( $k ), esc_html( $label ), esc_textarea( (string) $s[ $k ] ), $rows );
	}

	public static function action_settings_save(): void {
		self::guard( 'settings_save', 'wr_manage' );
		$in = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? $_POST['s'] : array(); // phpcs:ignore -- sanitized in WR_Settings::sanitize().
		WR_Settings::update( WR_Settings::sanitize( $in ) );
		WR_Audit::log( 'settings_save', 'settings' );
		self::notice( 'Οι ρυθμίσεις αποθηκεύτηκαν.' );
		wp_safe_redirect( self::url( 'wr-settings' ) );
		exit;
	}

	public static function action_test_send(): void {
		self::guard( 'test_send', 'wr_manage' );
		$to = self::req( 'to' );
		if ( 'sms' === self::req( 'channel' ) ) {
			$phone = WR_Util::normalize_phone( $to );
			$res   = $phone ? WR_SMS::send( $phone, 'Dokimastiko SMS apo ' . get_bloginfo( 'name' ) ) : new WP_Error( 'x', 'Μη έγκυρος αριθμός.' );
			WR_Notifier::log( null, null, 'test', 'sms', $phone, $res );
		} else {
			$res = is_email( $to ) ? WR_Notifier::email( $to, 'Δοκιμαστικό μήνυμα εγγυήσεων', 'Αν λάβατε αυτό το μήνυμα, η αποστολή email λειτουργεί.' ) : new WP_Error( 'x', 'Μη έγκυρο email.' );
			WR_Notifier::log( null, null, 'test', 'email', $to, $res );
		}
		self::notice( true === $res ? 'Η δοκιμή στάλθηκε.' : 'Αποτυχία: ' . $res->get_error_message(), true === $res ? 'success' : 'error' );
		wp_safe_redirect( self::url( 'wr-settings' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Security & access                                                   */
	/* ------------------------------------------------------------------ */

	public static function page_security(): void {
		$yes = '<span class="wr-badge wr-badge--active">Ναι</span>';
		$no  = '<span class="wr-badge wr-badge--void">Όχι</span>';
		$opt = '<span class="wr-badge wr-badge--expired">Δεν έχει οριστεί</span>';

		echo '<div class="wrap wr-wrap"><h1>Ασφάλεια & πρόσβαση</h1>';
		echo '<div class="wr-panel"><h2>Κατάσταση</h2><table class="widefat striped wr-sec"><tbody>';
		$rows = array(
			array( 'Κρυπτογράφηση στοιχείων πελατών (WR_ENCRYPTION_KEY)', WR_Crypto::ready() ? $yes : $no, 'Ονοματεπώνυμα, email, τηλέφωνα, σημειώσεις, IP και στοιχεία συσκευής αποθηκεύονται κρυπτογραφημένα.' ),
			array( 'Υποχρεωτική επαλήθευση δύο βημάτων (2FA)', $yes, sprintf( 'Ανά σύνδεση, δεμένη με IP και browser. Λήγει μετά από %d λεπτά αδράνειας ή %d ώρες.', defined( 'WR_IDLE_MINUTES' ) ? (int) WR_IDLE_MINUTES : 30, defined( 'WR_2FA_SESSION_HOURS' ) ? (int) WR_2FA_SESSION_HOURS : 8 ) ),
			array( 'Επιτρεπόμενοι χρήστες (WR_ALLOWED_USERS)', defined( 'WR_ALLOWED_USERS' ) && WR_ALLOWED_USERS ? esc_html( (string) WR_ALLOWED_USERS ) : $opt, 'Μόνο αυτοί οι χρήστες βλέπουν το σύστημα εγγυήσεων, ανεξάρτητα από τον ρόλο τους.' ),
			array( 'Επιτρεπόμενες IP (WR_ALLOWED_IPS)', defined( 'WR_ALLOWED_IPS' ) && WR_ALLOWED_IPS ? esc_html( (string) WR_ALLOWED_IPS ) : $opt, 'Πρόσβαση μόνο από το γραφείο / VPN. Η τρέχουσα IP σας: <code>' . esc_html( WR_Util::client_ip() ) . '</code>' ),
			array( 'HTTPS', is_ssl() ? $yes : $no, 'Απαραίτητο για την προστασία των cookies σύνδεσης.' ),
			array( 'Απενεργοποιημένη επεξεργασία αρχείων από το admin (DISALLOW_FILE_EDIT)', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? $yes : $no, 'Αποτρέπει την αλλαγή κώδικα από το wp-admin αν παραβιαστεί λογαριασμός.' ),
			array( 'CAPTCHA στις δημόσιες φόρμες', WR_Security::captcha_enabled() ? $yes : $no, 'Cloudflare Turnstile – ρυθμίζεται στις Ρυθμίσεις.' ),
		);
		foreach ( $rows as $r ) {
			echo '<tr><th>' . esc_html( $r[0] ) . '</th><td>' . $r[1] . '</td><td>' . wp_kses( $r[2], array( 'code' => array() ) ) . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table>';
		echo '<p>Οι ρυθμίσεις πρόσβασης ορίζονται <strong>μόνο</strong> στο <code>wp-config.php</code>, ώστε να μην μπορούν να αλλάξουν από το wp-admin ακόμη κι αν παραβιαστεί λογαριασμός διαχειριστή. Παράδειγμα:</p>';
		echo '<pre class="wr-snippet">define( \'WR_ALLOWED_USERS\', \'' . esc_html( wp_get_current_user()->user_login ) . '\' );           // χρήστες, χωρισμένοι με κόμμα' . "\n"
			. 'define( \'WR_ALLOWED_IPS\', \'' . esc_html( WR_Util::client_ip() ?: '203.0.113.10' ) . '\' );      // IP ή δίκτυα (CIDR), χωρισμένα με κόμμα' . "\n"
			. 'define( \'WR_IDLE_MINUTES\', 30 );        // λήξη μετά από αδράνεια' . "\n"
			. 'define( \'WR_2FA_SESSION_HOURS\', 8 );    // μέγιστη διάρκεια επαλήθευσης' . "\n"
			. 'define( \'DISALLOW_FILE_EDIT\', true );</pre>';
		echo '<p class="description">Προσοχή με το WR_ALLOWED_IPS: αν η IP του γραφείου σας είναι δυναμική, μπορεί να κλειδωθείτε έξω. Τότε αφαιρέστε τη γραμμή από το wp-config.php μέσω cPanel → File Manager.</p></div>';

		$users = get_users( array( 'fields' => 'all' ) );
		echo '<div class="wr-panel"><h2>Χρήστες με πρόσβαση</h2><table class="widefat striped"><thead><tr><th>Χρήστης</th><th>Ρόλος</th><th>Δικαιώματα</th><th>2FA</th><th>Τελευταία επαλήθευση</th><th></th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$caps = array_values( array_filter( WR_Access::CAPS, fn( $c ) => ! empty( $u->allcaps[ $c ] ) ) );
			if ( ! $caps ) {
				continue;
			}
			$allowed = WR_Access::user_allowed( $u );
			$last    = self::last_audit( 'twofa_success', $u->ID );
			$reset   = WR_Access::has_2fa( $u->ID )
				? self::form_open( 'twofa_reset', 'data-confirm="Ο χρήστης θα πρέπει να ρυθμίσει ξανά την εφαρμογή επαλήθευσης. Συνέχεια;"' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-small">Επαναφορά 2FA</button></form>'
				: '';
			printf(
				'<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $u->user_login ),
				esc_html( $u->user_email ),
				esc_html( implode( ', ', $u->roles ) ),
				$allowed ? esc_html( implode( ', ', $caps ) ) : '<span class="wr-badge wr-badge--void">Αποκλεισμένος (WR_ALLOWED_USERS)</span>', // phpcs:ignore
				WR_Access::has_2fa( $u->ID ) ? '<span class="wr-badge wr-badge--active">Ενεργό</span>' : '<span class="wr-badge wr-badge--expired">Δεν έχει ρυθμιστεί</span>', // phpcs:ignore
				esc_html( $last ?: '—' ),
				$reset // phpcs:ignore
			);
		}
		echo '</tbody></table><p class="description">Η «Επαναφορά 2FA» χρειάζεται όταν κάποιος χάσει το κινητό του και τους κωδικούς ανάκτησης. Στην επόμενη είσοδο θα ρυθμίσει ξανά την εφαρμογή.</p></div>';

		global $wpdb;
		$events = $wpdb->get_results( 'SELECT * FROM ' . WR_DB::table( 'audit_log' ) . " WHERE action IN ('twofa_failed','twofa_lockout','twofa_reset','twofa_enabled','twofa_recovery_used','access_denied','warranties_export') ORDER BY id DESC LIMIT 30" );
		echo '<div class="wr-panel"><h2>Πρόσφατα συμβάντα ασφαλείας</h2>';
		self::render_audit( $events, true );
		echo '</div></div>';
	}

	private static function last_audit( string $action, int $user_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . WR_DB::table( 'audit_log' ) . ' WHERE action = %s AND object_type = %s AND object_id = %d', $action, 'user', $user_id ) );
	}

	public static function action_twofa_reset(): void {
		self::guard( 'twofa_reset', 'wr_manage' );
		$uid = absint( $_POST['user_id'] ?? 0 );
		if ( $uid ) {
			WR_Access::reset_user( $uid );
			self::notice( 'Η επαλήθευση δύο βημάτων επαναφέρθηκε. Ο χρήστης θα τη ρυθμίσει ξανά στην επόμενη είσοδο.' );
		}
		wp_safe_redirect( self::url( 'wr-security' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Audit                                                               */
	/* ------------------------------------------------------------------ */

	public static function page_audit(): void {
		global $wpdb;
		$a     = WR_DB::table( 'audit_log' );
		$paged = max( 1, absint( self::req( 'paged', '1' ) ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a" );
		$rows  = $wpdb->get_results( "SELECT * FROM $a ORDER BY id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ( ( $paged - 1 ) * self::PER_PAGE ) );
		echo '<div class="wrap wr-wrap"><h1>Ιστορικό ενεργειών</h1><p>Καταγράφεται κάθε προβολή, αλλαγή και εξαγωγή στοιχείων πελατών από το διαχειριστικό.</p>';
		self::render_audit( $rows, true );
		echo self::pagination( $total, $paged ) . '</div>'; // phpcs:ignore
	}

	private static function render_audit( array $rows, bool $with_object = false ): void {
		echo '<table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Χρήστης</th><th>Ενέργεια</th>' . ( $with_object ? '<th>Αντικείμενο</th>' : '' ) . '<th>Λεπτομέρειες</th><th>IP</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u   = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
			$obj = '';
			if ( $with_object && $r->object_id ) {
				$ou  = 'user' === $r->object_type ? get_userdata( (int) $r->object_id ) : null;
				$obj = 'warranty' === $r->object_type
					? '<a href="' . esc_url( self::url( 'wr-warranties', array( 'id' => $r->object_id ) ) ) . '">εγγύηση #' . (int) $r->object_id . '</a>'
					: esc_html( $ou ? 'χρήστης ' . $ou->user_login : $r->object_type . ' #' . $r->object_id );
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td>%s<td><code class="wr-details">%s</code></td><td>%s</td></tr>',
				esc_html( $r->created_at ),
				esc_html( $u ? $u->display_name : ( $r->user_id ? '#' . $r->user_id : 'σύστημα' ) ),
				esc_html( WR_Audit::action_label( $r->action ) ),
				$with_object ? '<td>' . $obj . '</td>' : '', // phpcs:ignore
				esc_html( (string) $r->details ),
				esc_html( (string) $r->ip_address )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Καμία εγγραφή.</td></tr>';
		}
		echo '</tbody></table>';
	}
}
