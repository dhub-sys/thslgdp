<?php
defined( 'ABSPATH' ) || exit;

/**
 * Protection of the WordPress login itself:
 *
 * - Two-factor authentication at wp-login.php for administrators and the other
 *   roles in WR_2FA_ROLES (default: administrator, editor, shop_manager,
 *   wr_support) and for anyone with warranty access. The same authenticator
 *   entry is used for the warranty area, so one code covers both.
 * - Users of those roles who have not enrolled yet are sent to the enrollment
 *   screen right after login and can do nothing else in wp-admin until done.
 * - XML-RPC and application-password logins are refused for those users, so
 *   2FA cannot be bypassed through the API.
 * - Brute-force protection: after 10 failed logins from an IP within 15
 *   minutes, that IP cannot log in for 30 minutes.
 * - Login errors no longer reveal whether a username exists.
 *
 * Emergency switch (e.g. lost phone and recovery codes for the only admin):
 *   define( 'WR_LOGIN_2FA', false ); in wp-config.php.
 */
class WR_Login {

	const ACTION        = 'wr_2fa';
	const PENDING_TTL   = 300;
	const IP_MAX_FAILS  = 10;
	const IP_WINDOW     = 900;
	const IP_BLOCK      = 1800;
	const DEFAULT_ROLES = 'administrator,editor,shop_manager,wr_support';

