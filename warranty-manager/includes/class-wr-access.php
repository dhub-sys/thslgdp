<?php
defined( 'ABSPATH' ) || exit;

/**
 * Access control for the warranty admin area.
 *
 * - Optional allowlists, set only in wp-config.php so that a compromised
 *   WordPress account cannot change them from the dashboard:
 *     define( 'WR_ALLOWED_USERS', 'maria,giorgos' );          // user logins
 *     define( 'WR_ALLOWED_IPS', '203.0.113.10, 198.51.100.0/24' ); // IPs / CIDR
 *   Users or IPs outside the lists lose every wr_* capability.
 * - Mandatory two-factor authentication (TOTP) for every user of the area,
 *   verified per login session, bound to the browser's IP and user agent,
 *   with an idle timeout (WR_IDLE_MINUTES, default 30) and a maximum age
 *   (WR_2FA_SESSION_HOURS, default 8).
 * - Lockout after 5 wrong codes for 15 minutes, with an email alert.
 */
class WR_Access {

	const CAPS          = array( 'wr_view', 'wr_edit', 'wr_manage' );
	const MAX_FAILS     = 5;
	const LOCK_SECONDS  = 900;
	const META_SECRET   = 'wr_totp_secret';
	const META_PENDING  = 'wr_totp_pending';
	const META_LASTSTEP = 'wr_totp_last_step';
	const META_RECOVERY = 'wr_totp_recovery';
	const META_SESSIONS = 'wr_2fa_sessions';
	const META_FAILS    = 'wr_2fa_fails';
	const PAGE          = 'wr-2fa';

