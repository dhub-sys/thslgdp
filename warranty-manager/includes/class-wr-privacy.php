<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WordPress' built-in privacy tools (Tools → Export/Erase Personal Data)
 * and suggests text for the privacy policy.
 */
class WR_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['warranty-manager'] = array(
			'exporter_friendly_name' => 'Εγγυήσεις προϊόντων',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['warranty-manager'] = array(
			'eraser_friendly_name' => 'Εγγυήσεις προϊόντων',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	private static function by_email( string $email ): array {
		global $wpdb;
		if ( '' === $email ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( WR_Warranties::base_select() . ' WHERE w.email = %s', $email ) );
	}

	public static function export( string $email, int $page = 1 ): array {
		$items = array();
		foreach ( self::by_email( $email ) as $w ) {
			$data = array(
				array( 'name' => 'Κωδικός εγγύησης', 'value' => $w->code ),
				array( 'name' => 'Προϊόν', 'value' => WR_Warranties::product_label( $w ) ),
				array( 'name' => 'Σειριακός αριθμός', 'value' => $w->serial ),
				array( 'name' => 'Ονοματεπώνυμο', 'value' => $w->full_name ),
				array( 'name' => 'Email', 'value' => $w->email ),
				array( 'name' => 'Κινητό', 'value' => $w->phone ),
				array( 'name' => 'Έναρξη', 'value' => WR_Util::format_date( $w->starts_on ) ),
				array( 'name' => 'Λήξη', 'value' => WR_Util::format_date( $w->expires_on ) ),
				array( 'name' => 'Ημερομηνία ενεργοποίησης', 'value' => $w->created_at ),
			);
			foreach ( WR_Warranties::consents( (int) $w->id ) as $c ) {
				$data[] = array(
					'name'  => 'Αποδοχή όρων εγγύησης',
					'value' => sprintf( 'έκδοση %s, %s, IP %s', $c->terms_version, $c->accepted_at, $c->ip_address ?: '—' ),
				);
			}
			$items[] = array(
				'group_id'    => 'warranties',
				'group_label' => 'Εγγυήσεις προϊόντων',
				'item_id'     => 'warranty-' . $w->id,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( string $email, int $page = 1 ): array {
		$removed = false;
		foreach ( self::by_email( $email ) as $w ) {
			WR_Warranties::anonymize( (int) $w->id );
			WR_Audit::log( 'privacy_erase', 'warranty', (int) $w->id );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( 'Τα προσωπικά στοιχεία των εγγυήσεων ανωνυμοποιήθηκαν. Ο σειριακός αριθμός και οι ημερομηνίες της εγγύησης διατηρούνται χωρίς στοιχεία προσώπου.' ) : array(),
			'done'           => true,
		);
	}

	public static function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$years = (int) WR_Settings::get( 'retention_years' );
		$text  = '<h2>Ενεργοποίηση εγγύησης προϊόντων</h2>'
			. '<p>Όταν ενεργοποιείτε την εγγύηση ενός προϊόντος συλλέγουμε τον σειριακό αριθμό, το ονοματεπώνυμο, το email και το κινητό σας τηλέφωνο. '
			. 'Τα στοιχεία αυτά χρησιμοποιούνται αποκλειστικά για τη διαχείριση της εγγύησης (επιβεβαίωση, εξυπηρέτηση, ειδοποίηση πριν τη λήξη). '
			. 'Νομική βάση είναι η εκτέλεση της σύμβασης εγγύησης (άρθρο 6 παρ. 1 β ΓΚΠΔ).</p>'
			. '<p>Καταγράφουμε την έκδοση των όρων εγγύησης που αποδεχθήκατε, την ημερομηνία/ώρα και τη διεύθυνση IP, ως απόδειξη της αποδοχής.</p>'
			. '<p>Τα μηνύματα αποστέλλονται μέσω παρόχων email/SMS που ενεργούν ως εκτελούντες την επεξεργασία. '
			. 'Τα προσωπικά στοιχεία ανωνυμοποιούνται ' . $years . ' έτη μετά τη λήξη της εγγύησης.</p>'
			. '<p>Μπορείτε να ζητήσετε πρόσβαση, διόρθωση ή διαγραφή των στοιχείων σας επικοινωνώντας μαζί μας.</p>';
		wp_add_privacy_policy_content( 'Warranty Manager', wp_kses_post( $text ) );
	}
}
