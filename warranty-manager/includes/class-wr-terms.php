<?php
defined( 'ABSPATH' ) || exit;

/**
 * Versioned warranty terms. Published versions are never edited, so a consent
 * record always points to the exact text the customer accepted.
 */
class WR_Terms {

	public static function current(): ?object {
		global $wpdb;
		$t = WR_DB::table( 'terms' );
		return $wpdb->get_row( "SELECT * FROM $t WHERE is_current = 1 ORDER BY id DESC LIMIT 1" ) ?: null;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$t = WR_DB::table( 'terms' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) ?: null;
	}

	public static function all(): array {
		global $wpdb;
		$t = WR_DB::table( 'terms' );
		return $wpdb->get_results( "SELECT id, version, content_hash, is_current, published_by, published_at FROM $t ORDER BY id DESC" );
	}

	public static function publish( string $version, string $content, int $user_id ): int {
		global $wpdb;
		$t = WR_DB::table( 'terms' );
		$wpdb->query( "UPDATE $t SET is_current = 0 WHERE is_current = 1" );
		$wpdb->insert(
			$t,
			array(
				'version'      => $version,
				'content'      => $content,
				'content_hash' => hash( 'sha256', $content ),
				'is_current'   => 1,
				'published_by' => $user_id,
				'published_at' => WR_Util::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}
}