	public static function init(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 10, 4 );
		add_action( 'admin_init', array( __CLASS__, 'gate' ), 1 );
		// Screens are refused by WordPress itself (menu.php) before admin_init runs.
		add_action( 'admin_page_access_denied', array( __CLASS__, 'log_denied' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_wr_2fa_verify', array( __CLASS__, 'action_verify' ) );
		add_action( 'admin_post_wr_2fa_enroll', array( __CLASS__, 'action_enroll' ) );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Allowlists                                                          */
	/* ------------------------------------------------------------------ */

	public static function user_allowed( WP_User $user ): bool {
		if ( ! defined( 'WR_ALLOWED_USERS' ) || '' === trim( (string) WR_ALLOWED_USERS ) ) {
			return true;
		}
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) WR_ALLOWED_USERS ) ) ) );
		return in_array( strtolower( $user->user_login ), $list, true );
	}

	public static function ip_allowed( ?string $ip = null ): bool {
		if ( ! defined( 'WR_ALLOWED_IPS' ) || '' === trim( (string) WR_ALLOWED_IPS ) ) {
			return true;
		}
		$ip = $ip ?? WR_Util::client_ip();
		if ( '' === $ip ) {
			return false;
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) WR_ALLOWED_IPS ) ) ) as $rule ) {
			if ( self::ip_in( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ip_in( string $ip, string $rule ): bool {
		[ $net, $bits ] = array_pad( explode( '/', $rule, 2 ), 2, null );
		$ip_bin         = @inet_pton( $ip );
		$net_bin        = @inet_pton( (string) $net );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$max  = strlen( $ip_bin ) * 8;
		$bits = null === $bits ? $max : (int) $bits;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	/**
	 * Strips warranty capabilities from users or IPs outside the allowlists,
	 * which also hides the menu and blocks every action consistently.
	 */
	public static function filter_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( empty( array_intersect_key( $allcaps, array_flip( self::CAPS ) ) ) ) {
			return $allcaps;
		}
		if ( ! self::user_allowed( $user ) || ! self::ip_allowed() ) {
			foreach ( self::CAPS as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}
		return $allcaps;
	}

	/* ------------------------------------------------------------------ */
	/* Gate                                                                */
	/* ------------------------------------------------------------------ */

	/** Is this request for the warranty area (screens, admin-post actions, AJAX)? */
	public static function is_protected_request(): bool {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( str_starts_with( $page, 'wr-' ) && self::PAGE !== $page ) {
			return true;
		}
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			return str_starts_with( $action, 'wr_' ) && ! str_starts_with( $action, 'wr_2fa_' );
		}
		return false;
	}

	public static function gate(): void {
		if ( ! self::is_protected_request() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! current_user_can( 'wr_view' ) ) {
			self::log_denied();
			wp_die( 'Η πρόσβαση στο σύστημα εγγυήσεων δεν επιτρέπεται από αυτόν τον χρήστη ή αυτή τη διεύθυνση IP.', 'Απαγορεύεται η πρόσβαση', array( 'response' => 403 ) );
		}
		if ( ! WR_Crypto::ready() ) {
			self::die_missing_key();
		}
		if ( self::session_verified() ) {
			self::touch_session();
			return;
		}
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα και επιβεβαιώστε ξανά με τον κωδικό 2FA.' ), 401 );
		}
		$back = 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'admin.php' === $GLOBALS['pagenow']
			? ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) )
			: admin_url( 'admin.php?page=wr-dashboard' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'redirect_to' => rawurlencode( $back ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Records attempts by users who hold warranty capabilities by role but are
	 * outside WR_ALLOWED_USERS / WR_ALLOWED_IPS.
	 */
	public static function log_denied(): void {
		if ( ! self::is_protected_request() || ! is_user_logged_in() ) {
			return;
		}
		$raw = wp_get_current_user();
		if ( array_intersect( self::CAPS, array_keys( array_filter( $raw->allcaps ) ) ) ) {
			WR_Audit::log(
				'access_denied',
				'user',
				$raw->ID,
				array(
					'reason' => self::user_allowed( $raw ) ? 'ip' : 'user',
					'page'   => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ),
				)
			);
		}
	}

	private static function die_missing_key(): void {
		$msg = '<h1>Απαιτείται κλειδί κρυπτογράφησης</h1>'
			. '<p>Τα στοιχεία πελατών αποθηκεύονται κρυπτογραφημένα. Προσθέστε την παρακάτω γραμμή στο <code>wp-config.php</code>, πάνω από τη γραμμή «That\'s all, stop editing!»:</p>'
			. '<pre style="background:#f6f7f7;padding:12px;user-select:all">define( \'WR_ENCRYPTION_KEY\', \'' . esc_html( WR_Crypto::generate_key() ) . '\' );</pre>'
			. '<p><strong>Κρατήστε αντίγραφο του κλειδιού σε ασφαλές μέρος, χωριστά από τα backup της βάσης.</strong> Χωρίς αυτό τα στοιχεία των πελατών δεν μπορούν να ανακτηθούν. Μην αλλάξετε ποτέ το κλειδί αφού αρχίσουν οι καταχωρήσεις.</p>'
			. '<p>Μέχρι να οριστεί το κλειδί, η φόρμα ενεργοποίησης εγγύησης στο site δεν δέχεται καταχωρήσεις.</p>';
		wp_die( $msg, 'Απαιτείται κλειδί κρυπτογράφησης', array( 'response' => 503 ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* Sessions                                                            */
	/* ------------------------------------------------------------------ */

	private static function session_key(): string {
		$token = wp_get_session_token();
		return '' === $token ? '' : hash( 'sha256', $token );
	}

	private static function fingerprint(): string {
		return hash( 'sha256', WR_Util::client_ip() . '|' . WR_Util::user_agent() );
	}

	private static function max_age(): int {
		return ( defined( 'WR_2FA_SESSION_HOURS' ) ? max( 1, (int) WR_2FA_SESSION_HOURS ) : 8 ) * HOUR_IN_SECONDS;
	}

	private static function idle(): int {
		return ( defined( 'WR_IDLE_MINUTES' ) ? max( 5, (int) WR_IDLE_MINUTES ) : 30 ) * MINUTE_IN_SECONDS;
	}

	private static function sessions( int $user_id ): array {
		$s   = get_user_meta( $user_id, self::META_SESSIONS, true );
		$s   = is_array( $s ) ? $s : array();
		$now = time();
		return array_filter( $s, fn( $x ) => $x['at'] > $now - self::max_age() && $x['seen'] > $now - self::idle() );
	}

	public static function session_verified(): bool {
		$key = self::session_key();
		if ( '' === $key || ! self::has_2fa( get_current_user_id() ) ) {
			return false;
		}
		$s = self::sessions( get_current_user_id() )[ $key ] ?? null;
		return $s && hash_equals( $s['fp'], self::fingerprint() );
	}

	private static function touch_session(): void {
		$uid = get_current_user_id();
		$all = self::sessions( $uid );
		$key = self::session_key();
		if ( isset( $all[ $key ] ) && time() - $all[ $key ]['seen'] > 60 ) {
			$all[ $key ]['seen'] = time();
			update_user_meta( $uid, self::META_SESSIONS, $all );
		}
	}

	private static function mark_verified( int $uid ): void {
		$all                         = self::sessions( $uid );
		$all[ self::session_key() ] = array(
			'at'   => time(),
			'seen' => time(),
			'fp'   => self::fingerprint(),
		);
		update_user_meta( $uid, self::META_SESSIONS, $all );
	}

	public static function on_logout( int $user_id = 0 ): void {
		$uid = $user_id ?: get_current_user_id();
		$key = self::session_key();
		if ( $uid && $key ) {
			$all = self::sessions( $uid );
			unset( $all[ $key ] );
			update_user_meta( $uid, self::META_SESSIONS, $all );
		}
	}

	/* ------------------------------------------------------------------ */
	/* TOTP                                                                */
	/* ------------------------------------------------------------------ */

	public static function has_2fa( int $uid ): bool {
		return '' !== (string) get_user_meta( $uid, self::META_SECRET, true );
	}

	private static function secret( int $uid ): string {
		return WR_Crypto::decrypt( (string) get_user_meta( $uid, self::META_SECRET, true ) );
	}

	private static function locked_until( int $uid ): int {
		$f = get_user_meta( $uid, self::META_FAILS, true );
		return is_array( $f ) && ( $f['until'] ?? 0 ) > time() ? (int) $f['until'] : 0;
	}

	private static function register_failure( int $uid, string $context ): void {
		$f = get_user_meta( $uid, self::META_FAILS, true );
		$f = is_array( $f ) ? $f : array( 'count' => 0, 'until' => 0 );
		if ( $f['until'] > time() ) {
			return;
		}
		if ( $f['until'] ) { // A previous lock has expired: start counting again.
			$f = array( 'count' => 0, 'until' => 0 );
		}
		++$f['count'];
		WR_Audit::log( 'twofa_failed', 'user', $uid, array( 'context' => $context ) );
		if ( $f['count'] >= self::MAX_FAILS ) {
			$f = array( 'count' => 0, 'until' => time() + self::LOCK_SECONDS );
			WR_Audit::log( 'twofa_lockout', 'user', $uid );
			$user = get_userdata( $uid );
			$to   = (string) WR_Settings::get( 'admin_email' );
			if ( $to && $user ) {
				wp_mail(
					$to,
					'[Εγγυήσεις] Κλείδωμα λογαριασμού μετά από λάθος κωδικούς 2FA',
					sprintf( "Ο χρήστης %s έδωσε %d λάθος κωδικούς 2FA και κλειδώθηκε για 15 λεπτά.\nIP: %s\nΏρα: %s\n\nΑν δεν ήταν ο ίδιος, αλλάξτε άμεσα τον κωδικό πρόσβασής του στο WordPress.", $user->user_login, self::MAX_FAILS, WR_Util::client_ip(), WR_Util::now() )
				);
			}
		}
		update_user_meta( $uid, self::META_FAILS, $f );
	}

	/**
	 * Checks a TOTP or recovery code for the current user. Used both at login
	 * to the area and as step-up confirmation for sensitive actions (exports).
	 */
	public static function check_code( string $code, string $context ): bool {
		$uid = get_current_user_id();
		if ( ! $uid || self::locked_until( $uid ) ) {
			return false;
		}
		$code = trim( $code );
		$step = WR_TOTP::verify( self::secret( $uid ), $code, (int) get_user_meta( $uid, self::META_LASTSTEP, true ) );
		if ( null !== $step ) {
			update_user_meta( $uid, self::META_LASTSTEP, $step );
			delete_user_meta( $uid, self::META_FAILS );
			return true;
		}
		// Recovery codes: one-time, 10 characters.
		$norm  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
		$codes = (array) get_user_meta( $uid, self::META_RECOVERY, true );
		if ( strlen( $norm ) === 10 ) {
			foreach ( $codes as $i => $hash ) {
				if ( wp_check_password( $norm, $hash ) ) {
					unset( $codes[ $i ] );
					update_user_meta( $uid, self::META_RECOVERY, array_values( $codes ) );
					delete_user_meta( $uid, self::META_FAILS );
					WR_Audit::log( 'twofa_recovery_used', 'user', $uid, array( 'remaining' => count( $codes ) ) );
					return true;
				}
			}
		}
		self::register_failure( $uid, $context );
		return false;
	}

	public static function reset_user( int $uid ): void {
		foreach ( array( self::META_SECRET, self::META_PENDING, self::META_LASTSTEP, self::META_RECOVERY, self::META_SESSIONS, self::META_FAILS ) as $k ) {
			delete_user_meta( $uid, $k );
		}
		WR_Audit::log( 'twofa_reset', 'user', $uid );
	}

	/* ------------------------------------------------------------------ */
	/* 2FA screen                                                          */
	/* ------------------------------------------------------------------ */

	public static function register_page(): void {
		add_submenu_page( 'options.php', 'Επιβεβαίωση ταυτότητας', 'Επιβεβαίωση ταυτότητας', 'wr_view', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	private static function redirect_target(): string {
		$to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_REQUEST['redirect_to'] ) ) ) : '';
		return wp_validate_redirect( $to, admin_url( 'admin.php?page=wr-dashboard' ) );
	}

	public static function render_page(): void {
		if ( ! WR_Crypto::ready() ) {
			self::die_missing_key();
		}
		$uid   = get_current_user_id();
		$user  = wp_get_current_user();
		$error = isset( $_GET['wr_err'] ) ? sanitize_key( wp_unslash( $_GET['wr_err'] ) ) : '';
		$msgs  = array(
			'code'   => 'Λάθος κωδικός. Δοκιμάστε ξανά.',
			'locked' => 'Πολλές λανθασμένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.',
		);
		echo '<div class="wrap wr-wrap wr-2fa"><h1>Επιβεβαίωση ταυτότητας</h1>';
		if ( isset( $msgs[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msgs[ $error ] ) . '</p></div>';
		}

		// Show recovery codes once, right after enrollment.
		$fresh = json_decode( WR_Crypto::decrypt( (string) get_transient( 'wr_recovery_show_' . $uid ) ), true );
		if ( $fresh ) {
			delete_transient( 'wr_recovery_show_' . $uid );
			echo '<div class="wr-panel"><h2>Κωδικοί ανάκτησης</h2><p>Η επαλήθευση δύο βημάτων ενεργοποιήθηκε. Αν χάσετε το κινητό σας, μπορείτε να μπείτε με έναν από αυτούς τους κωδικούς. Ο καθένας χρησιμοποιείται μία φορά.</p>';
			echo '<p><strong>Αποθηκεύστε τους τώρα σε ασφαλές μέρος (π.χ. password manager). Δεν θα εμφανιστούν ξανά.</strong></p><pre class="wr-recovery">' . esc_html( implode( "\n", $fresh ) ) . '</pre>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::redirect_target() ) . '">Τους αποθήκευσα, συνέχεια</a></p></div></div>';
			return;
		}

		if ( ! self::has_2fa( $uid ) ) {
			$pending = WR_Crypto::decrypt( (string) get_user_meta( $uid, self::META_PENDING, true ) );
			if ( '' === $pending ) {
				$pending = WR_TOTP::new_secret();
				update_user_meta( $uid, self::META_PENDING, WR_Crypto::encrypt( $pending ) );
			}
			$uri = WR_TOTP::uri( $pending, $user->user_login, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' Εγγυήσεις' );
			wp_enqueue_script( 'wr-qrcode', WR_URL . 'assets/vendor/qrcode.js', array(), WR_VERSION, true );
			echo '<div class="wr-panel"><h2>Ενεργοποίηση επαλήθευσης δύο βημάτων</h2>';
			echo '<p>Η πρόσβαση στα στοιχεία πελατών απαιτεί επαλήθευση δύο βημάτων.</p><ol>';
			echo '<li>Εγκαταστήστε στο κινητό σας μια εφαρμογή επαλήθευσης: Google Authenticator, Microsoft Authenticator ή αντίστοιχη.</li>';
			echo '<li>Σκανάρετε τον κωδικό QR:</li></ol>';
			echo '<div id="wr-qr" data-uri="' . esc_attr( $uri ) . '"></div>';
			echo '<p>ή πληκτρολογήστε το κλειδί: <code class="wr-secret">' . esc_html( trim( chunk_split( $pending, 4, ' ' ) ) ) . '</code></p>';
			echo '<ol start="3"><li>Γράψτε τον 6ψήφιο κωδικό που εμφανίζει η εφαρμογή:</li></ol>';
			self::code_form( 'wr_2fa_enroll', 'Ενεργοποίηση' );
			echo '</div></div>';
			return;
		}

		echo '<div class="wr-panel"><p>Για να συνεχίσετε στο σύστημα εγγυήσεων, γράψτε τον 6ψήφιο κωδικό από την εφαρμογή επαλήθευσης ή έναν κωδικό ανάκτησης.</p>';
		self::code_form( 'wr_2fa_verify', 'Επιβεβαίωση' );
		echo '</div></div>';
	}

	private static function code_form( string $action, string $button ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( rawurlencode( self::redirect_target() ) ) . '">';
		wp_nonce_field( $action );
		echo '<p><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus class="wr-otp" aria-label="Κωδικός"> ';
		echo '<button class="button button-primary">' . esc_html( $button ) . '</button></p></form>';
	}

	private static function back_to_page( string $err = '' ): void {
		$args = array(
			'page'        => self::PAGE,
			'redirect_to' => rawurlencode( self::redirect_target() ),
		);
		if ( $err ) {
			$args['wr_err'] = $err;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function action_verify(): void {
		check_admin_referer( 'wr_2fa_verify' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wr_view' ) || ! WR_Crypto::ready() || ! self::has_2fa( $uid ) ) {
			wp_die( 'Δεν επιτρέπεται.', 403 );
		}
		if ( self::locked_until( $uid ) ) {
			self::back_to_page( 'locked' );
		}
		if ( ! self::check_code( (string) wp_unslash( $_POST['code'] ?? '' ), 'login' ) ) {
			self::back_to_page( self::locked_until( $uid ) ? 'locked' : 'code' );
		}
		self::mark_verified( $uid );
		WR_Audit::log( 'twofa_success', 'user', $uid );
		wp_safe_redirect( self::redirect_target() );
		exit;
	}

	public static function action_enroll(): void {
		check_admin_referer( 'wr_2fa_enroll' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wr_view' ) || ! WR_Crypto::ready() || self::has_2fa( $uid ) ) {
			wp_die( 'Δεν επιτρέπεται.', 403 );
		}
		if ( self::locked_until( $uid ) ) {
			self::back_to_page( 'locked' );
		}
		$pending = WR_Crypto::decrypt( (string) get_user_meta( $uid, self::META_PENDING, true ) );
		$step    = '' === $pending ? null : WR_TOTP::verify( $pending, (string) wp_unslash( $_POST['code'] ?? '' ) );
		if ( null === $step ) {
			self::register_failure( $uid, 'enroll' );
			self::back_to_page( self::locked_until( $uid ) ? 'locked' : 'code' );
		}
		update_user_meta( $uid, self::META_SECRET, WR_Crypto::encrypt( $pending ) );
		update_user_meta( $uid, self::META_LASTSTEP, $step );
		delete_user_meta( $uid, self::META_PENDING );

		$plain  = array();
		$hashed = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$c        = strtoupper( substr( WR_TOTP::new_secret(), 0, 10 ) );
			$plain[]  = substr( $c, 0, 5 ) . '-' . substr( $c, 5 );
			$hashed[] = wp_hash_password( $c );
		}
		update_user_meta( $uid, self::META_RECOVERY, $hashed );
		set_transient( 'wr_recovery_show_' . $uid, WR_Crypto::encrypt( wp_json_encode( $plain ) ), 10 * MINUTE_IN_SECONDS );
		self::mark_verified( $uid );
		WR_Audit::log( 'twofa_enabled', 'user', $uid );
		self::back_to_page();
	}
}
