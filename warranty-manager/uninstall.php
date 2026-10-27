<?php
/**
 * Removes plugin data only when "delete data on uninstall" is enabled in settings,
 * so that deleting the plugin by mistake does not destroy warranty records.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'wr_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'products', 'serials', 'batches', 'warranties', 'terms', 'consents', 'rules', 'notification_log', 'audit_log' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wr_{$t}" ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wr\\_%'" ); // phpcs:ignore
remove_role( 'wr_support' );
foreach ( array( 'administrator' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'wr_view' );
		$role->remove_cap( 'wr_edit' );
		$role->remove_cap( 'wr_manage' );
	}
}
