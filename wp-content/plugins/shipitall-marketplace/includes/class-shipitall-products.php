<?php
/**
 * Ties WooCommerce products to a vendor's store.
 *
 * Ownership itself is just post_author (that's what WordPress's own
 * capability checks key off), _shipitall_store_id is a denormalised
 * lookup so the order splitter and "Sold by" display don't need a
 * user-meta round trip on every product.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Products {

	public static function init() {
		add_action( 'save_post_product', array( __CLASS__, 'link_product_to_store' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_admin_product_list' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'show_sold_by' ), 6 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'show_sold_by_loop' ), 15 );
	}

	public static function link_product_to_store( $product_id, $post ) {
		if ( wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}

		$owner_id = (int) $post->post_author;

		if ( ! Shipitall_Roles::is_vendor( $owner_id ) ) {
			return;
		}

		$store = Shipitall_Store_CPT::get_store_for_owner( $owner_id );

		if ( $store ) {
			update_post_meta( $product_id, '_shipitall_store_id', $store->ID );
		}
	}

	public static function get_store_id_for_product( $product_id ) {
		$store_id = get_post_meta( $product_id, '_shipitall_store_id', true );
		return $store_id ? (int) $store_id : 0;
	}

	/**
	 * A vendor browsing Products in wp-admin should only ever see their
	 * own — capabilities already stop them editing others', but without
	 * this they'd still see the full catalog listed.
	 */
	public static function scope_admin_product_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;
		if ( 'edit.php' !== $pagenow || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( Shipitall_Roles::is_vendor() && ! current_user_can( 'manage_woocommerce' ) ) {
			$query->set( 'author', get_current_user_id() );
		}
	}

	public static function show_sold_by() {
		global $product;
		self::render_sold_by( $product );
	}

	public static function show_sold_by_loop() {
		global $product;
		self::render_sold_by( $product );
	}

	private static function render_sold_by( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$store_id = self::get_store_id_for_product( $product->get_id() );
		if ( ! $store_id || ! Shipitall_Store_CPT::is_approved( $store_id ) ) {
			return;
		}

		printf(
			'<p class="shipitall-sold-by">%s <a href="%s">%s</a></p>',
			esc_html__( 'Sold by', 'shipitall-marketplace' ),
			esc_url( get_permalink( $store_id ) ),
			esc_html( get_the_title( $store_id ) )
		);
	}
}
