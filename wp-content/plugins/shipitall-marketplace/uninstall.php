<?php
/**
 * Fires on plugin deletion (not deactivation) from wp-admin.
 * Removes the plugin's own tables, options and role. Leaves
 * WooCommerce products/orders and the shipitall_store posts alone —
 * those are content, not plugin config, and deleting a vendor's
 * products because an admin removed a plugin would be destructive.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shipitall_commissions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shipitall_payouts" );

delete_option( 'shipitall_default_commission_rate' );

remove_role( 'shipitall_vendor' );
