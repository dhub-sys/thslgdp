<?php
defined( 'ABSPATH' ) || exit;

/**
 * Serial lookup, activation and warranty lifecycle.
 */
class WR_Warranties {

	/**
	 * Returns the serial row if it exists and can still be activated.
	 */
	public static function find_available_serial( string $serial ): ?object {
		global $wpdb;
		$key = WR_Util::serial_key( $serial );
		if ( '' === $key ) {
			return null;
		}
		$s   = WR_DB::table( 'serials' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $s WHERE serial_key = %s", $key ) );
		return ( $row && 'available' === $row->status ) ? $row : null;
	}

	public static function get_serial( int $id ): ?object {
		global $wpdb;
		$s = WR_DB::table( 'serials' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $s WHERE id = %d", $id ) ) ?: null;
	}

	public static function get_product( ?int $id ): ?object {
		global $wpdb;
		if ( ! $id ) {
			return null;
		}
		$p = WR_DB::table( 'products' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $p WHERE id = %d", $id ) ) ?: null;
	}

	public static function duration_for( ?object $product ): int {
		return $product ? (int) $product->duration_months : (int) WR_Settings::get( 'default_duration_months' );
	}

	/**
	 * Activates a warranty for a serial.
	 *
	 * @param array $customer full_name, email, phone (already validated/normalized).
	 * @param array $consent  terms (object), ip, user_agent, recorded_by.
	 * @return object|WP_Error The new warranty row.
	 */
	public static function activate( int $serial_id, array $customer, array $consent, string $source = 'web' ) {
		global $wpdb;
		$serials = WR_DB::table( 'serials' );
		$warr    = WR_DB::table( 'warranties' );
		if ( ! WR_Crypto::ready() ) {
			return new WP_Error( 'wr_no_key', 'encryption key missing' );
		}

		// Claim the serial atomically: only one concurrent request can flip it.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $serials SET status = 'activated' WHERE id = %d AND status = 'available'", $serial_id ) );
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'wr_unavailable', 'serial not available' );
		}

		$serial  = self::get_serial( $serial_id );
		$product = self::get_product( $serial->product_id ? (int) $serial->product_id : null );
		$today   = WR_Util::today();
		$start   = $today;
		if ( 'sale_date' === WR_Settings::get( 'start_mode' ) && $serial->sale_date && $serial->sale_date <= $today ) {
			$start = $serial->sale_date;
		}
		$expires = WR_Util::add_months( $start, self::duration_for( $product ) );
		$now     = WR_Util::now();
		$terms   = $consent['terms'];

		$ok = false;
		for ( $i = 0; $i < 5 && ! $ok; $i++ ) {
			$code = WR_Util::generate_code();
			$ok   = (bool) $wpdb->insert(
				$warr,
				self::encrypt_pii( $customer ) + array(
					'code'       => $code,
					'serial_id'  => $serial_id,
					'product_id' => $serial->product_id,
					'starts_on'  => $start,
					'expires_on' => $expires,
					'status'     => 'active',
					'source'     => $source,
					'terms_id'   => $terms->id,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}
		if ( ! $ok ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $serials SET status = 'available' WHERE id = %d", $serial_id ) );
			return new WP_Error( 'wr_db', 'could not store warranty' );
		}
		$warranty_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			WR_DB::table( 'consents' ),
			array(
				'warranty_id'   => $warranty_id,
				'consent_type'  => 'warranty_terms',
				'terms_id'      => $terms->id,
				'terms_version' => $terms->version,
				'terms_hash'    => $terms->content_hash,
				'accepted_at'   => $now,
				'ip_address'    => WR_Crypto::encrypt( $consent['ip'] ?? '' ),
				'user_agent'    => WR_Crypto::encrypt( $consent['user_agent'] ?? '' ),
				'recorded_by'   => (int) ( $consent['recorded_by'] ?? 0 ),
			)
		);

		$warranty = self::get( $warranty_id );
		do_action( 'wr_warranty_activated', $warranty );
		return $warranty;
	}

	public static function get( int $id ): ?object {
		return self::query_one( 'w.id = %d', $id );
	}

	public static function get_by_code( string $code ): ?object {
		return self::query_one( 'w.code = %s', WR_Util::normalize_code( $code ) );
	}

	private static function query_one( string $where, $value ): ?object {
		global $wpdb;
		$sql = self::base_select() . " WHERE $where";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $value ) );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Runs a query built on base_select() and decrypts personal data in each row.
	 * $sql must already be prepared.
	 */
	public static function fetch_all( string $sql ): array {
		global $wpdb;
		return array_map( array( __CLASS__, 'hydrate' ), $wpdb->get_results( $sql ) );
	}

	const PII = array( 'full_name', 'email', 'phone', 'notes' );

	public static function hydrate( object $w ): object {
		foreach ( self::PII as $f ) {
			if ( property_exists( $w, $f ) ) {
				$w->$f = WR_Crypto::decrypt( $w->$f );
			}
		}
		return $w;
	}

	/**
	 * Turns plaintext personal fields into ciphertext columns plus blind indexes.
	 * Only the keys present in $data are touched.
	 */
	public static function encrypt_pii( array $data ): array {
		$out = array();
		foreach ( self::PII as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$out[ $f ] = WR_Crypto::encrypt( (string) $data[ $f ] );
			}
		}
		if ( array_key_exists( 'email', $data ) ) {
			$out['email_idx'] = WR_Crypto::email_index( (string) $data['email'] );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$out['phone_idx'] = WR_Crypto::phone_index( (string) $data['phone'] );
		}
		if ( array_key_exists( 'full_name', $data ) ) {
			$out['name_idx'] = substr( WR_Crypto::name_index( (string) $data['full_name'] ), 0, 255 );
		}
		return $out;
	}

