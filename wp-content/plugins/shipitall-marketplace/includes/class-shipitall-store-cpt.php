<?php
/**
 * The shipitall_store custom post type — one per vendor.
 *
 * Application status is modelled with native post statuses instead of
 * a custom meta flag: 'draft' = submitted, awaiting approval; 'publish'
 * = approved, live storefront. That gets us the wp-admin list table,
 * filters, and quick-edit for free instead of building an approval UI
 * from scratch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Store_CPT {

	const POST_TYPE = 'shipitall_store';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Vendor Stores', 'shipitall-marketplace' ),
					'singular_name' => __( 'Vendor Store', 'shipitall-marketplace' ),
					'add_new_item'  => __( 'Add Vendor Store', 'shipitall-marketplace' ),
					'edit_item'     => __( 'Edit Vendor Store', 'shipitall-marketplace' ),
					'all_items'     => __( 'Stores', 'shipitall-marketplace' ),
				),
				'public'               => true,
				'show_in_menu'         => true,
				'menu_icon'            => 'dashicons-store',
				'has_archive'          => 'stores',
				'rewrite'              => array( 'slug' => 'store' ),
				'supports'             => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest'         => true,
				'capability_type'      => array( 'shipitall_store', 'shipitall_stores' ),
				'map_meta_cap'         => true,
			)
		);
	}

	/**
	 * Fetch the store owned by a given user, if any. A user owns at
	 * most one store — enforced at creation time in the onboarding flow.
	 */
	public static function get_store_for_owner( $user_id ) {
		$stores = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 1,
				'meta_key'       => '_shipitall_owner_id',
				'meta_value'     => $user_id,
				'no_found_rows'  => true,
			)
		);

		return $stores ? $stores[0] : null;
	}

	public static function get_owner_id( $store_id ) {
		return (int) get_post_meta( $store_id, '_shipitall_owner_id', true );
	}

	public static function get_commission_rate_override( $store_id ) {
		$rate = get_post_meta( $store_id, '_shipitall_commission_rate', true );
		return ( '' === $rate || null === $rate ) ? null : (float) $rate;
	}

	public static function is_approved( $store_id ) {
		return 'publish' === get_post_status( $store_id );
	}
}
