<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public shortcodes:
 *   [warranty_activate] serial -> customer details + terms -> review -> done
 *   [warranty_check]    look up a warranty by code + serial
 *
 * Form posts are handled on template_redirect so a successful activation can
 * redirect (no duplicate submission on refresh).
 */
class WR_Frontend {

	const GENERIC_SERIAL_ERROR = 'Ο σειριακός αριθμός δεν μπορεί να ενεργοποιηθεί. Ελέγξτε ότι τον πληκτρολογήσατε σωστά ή επικοινωνήστε μαζί μας.';
	const RATE_LIMIT_ERROR     = 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά σε λίγα λεπτά.';
	const CAPTCHA_ERROR        = 'Η επαλήθευση ασφαλείας απέτυχε. Δοκιμάστε ξανά.';
	const EXPIRED_ERROR        = 'Η φόρμα έληξε. Ξεκινήστε ξανά από την αρχή.';

	/** Render state per form, filled by the POST handler. */
	private static array $state = array(
		'activate' => array(),
		'check'    => array(),
	);

	public static function init(): void {
		add_shortcode( 'warranty_activate', array( __CLASS__, 'shortcode_activate' ) );
		add_shortcode( 'warranty_check', array( __CLASS__, 'shortcode_check' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_style( 'wr-frontend', WR_URL . 'assets/frontend.css', array(), WR_VERSION );
	}

	public static function handle_post(): void {
		if ( isset( $_GET['wr_done'] ) ) {
			self::no_cache();
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wr_form'] ) ) {
			return;
		}
		self::no_cache();
		$form = sanitize_key( wp_unslash( $_POST['wr_form'] ) );
		if ( 'activate' === $form ) {
			self::handle_activate( sanitize_key( wp_unslash( $_POST['wr_step'] ?? '' ) ) );
		} elseif ( 'check' === $form ) {
			self::handle_check();
		}
	}

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	private static function post( string $key ): string {
		return trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Activation                                                          */
	/* ------------------------------------------------------------------ */

	private static function handle_activate( string $step ): void {
		$st = &self::$state['activate'];

		if ( 'serial' === $step ) {
			$st = array(
				'step'   => 'serial',
				'serial' => self::post( 'wr_serial' ),
			);
			if ( '' !== self::post( 'wr_website' ) ) { // Honeypot.
				$st['error'] = self::GENERIC_SERIAL_ERROR;
				return;
			}
			if ( ! WR_Security::hit( 'activate' ) ) {
				$st['error'] = self::RATE_LIMIT_ERROR;
				return;
			}
			if ( ! WR_Security::verify_captcha() ) {
				$st['error'] = self::CAPTCHA_ERROR;
				return;
			}
			$serial = WR_Warranties::find_available_serial( $st['serial'] );
			if ( ! $serial ) {
				$st['error'] = self::GENERIC_SERIAL_ERROR;
				return;
			}
			$st = array(
				'step'  => 'details',
				'token' => WR_Security::sign( array( 'sid' => (int) $serial->id ) ),
				'data'  => array(),
			);
			return;
		}

		$token = WR_Security::verify( self::post( 'wr_token' ) );
		if ( ! $token || empty( $token['sid'] ) ) {
			$st = array(
				'step'  => 'serial',
				'error' => self::EXPIRED_ERROR,
			);
			return;
		}
		$sid = (int) $token['sid'];

		if ( 'details' === $step ) {
			$data   = array(
				'full_name' => self::post( 'wr_full_name' ),
				'email'     => sanitize_email( self::post( 'wr_email' ) ),
				'phone_raw' => self::post( 'wr_phone' ),
			);
			$errors = array();
			if ( mb_strlen( $data['full_name'] ) < 3 || mb_strlen( $data['full_name'] ) > 150 || ! preg_match( '/\s/u', $data['full_name'] ) ) {
				$errors['full_name'] = 'Συμπληρώστε ονοματεπώνυμο.';
			}
			if ( ! is_email( $data['email'] ) ) {
				$errors['email'] = 'Συμπληρώστε έγκυρο email.';
			}
			$data['phone'] = WR_Util::normalize_phone( $data['phone_raw'] );
			if ( '' === $data['phone'] || ! WR_Util::is_greek_mobile_or_foreign( $data['phone'] ) ) {
				$errors['phone'] = 'Συμπληρώστε έγκυρο αριθμό κινητού τηλεφώνου.';
			}
			$terms = WR_Terms::current();
			if ( empty( $_POST['wr_terms'] ) ) {
				$errors['terms'] = 'Για να συνεχίσετε πρέπει να αποδεχθείτε τους όρους εγγύησης.';
			} elseif ( ! $terms || (int) self::post( 'wr_terms_id' ) !== (int) $terms->id ) {
				$errors['terms'] = 'Οι όροι εγγύησης ενημερώθηκαν. Διαβάστε τους και αποδεχθείτε τους ξανά.';
			}
			if ( $errors ) {
				$st = array(
					'step'   => 'details',
					'token'  => self::post( 'wr_token' ),
					'data'   => $data,
					'errors' => $errors,
				);
				return;
			}
			$st = array(
				'step'  => 'review',
				'token' => WR_Security::sign(
					array(
						'sid'   => $sid,
						'name'  => $data['full_name'],
						'email' => $data['email'],
						'phone' => $data['phone'],
						'tid'   => (int) $terms->id,
						'once'  => wp_generate_password( 12, false ),
					)
				),
				'data'  => $data,
			);
			return;
		}

		if ( 'edit' === $step ) {
			$st = array(
				'step'  => 'details',
				'token' => WR_Security::sign( array( 'sid' => $sid ) ),
				'data'  => array(
					'full_name' => (string) ( $token['name'] ?? '' ),
					'email'     => (string) ( $token['email'] ?? '' ),
					'phone_raw' => (string) ( $token['phone'] ?? '' ),
				),
			);
			return;
		}

		if ( 'confirm' === $step && ! empty( $token['once'] ) ) {
			// A double click or refresh of the same review form shows the same result.
			$done_key = 'wr_done_' . md5( $token['once'] );
			$prev     = get_transient( $done_key );
			if ( $prev ) {
				self::redirect_done( (int) $prev );
			}

			$terms = WR_Terms::current();
			if ( ! $terms || (int) $terms->id !== (int) $token['tid'] ) {
				$st = array(
					'step'   => 'details',
					'token'  => WR_Security::sign( array( 'sid' => $sid ) ),
					'data'   => array(
						'full_name' => $token['name'],
						'email'     => $token['email'],
						'phone_raw' => $token['phone'],
					),
					'errors' => array( 'terms' => 'Οι όροι εγγύησης ενημερώθηκαν. Διαβάστε τους και αποδεχθείτε τους ξανά.' ),
				);
				return;
			}
			$w = WR_Warranties::activate(
				$sid,
				array(
					'full_name' => $token['name'],
					'email'     => $token['email'],
					'phone'     => $token['phone'],
				),
				array(
					'terms'      => $terms,
					'ip'         => WR_Util::client_ip(),
					'user_agent' => WR_Util::user_agent(),
				)
			);
			if ( is_wp_error( $w ) ) {
				$st = array(
					'step'  => 'serial',
					'error' => self::GENERIC_SERIAL_ERROR,
				);
				return;
			}
			set_transient( $done_key, (int) $w->id, HOUR_IN_SECONDS );
			self::redirect_done( (int) $w->id );
		}

		$st = array( 'step' => 'serial' );
	}

	private static function redirect_done( int $warranty_id ): void {
		$url = add_query_arg( 'wr_done', rawurlencode( WR_Security::sign( array( 'wid' => $warranty_id ), HOUR_IN_SECONDS ) ), remove_query_arg( 'wr_done' ) );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	public static function shortcode_activate(): string {
		wp_enqueue_style( 'wr-frontend' );
		$st = self::$state['activate'];

		if ( empty( $st ) && isset( $_GET['wr_done'] ) ) {
			$data = WR_Security::verify( sanitize_text_field( wp_unslash( $_GET['wr_done'] ) ) );
			$w    = $data ? WR_Warranties::get( (int) $data['wid'] ) : null;
			if ( $w ) {
				return self::render_done( $w );
			}
		}

		$step = $st['step'] ?? 'serial';
		if ( 'details' === $step ) {
			return self::render_details( $st );
		}
		if ( 'review' === $step ) {
			return self::render_review( $st );
		}
		return self::render_serial( $st );
	}

	private static function form_open( string $step, string $token = '' ): string {
		$html  = '<form method="post" class="wr-form" novalidate>';
		$html .= '<input type="hidden" name="wr_form" value="activate">';
		$html .= '<input type="hidden" name="wr_step" value="' . esc_attr( $step ) . '">';
		if ( $token ) {
			$html .= '<input type="hidden" name="wr_token" value="' . esc_attr( $token ) . '">';
		}
		return $html;
	}

	private static function steps( int $current ): string {
		$labels = array( 1 => 'Σειριακός αριθμός', 2 => 'Στοιχεία & όροι', 3 => 'Επιβεβαίωση' );
		$html   = '<ol class="wr-steps">';
		foreach ( $labels as $n => $label ) {
			$cls   = $n === $current ? 'is-current' : ( $n < $current ? 'is-done' : '' );
			$html .= '<li class="' . $cls . '"><span>' . $n . '</span> ' . esc_html( $label ) . '</li>';
		}
		return $html . '</ol>';
	}

	private static function error_box( ?string $msg ): string {
		return $msg ? '<div class="wr-alert wr-alert--error" role="alert">' . esc_html( $msg ) . '</div>' : '';
	}

	private static function render_serial( array $st ): string {
		$html  = '<div class="wr-box">' . self::steps( 1 );
		$html .= self::error_box( $st['error'] ?? null );
		$html .= self::form_open( 'serial' );
		$html .= '<p class="wr-field"><label for="wr_serial">Σειριακός αριθμός προϊόντος</label>';
		$html .= '<input type="text" id="wr_serial" name="wr_serial" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" value="' . esc_attr( $st['serial'] ?? '' ) . '">';
		$html .= '<small>Θα τον βρείτε στην ετικέτα του προϊόντος ή στη συσκευασία.</small></p>';
		$html .= '<p class="wr-hp" aria-hidden="true"><label>Website <input type="text" name="wr_website" tabindex="-1" autocomplete="off"></label></p>';
		$html .= WR_Security::captcha_html();
		$html .= '<p><button type="submit" class="wr-btn">Συνέχεια</button></p></form></div>';
		return $html;
	}

	private static function render_details( array $st ): string {
		$token  = WR_Security::verify( (string) $st['token'] );
		$serial = $token ? WR_Warranties::get_serial( (int) $token['sid'] ) : null;
		if ( ! $serial ) {
			return self::render_serial( array( 'error' => self::EXPIRED_ERROR ) );
		}
		$product = WR_Warranties::get_product( $serial->product_id ? (int) $serial->product_id : null );
		$terms   = WR_Terms::current();
		$d       = $st['data'] ?? array();
		$e       = $st['errors'] ?? array();

		$html  = '<div class="wr-box">' . self::steps( 2 );
		$html .= $e ? self::error_box( 'Ελέγξτε τα πεδία που επισημαίνονται.' ) : '';
		$html .= '<div class="wr-summary"><div><span>Σειριακός</span><strong>' . esc_html( $serial->serial ) . '</strong></div>';
		if ( $product ) {
			$html .= '<div><span>Προϊόν</span><strong>' . esc_html( $product->name ?: $product->code ) . '</strong></div>';
			$html .= '<div><span>Διάρκεια εγγύησης</span><strong>' . (int) $product->duration_months . ' μήνες</strong></div>';
		}
		$html .= '</div>';
		$html .= self::form_open( 'details', (string) $st['token'] );
		$html .= self::input( 'wr_full_name', 'Ονοματεπώνυμο', 'text', $d['full_name'] ?? '', $e['full_name'] ?? null, 'name' );
		$html .= self::input( 'wr_email', 'Email', 'email', $d['email'] ?? '', $e['email'] ?? null, 'email' );
		$html .= self::input( 'wr_phone', 'Κινητό τηλέφωνο', 'tel', $d['phone_raw'] ?? '', $e['phone'] ?? null, 'tel', 'π.χ. 69XXXXXXXX' );

		if ( $terms ) {
			$html .= '<div class="wr-terms" tabindex="0" aria-label="Όροι εγγύησης"><h3>Όροι εγγύησης <small>(έκδοση ' . esc_html( $terms->version ) . ')</small></h3>' . wp_kses_post( wpautop( $terms->content ) ) . '</div>';
			$html .= '<input type="hidden" name="wr_terms_id" value="' . (int) $terms->id . '">';
		}
		$html .= '<p class="wr-field wr-check' . ( isset( $e['terms'] ) ? ' has-error' : '' ) . '"><label><input type="checkbox" name="wr_terms" value="1"> Διάβασα και αποδέχομαι τους όρους εγγύησης.</label>';
		$html .= isset( $e['terms'] ) ? '<span class="wr-error">' . esc_html( $e['terms'] ) . '</span>' : '';
		$html .= '</p>';
		$html .= self::privacy_notice();
		$html .= '<p><button type="submit" class="wr-btn">Συνέχεια</button></p></form></div>';
		return $html;
	}

	private static function input( string $name, string $label, string $type, string $value, ?string $error, string $autocomplete, string $placeholder = '' ): string {
		$html  = '<p class="wr-field' . ( $error ? ' has-error' : '' ) . '"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" autocomplete="' . esc_attr( $autocomplete ) . '" required' . ( $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . ( $error ? ' aria-invalid="true"' : '' ) . '>';
		$html .= $error ? '<span class="wr-error">' . esc_html( $error ) . '</span>' : '';
		return $html . '</p>';
	}

	private static function privacy_notice(): string {
		$url  = (string) WR_Settings::get( 'privacy_page_url' );
		$url  = $url ?: (string) get_privacy_policy_url();
		$text = 'Τα στοιχεία σας χρησιμοποιούνται αποκλειστικά για την ενεργοποίηση και τη διαχείριση της εγγύησης και για ενημερώσεις σχετικά με αυτήν (π.χ. υπενθύμιση λήξης).';
		if ( $url ) {
			$text .= ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Πολιτική απορρήτου</a>';
		}
		return '<p class="wr-privacy">' . wp_kses( $text, array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</p>';
	}

	private static function render_review( array $st ): string {
		$token  = WR_Security::verify( (string) $st['token'] );
		$serial = $token ? WR_Warranties::get_serial( (int) $token['sid'] ) : null;
		if ( ! $serial ) {
			return self::render_serial( array( 'error' => self::EXPIRED_ERROR ) );
		}
		$product = WR_Warranties::get_product( $serial->product_id ? (int) $serial->product_id : null );

		$html  = '<div class="wr-box">' . self::steps( 3 );
		$html .= '<p class="wr-lead">Ελέγξτε προσεκτικά τα στοιχεία σας. Μετά την ολοκλήρωση δεν μπορούν να αλλάξουν από εσάς.</p>';
		$html .= '<dl class="wr-review">';
		$rows  = array(
			'Σειριακός αριθμός' => $serial->serial,
			'Προϊόν'            => $product ? ( $product->name ?: $product->code ) : '',
			'Ονοματεπώνυμο'     => $token['name'],
			'Email'             => $token['email'],
			'Κινητό'            => $token['phone'],
		);
		foreach ( $rows as $k => $v ) {
			if ( '' !== (string) $v ) {
				$html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
			}
		}
		$html .= '</dl><div class="wr-actions">';
		$html .= self::form_open( 'edit', (string) $st['token'] ) . '<button type="submit" class="wr-btn wr-btn--secondary">Διόρθωση</button></form>';
		$html .= self::form_open( 'confirm', (string) $st['token'] ) . '<button type="submit" class="wr-btn">Επιβεβαίωση & ενεργοποίηση</button></form>';
		return $html . '</div></div>';
	}

	private static function render_done( object $w ): string {
		$channels = array();
		if ( WR_Settings::get( 'activation_email' ) ) {
			$channels[] = 'email';
		}
		if ( WR_Settings::get( 'activation_sms' ) && WR_Settings::get( 'sms_enabled' ) ) {
			$channels[] = 'SMS';
		}
		$html  = '<div class="wr-box wr-done">';
		$html .= '<div class="wr-alert wr-alert--success" role="status">Η εγγύησή σας ενεργοποιήθηκε επιτυχώς.</div>';
		$html .= '<p>Κωδικός εγγύησης</p><p class="wr-code">' . esc_html( $w->code ) . '</p>';
		$html .= '<dl class="wr-review">';
		$html .= '<dt>Προϊόν</dt><dd>' . esc_html( WR_Warranties::product_label( $w ) ?: '—' ) . '</dd>';
		$html .= '<dt>Σειριακός αριθμός</dt><dd>' . esc_html( $w->serial ) . '</dd>';
		$html .= '<dt>Ισχύει από</dt><dd>' . esc_html( WR_Util::format_date( $w->starts_on ) ) . '</dd>';
		$html .= '<dt>Λήγει</dt><dd>' . esc_html( WR_Util::format_date( $w->expires_on ) ) . '</dd>';
		$html .= '</dl>';
		if ( $channels ) {
			$html .= '<p>Σας στείλαμε τον κωδικό με ' . esc_html( implode( ' και ', $channels ) ) . '. Φυλάξτε τον για κάθε μελλοντική επικοινωνία μαζί μας.</p>';
		}
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Warranty check                                                      */
	/* ------------------------------------------------------------------ */

	private static function handle_check(): void {
		$st = array(
			'code'   => self::post( 'wr_code' ),
			'serial' => self::post( 'wr_serial' ),
		);
		if ( ! WR_Security::hit( 'check' ) ) {
			$st['error'] = self::RATE_LIMIT_ERROR;
		} elseif ( ! WR_Security::verify_captcha() ) {
			$st['error'] = self::CAPTCHA_ERROR;
		} else {
			$w = WR_Warranties::get_by_code( $st['code'] );
			if ( $w && WR_Util::serial_key( (string) $w->serial ) === WR_Util::serial_key( $st['serial'] ) && '' !== WR_Util::serial_key( $st['serial'] ) ) {
				$st['warranty_id'] = (int) $w->id;
			} else {
				$st['error'] = 'Δεν βρέθηκε εγγύηση με αυτά τα στοιχεία. Ελέγξτε τον κωδικό εγγύησης και τον σειριακό αριθμό.';
			}
		}
		self::$state['check'] = $st;
	}

	public static function shortcode_check(): string {
		wp_enqueue_style( 'wr-frontend' );
		$st   = self::$state['check'];
		$html = '<div class="wr-box">';

		if ( ! empty( $st['warranty_id'] ) ) {
			$w      = WR_Warranties::get( (int) $st['warranty_id'] );
			$status = WR_Warranties::effective_status( $w );
			$days   = WR_Util::days_between( WR_Util::today(), $w->expires_on );
			$html  .= '<div class="wr-alert wr-alert--' . ( 'active' === $status ? 'success' : 'error' ) . '" role="status">Κατάσταση εγγύησης: <strong>' . esc_html( WR_Warranties::status_label( $status ) ) . '</strong>';
			if ( 'active' === $status ) {
				$html .= ' – απομένουν ' . (int) $days . ' ημέρες';
			}
			$html .= '</div><dl class="wr-review">';
			$rows  = array(
				'Κωδικός εγγύησης'  => $w->code,
				'Προϊόν'            => WR_Warranties::product_label( $w ),
				'Σειριακός αριθμός' => $w->serial,
				'Κάτοχος'           => WR_Util::mask_name( $w->full_name ),
				'Ισχύει από'        => WR_Util::format_date( $w->starts_on ),
				'Λήγει'             => WR_Util::format_date( $w->expires_on ),
			);
			foreach ( $rows as $k => $v ) {
				if ( '' !== (string) $v ) {
					$html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
				}
			}
			$html .= '</dl><p><a class="wr-btn wr-btn--secondary" href="' . esc_url( remove_query_arg( 'wr_done' ) ) . '">Νέος έλεγχος</a></p></div>';
			return $html;
		}

		$html .= self::error_box( $st['error'] ?? null );
		$html .= '<form method="post" class="wr-form" novalidate><input type="hidden" name="wr_form" value="check">';
		$html .= '<p class="wr-field"><label for="wr_code">Κωδικός εγγύησης</label><input type="text" id="wr_code" name="wr_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="π.χ. ' . esc_attr( WR_Settings::get( 'code_prefix' ) ) . '-XXXX-XXXX" value="' . esc_attr( $st['code'] ?? '' ) . '"></p>';
		$html .= '<p class="wr-field"><label for="wr_check_serial">Σειριακός αριθμός προϊόντος</label><input type="text" id="wr_check_serial" name="wr_serial" required autocomplete="off" autocapitalize="characters" spellcheck="false" value="' . esc_attr( $st['serial'] ?? '' ) . '"></p>';
		$html .= WR_Security::captcha_html();
		$html .= '<p><button type="submit" class="wr-btn">Έλεγχος</button></p></form></div>';
		return $html;
	}
}