	public static function init(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 100, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'second_step' ) );
		add_filter( 'login_errors', array( __CLASS__, 'generic_errors' ) );
		add_filter( 'wp_login_errors', array( __CLASS__, 'expired_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'enforce_enrollment' ), 0 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'app_passwords' ), 10, 2 );
	}

	public static function enabled(): bool {
		return ! defined( 'WR_LOGIN_2FA' ) || (bool) WR_LOGIN_2FA;
	}

	public static function requires_2fa( $user ): bool {
		if ( ! self::enabled() || ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		$roles = array_filter( array_map( 'trim', explode( ',', defined( 'WR_2FA_ROLES' ) ? (string) WR_2FA_ROLES : self::DEFAULT_ROLES ) ) );
		if ( array_intersect( $roles, (array) $user->roles ) ) {
			return true;
		}
		return (bool) array_intersect( WR_Access::CAPS, array_keys( array_filter( $user->allcaps ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* First step: password                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Runs after WordPress has checked the password. A correct password for a
	 * user with 2FA does not log in: it leads to the code screen.
	 *
	 * @param WP_User|WP_Error|null $user
	 */
	public static function authenticate( $user, $username, $password ) {
		if ( self::ip_blocked() && ( '' !== (string) $username || '' !== (string) $password ) ) {
			return new WP_Error( 'wr_ip_blocked', 'Πάρα πολλές αποτυχημένες προσπάθειες σύνδεσης. Δοκιμάστε ξανά σε 30 λεπτά.' );
		}
		if ( ! $user instanceof WP_User || ! self::requires_2fa( $user ) || ! WR_Crypto::ready() ) {
			return $user;
		}
		$api = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_is_json_request();
		if ( $api ) {
			return new WP_Error( 'wr_2fa_required', 'Ο λογαριασμός απαιτεί επαλήθευση δύο βημάτων. Συνδεθείτε από τη σελίδα σύνδεσης.' );
		}
		if ( ! WR_Access::has_2fa( $user->ID ) ) {
			return $user; // Enrollment is enforced right after login (enforce_enrollment).
		}

		$token = wp_generate_password( 40, false );
		set_transient(
			self::pending_key( $token ),
			array(
				'uid'      => $user->ID,
				'remember' => ! empty( $_POST['rememberme'] ),
				'redirect' => isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '',
				'fp'       => hash( 'sha256', WR_Util::client_ip() . '|' . WR_Util::user_agent() ),
			),
			self::PENDING_TTL
		);
		wp_safe_redirect( add_query_arg( array( 'action' => self::ACTION, 'wr_t' => $token ), wp_login_url() ) );
		exit;
	}

	private static function pending_key( string $token ): string {
		return 'wr_lp_' . hash( 'sha256', $token );
	}

	/* ------------------------------------------------------------------ */
	/* Second step: code                                                   */
	/* ------------------------------------------------------------------ */

	public static function second_step(): void {
		nocache_headers();
		$token   = sanitize_text_field( wp_unslash( $_REQUEST['wr_t'] ?? '' ) );
		$pending = '' === $token ? false : get_transient( self::pending_key( $token ) );
		$fp      = hash( 'sha256', WR_Util::client_ip() . '|' . WR_Util::user_agent() );
		if ( ! is_array( $pending ) || ! hash_equals( $pending['fp'], $fp ) ) {
			wp_safe_redirect( add_query_arg( 'wr_expired', 1, wp_login_url() ) );
			exit;
		}
		$uid   = (int) $pending['uid'];
		$error = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( WR_Access::locked_until( $uid ) || self::ip_blocked() ) {
				$error = 'Πολλές λανθασμένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.';
			} elseif ( WR_Access::check_code( (string) wp_unslash( $_POST['wr_code'] ?? '' ), 'wp-login', $uid ) ) {
				delete_transient( self::pending_key( $token ) );
				self::complete_login( $uid, (bool) $pending['remember'], (string) $pending['redirect'] );
			} else {
				self::count_ip_failure();
				$error = WR_Access::locked_until( $uid ) ? 'Πολλές λανθασμένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.' : 'Λάθος κωδικός. Δοκιμάστε ξανά.';
			}
		}

		$errors = new WP_Error();
		if ( $error ) {
			$errors->add( 'wr_code', $error );
		}
		login_header( 'Επαλήθευση δύο βημάτων', '<p class="message">Γράψτε τον 6ψήφιο κωδικό από την εφαρμογή επαλήθευσης στο κινητό σας.</p>', $errors );
		?>
		<form name="wr2faform" id="loginform" action="<?php echo esc_url( add_query_arg( 'action', self::ACTION, wp_login_url() ) ); ?>" method="post" autocomplete="off">
			<input type="hidden" name="wr_t" value="<?php echo esc_attr( $token ); ?>">
			<p>
				<label for="wr_code">Κωδικός επαλήθευσης</label>
				<input type="text" name="wr_code" id="wr_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus style="letter-spacing:.2em;text-align:center;font-size:24px">
			</p>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="Σύνδεση">
			</p>
		</form>
		<p id="nav">Χάσατε το κινητό σας; Γράψτε έναν από τους κωδικούς ανάκτησης στη θέση του κωδικού.</p>
		<?php
		login_footer( 'wr_code' );
		exit;
	}

	private static function complete_login( int $uid, bool $remember, string $redirect ): void {
		$user       = get_userdata( $uid );
		$expiration = time() + (int) apply_filters( 'auth_cookie_expiration', ( $remember ? 14 : 2 ) * DAY_IN_SECONDS, $uid, $remember );
		$session    = WP_Session_Tokens::get_instance( $uid )->create( $expiration );
		wp_set_auth_cookie( $uid, $remember, '', $session );
		WR_Access::mark_verified( $uid, $session ); // No second prompt when entering the warranty area.
		WR_Audit::log( 'login_2fa_success', 'user', $uid );
		do_action( 'wp_login', $user->user_login, $user );

		$requested = $redirect;
		$redirect  = $redirect ?: admin_url();
		$redirect  = apply_filters( 'login_redirect', $redirect, $requested, $user );
		wp_safe_redirect( wp_validate_redirect( $redirect, admin_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Enrollment enforcement                                              */
	/* ------------------------------------------------------------------ */

	public static function enforce_enrollment(): void {
		if ( ! is_user_logged_in() || ! WR_Crypto::ready() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! self::requires_2fa( $user ) || WR_Access::has_2fa( $user->ID ) ) {
			return;
		}
		global $pagenow;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( WR_Access::PAGE === $page || ( 'admin-post.php' === $pagenow && 'wr_2fa_enroll' === $action ) || ( 'admin-ajax.php' === $pagenow && 'heartbeat' === $action ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => 'Απαιτείται ενεργοποίηση επαλήθευσης δύο βημάτων.' ), 403 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . WR_Access::PAGE ) );
		exit;
	}

	/**
	 * Application passwords authenticate API requests without 2FA, so they are
	 * not available to users who must use 2FA.
	 */
	public static function app_passwords( bool $available, $user ): bool {
		return self::requires_2fa( $user ) ? false : $available;
	}

	/* ------------------------------------------------------------------ */
	/* Brute-force protection                                              */
	/* ------------------------------------------------------------------ */

	private static function ip_key(): string {
		return 'wr_lf_' . md5( WR_Util::client_ip() );
	}

	public static function ip_blocked(): bool {
		$d = get_transient( self::ip_key() );
		return is_array( $d ) && ( $d['blocked'] ?? 0 ) > time();
	}

	public static function login_failed( $username = '' ): void {
		self::count_ip_failure( (string) $username );
	}

	private static function count_ip_failure( string $username = '' ): void {
		if ( '' === WR_Util::client_ip() ) {
			return;
		}
		$d = get_transient( self::ip_key() );
		if ( ! is_array( $d ) || $d['start'] < time() - self::IP_WINDOW ) {
			$d = array( 'count' => 0, 'start' => time(), 'blocked' => 0 );
		}
		if ( $d['blocked'] > time() ) {
			return;
		}
		++$d['count'];
		if ( $d['count'] >= self::IP_MAX_FAILS ) {
			$d['blocked'] = time() + self::IP_BLOCK;
			WR_Audit::log( 'login_ip_blocked', 'ip', null, array( 'ip' => WR_Util::client_ip(), 'last_username' => sanitize_user( $username ) ) );
		}
		set_transient( self::ip_key(), $d, self::IP_BLOCK + self::IP_WINDOW );
	}

	public static function expired_notice( WP_Error $errors ): WP_Error {
		if ( isset( $_GET['wr_expired'] ) ) {
			$errors->add( 'wr_expired', 'Η επαλήθευση έληξε ή άνοιξε από άλλη συσκευή. Συνδεθείτε ξανά.', 'message' );
		}
		return $errors;
	}

	/** Same message for unknown user and wrong password: no username enumeration. */
	public static function generic_errors( $message ) {
		if ( ! is_string( $message ) || '' === $message ) {
			return $message;
		}
		if ( str_contains( $message, 'Πάρα πολλές' ) || str_contains( $message, 'επαλήθευση δύο βημάτων' ) ) {
			return $message;
		}
		if ( isset( $_POST['log'] ) ) {
			return '<strong>Σφάλμα:</strong> Λάθος όνομα χρήστη ή κωδικός πρόσβασης.';
		}
		return $message;
	}
}
