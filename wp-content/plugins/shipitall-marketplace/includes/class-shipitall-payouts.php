<?php
/**
 * Payout ledger.
 *
 * There's no bank/EFT integration here — that's a real external
 * integration (a payment provider or bank API), not something to fake.
 * This tracks that a payout happened once an admin has actually paid a
 * vendor (by EFT, typically, in the South African context) and flips
 * the underlying commission rows from pending to paid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Payouts {

	public static function create_for_vendor( $vendor_id, $note = '' ) {
		global $wpdb;

		$amount = Shipitall_Commissions::get_pending_total_for_vendor( $vendor_id );

		if ( $amount <= 0 ) {
			return 0;
		}

		$wpdb->insert(
			Shipitall_Install::payouts_table(),
			array(
				'vendor_id'  => $vendor_id,
				'amount'     => $amount,
				'status'     => 'paid',
				'note'       => $note,
				'created_at' => current_time( 'mysql' ),
				'paid_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s' )
		);

		$payout_id = (int) $wpdb->insert_id;

		Shipitall_Commissions::mark_paid_for_vendor( $vendor_id, $payout_id );

		return $payout_id;
	}

	public static function get_for_vendor( $vendor_id, $limit = 20 ) {
		global $wpdb;
		$table = Shipitall_Install::payouts_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY created_at DESC LIMIT %d",
				$vendor_id,
				$limit
			)
		);
	}

	public static function get_pending_summary() {
		$vendor_ids = Shipitall_Commissions::get_pending_vendor_ids();
		$summary    = array();

		foreach ( $vendor_ids as $vendor_id ) {
			$vendor_id = (int) $vendor_id;
			$user      = get_userdata( $vendor_id );

			if ( ! $user ) {
				continue;
			}

			$summary[] = array(
				'vendor_id'   => $vendor_id,
				'name'        => $user->display_name,
				'pending'     => Shipitall_Commissions::get_pending_total_for_vendor( $vendor_id ),
			);
		}

		return $summary;
	}
}
