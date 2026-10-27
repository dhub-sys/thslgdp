<?php
defined( 'ABSPATH' ) || exit;

/**
 * Append-only log of administrative actions.
 */
class WR_Audit {

	public static function log( string $action, string $object_type = '', ?int $object_id = null, $details = null ): void {
		global $wpdb;
		$wpdb->insert(
			WR_DB::table( 'audit_log' ),
			array(
				'user_id'     => get_current_user_id(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'details'     => null === $details ? null : ( is_string( $details ) ? $details : wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ),
				'ip_address'  => WR_Util::client_ip(),
				'created_at'  => WR_Util::now(),
			)
		);
	}

	public static function action_label( string $action ): string {
		$labels = array(
			'warranty_view'       => 'Προβολή εγγύησης',
			'warranty_update'     => 'Αλλαγή στοιχείων εγγύησης',
			'warranty_void'       => 'Ακύρωση εγγύησης',
			'warranty_restore'    => 'Επαναφορά εγγύησης',
			'warranty_extend'     => 'Αλλαγή λήξης εγγύησης',
			'warranty_anonymize'  => 'Ανωνυμοποίηση',
			'warranty_resend'     => 'Επαναποστολή επιβεβαίωσης',
			'warranty_manual'     => 'Χειροκίνητη ενεργοποίηση',
			'warranties_export'   => 'Εξαγωγή εγγυήσεων',
			'import_start'        => 'Έναρξη εισαγωγής',
			'import_revert'       => 'Αναίρεση εισαγωγής',
			'serial_add'          => 'Προσθήκη σειριακού',
			'serial_void'         => 'Ακύρωση σειριακού',
			'serial_restore'      => 'Επαναφορά σειριακού',
			'product_save'        => 'Αποθήκευση προϊόντος',
			'product_delete'      => 'Διαγραφή προϊόντος',
			'terms_publish'       => 'Δημοσίευση όρων',
			'rule_save'           => 'Αποθήκευση κανόνα ειδοποίησης',
			'rule_delete'         => 'Διαγραφή κανόνα ειδοποίησης',
			'settings_save'       => 'Αποθήκευση ρυθμίσεων',
			'retention_anonymize' => 'Αυτόματη ανωνυμοποίηση (διατήρηση)',
			'privacy_erase'       => 'Διαγραφή μέσω εργαλείων απορρήτου',
			'twofa_enabled'       => 'Ενεργοποίηση 2FA',
			'twofa_success'       => 'Επιτυχής επαλήθευση 2FA',
			'twofa_failed'        => 'Λάθος κωδικός 2FA',
			'twofa_lockout'       => 'Κλείδωμα μετά από λάθος κωδικούς 2FA',
			'twofa_reset'         => 'Επαναφορά 2FA χρήστη',
			'twofa_recovery_used' => 'Χρήση κωδικού ανάκτησης',
			'access_denied'       => 'Άρνηση πρόσβασης (χρήστης/IP εκτός λίστας)',
			'login_2fa_success'   => 'Σύνδεση στο WordPress με 2FA',
			'login_ip_blocked'    => 'Αποκλεισμός IP μετά από αποτυχημένες συνδέσεις',
		);
		return $labels[ $action ] ?? $action;
	}
}