	public static function base_select(): string {
		$w = WR_DB::table( 'warranties' );
		$s = WR_DB::table( 'serials' );
		$p = WR_DB::table( 'products' );
		return "SELECT w.*, s.serial, s.sale_date, p.code AS product_code, p.name AS product_name
			FROM $w w
			LEFT JOIN $s s ON s.id = w.serial_id
			LEFT JOIN $p p ON p.id = w.product_id";
	}

	public static function product_label( object $w ): string {
		if ( ! empty( $w->product_name ) ) {
			return $w->product_name;
		}
		return (string) ( $w->product_code ?? '' );
	}

	/** active | expired | void */
	public static function effective_status( object $w ): string {
		if ( 'void' === $w->status ) {
			return 'void';
		}
		return $w->expires_on < WR_Util::today() ? 'expired' : 'active';
	}

	public static function status_label( string $status ): string {
		$map = array(
			'active'    => 'Ενεργή',
			'expired'   => 'Ληγμένη',
			'void'      => 'Ακυρωμένη',
			'available' => 'Διαθέσιμος',
			'activated' => 'Ενεργοποιημένος',
		);
		return $map[ $status ] ?? $status;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data = self::encrypt_pii( $data ) + array_diff_key( $data, array_flip( self::PII ) );
		$data['updated_at'] = WR_Util::now();
		$wpdb->update( WR_DB::table( 'warranties' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Removes personal data while keeping the warranty, serial and dates for statistics.
	 */
	public static function anonymize( int $id ): void {
		global $wpdb;
		$w = self::get( $id );
		if ( ! $w || $w->anonymized_at ) {
			return;
		}
		self::update(
			$id,
			array(
				'full_name'     => '',
				'email'         => '',
				'phone'         => '',
				'notes'         => '',
				'anonymized_at' => WR_Util::now(),
			)
		);
		$wpdb->update( WR_DB::table( 'consents' ), array( 'ip_address' => null, 'user_agent' => null ), array( 'warranty_id' => $id ) );
		$wpdb->update( WR_DB::table( 'notification_log' ), array( 'recipient' => '' ), array( 'warranty_id' => $id ) );
	}

	public static function consents( int $warranty_id ): array {
		global $wpdb;
		$c = WR_DB::table( 'consents' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $c WHERE warranty_id = %d ORDER BY id", $warranty_id ) );
		foreach ( $rows as $r ) {
			$r->ip_address = WR_Crypto::decrypt( $r->ip_address );
			$r->user_agent = WR_Crypto::decrypt( $r->user_agent );
		}
		return $rows;
	}
}
