<?php
/**
 * Commission calculation and ledger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Commissions {

	const DEFAULT_RATE_OPTION = 'shipitall_default_commission_rate';

	public static function get_default_rate() {
		return (float) get_option( self::DEFAULT_RATE_OPTION, 10 );
	}

	public static function get_rate_for_store( $store_id ) {
		$override = Shipitall_Store_CPT::get_commission_rate_override( $store_id );
		return null !== $override ? $override : self::get_default_rate();
	}

	/**
	 * Record a commission line for a vendor suborder. gross_amount is
	 * the suborder total — i.e. exactly what the customer was charged
	 * for that vendor's items, so the commission is always calculated
	 * against the real price paid, never a listed/"was" price.
	 */
	public static function record( $order_id, $suborder_id, $vendor_id, $store_id, $gross_amount ) {
		global $wpdb;

		$rate               = self::get_rate_for_store( $store_id );
		$commission_amount  = round( $gross_amount * ( $rate / 100 ), 2 );
		$payable_amount     = round( $gross_amount - $commission_amount, 2 );

		$wpdb->insert(
			Shipitall_Install::commissions_table(),
			array(
				'order_id'           => $order_id,
				'suborder_id'        => $suborder_id,
				'vendor_id'          => $vendor_id,
				'store_id'           => $store_id,
				'gross_amount'       => $gross_amount,
				'commission_rate'    => $rate,
				'commission_amount'  => $commission_amount,
				'payable_amount'     => $payable_amount,
				'status'             => 'pending',
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function get_pending_total_for_vendor( $vendor_id ) {
		global $wpdb;
		$table = Shipitall_Install::commissions_table();

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(payable_amount) FROM {$table} WHERE vendor_id = %d AND status = 'pending'",
				$vendor_id
			)
		);

		return $total ? (float) $total : 0.0;
	}

	public static function get_lifetime_total_for_vendor( $vendor_id ) {
		global $wpdb;
		$table = Shipitall_Install::commissions_table();

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(gross_amount) FROM {$table} WHERE vendor_id = %d",
				$vendor_id
			)
		);

		return $total ? (float) $total : 0.0;
	}

	public static function get_pending_vendor_ids() {
		global $wpdb;
		$table = Shipitall_Install::commissions_table();

		return $wpdb->get_col( "SELECT DISTINCT vendor_id FROM {$table} WHERE status = 'pending'" );
	}

	public static function get_recent_for_vendor( $vendor_id, $limit = 20 ) {
		global $wpdb;
		$table = Shipitall_Install::commissions_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY created_at DESC LIMIT %d",
				$vendor_id,
				$limit
			)
		);
	}

	public static function mark_paid_for_vendor( $vendor_id, $payout_id ) {
		global $wpdb;
		$table = Shipitall_Install::commissions_table();

		$wpdb->update(
			$table,
			array(
				'status'    => 'paid',
				'payout_id' => $payout_id,
			),
			array(
				'vendor_id' => $vendor_id,
				'status'    => 'pending',
			),
			array( '%s', '%d' ),
			array( '%d', '%s' )
		);
	}
}
