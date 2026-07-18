<?php
/**
 * Splits a single WooCommerce checkout into per-vendor suborders.
 *
 * The customer still checks out and pays once, through the one parent
 * order — this doesn't touch payment or totals. Suborders are an
 * internal bookkeeping split: one per vendor, containing only that
 * vendor's line items at the price the customer actually paid for
 * them, which is also what commission is calculated against. Items
 * with no linked vendor (platform-owned listings, if any) simply stay
 * on the parent order and are never split out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Order_Splitter {

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'split' ) );
	}

	public static function split( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_meta( '_shipitall_split', true ) ) {
			return;
		}

		$items_by_vendor = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();
			$store_id   = Shipitall_Products::get_store_id_for_product( $product_id );

			if ( ! $store_id || ! Shipitall_Store_CPT::is_approved( $store_id ) ) {
				continue;
			}

			$vendor_id = Shipitall_Store_CPT::get_owner_id( $store_id );
			if ( ! $vendor_id ) {
				continue;
			}

			$items_by_vendor[ $vendor_id ]['store_id'] = $store_id;
			$items_by_vendor[ $vendor_id ]['items'][]  = $item;
		}

		$suborder_ids = array();

		foreach ( $items_by_vendor as $vendor_id => $group ) {
			$suborder_id = self::create_suborder( $order, $vendor_id, $group['store_id'], $group['items'] );
			if ( $suborder_id ) {
				$suborder_ids[] = $suborder_id;
			}
		}

		if ( $suborder_ids ) {
			$order->update_meta_data( '_shipitall_suborder_ids', $suborder_ids );
		}

		$order->update_meta_data( '_shipitall_split', 1 );
		$order->save();
	}

	private static function create_suborder( $parent_order, $vendor_id, $store_id, $items ) {
		$suborder = wc_create_order(
			array(
				'customer_id' => $parent_order->get_customer_id(),
				'status'      => 'processing',
			)
		);

		if ( is_wp_error( $suborder ) ) {
			return 0;
		}

		$gross_amount = 0.0;

		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$suborder->add_product(
				$product,
				$item->get_quantity(),
				array(
					'subtotal' => $item->get_subtotal(),
					'total'    => $item->get_total(),
				)
			);

			$gross_amount += (float) $item->get_total();
		}

		$suborder->set_address( $parent_order->get_address( 'billing' ), 'billing' );
		$suborder->set_address( $parent_order->get_address( 'shipping' ), 'shipping' );

		$suborder->update_meta_data( '_shipitall_parent_order_id', $parent_order->get_id() );
		$suborder->update_meta_data( '_shipitall_vendor_id', $vendor_id );
		$suborder->update_meta_data( '_shipitall_store_id', $store_id );
		$suborder->update_meta_data( '_shipitall_is_suborder', 1 );

		$suborder->calculate_totals( false );
		$suborder->save();

		Shipitall_Commissions::record( $parent_order->get_id(), $suborder->get_id(), $vendor_id, $store_id, $gross_amount );

		return $suborder->get_id();
	}

	public static function get_suborders_for_vendor( $vendor_id, $limit = 20 ) {
		return wc_get_orders(
			array(
				'limit'      => $limit,
				'meta_key'   => '_shipitall_vendor_id',
				'meta_value' => $vendor_id,
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);
	}
}
