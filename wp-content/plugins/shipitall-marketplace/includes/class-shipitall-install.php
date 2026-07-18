<?php
/**
 * Database table creation.
 *
 * Commissions and payouts get their own tables rather than post meta —
 * both are ledgers that need aggregate queries (sum owed per vendor,
 * per-period totals) that post meta can't do efficiently.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Install {

	public static function commissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'shipitall_commissions';
	}

	public static function payouts_table() {
		global $wpdb;
		return $wpdb->prefix . 'shipitall_payouts';
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$commissions     = self::commissions_table();
		$payouts         = self::payouts_table();

		$sql = "CREATE TABLE {$commissions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			suborder_id BIGINT UNSIGNED NOT NULL,
			vendor_id BIGINT UNSIGNED NOT NULL,
			store_id BIGINT UNSIGNED NOT NULL,
			gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			payable_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payout_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY vendor_id (vendor_id),
			KEY suborder_id (suborder_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$payouts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vendor_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'paid',
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			paid_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY vendor_id (vendor_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
