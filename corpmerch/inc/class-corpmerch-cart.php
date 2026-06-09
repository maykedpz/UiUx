<?php
/**
 * Corpmerch — Cart enhancements.
 *
 * Adds, without overriding any WooCommerce template:
 *   1. A "Continue shopping" button in the cart actions row.
 *   2. Accepted-payment marks beneath the "Proceed to checkout" button
 *      (reusing the same marks as the PDP trust section / footer).
 *   3. A "You may also like" product row below the cart totals.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Cart {

	public function __construct() {
		// 1. Continue shopping — in the cart actions area (left of "Update cart").
		add_action( 'woocommerce_cart_actions', array( $this, 'continue_shopping' ) );

		// 2. Payment marks — directly under the checkout button in the totals box.
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'payment_marks' ), 25 );

		// 3. You may also like — after the cart collaterals (totals/cross-sells).
		add_action( 'woocommerce_after_cart', array( $this, 'you_may_also_like' ) );

		// 4. When free shipping is available (cart qualifies), hide paid methods.
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_rates_when_free' ), 100 );
	}

	/**
	 * If any free-shipping rate is available for a package, remove all other
	 * (paid) shipping rates so only "Free shipping" shows. Triggered once the
	 * cart meets the free-shipping minimum configured in the WooCommerce zone.
	 *
	 * @param array $rates Available WC_Shipping_Rate objects, keyed by id.
	 * @return array
	 */
	public function hide_rates_when_free( $rates ) {
		$free = array();
		foreach ( $rates as $id => $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() ) {
				$free[ $id ] = $rate;
			}
		}
		return ! empty( $free ) ? $free : $rates;
	}

	/** Shop / catalogue URL the "continue" button points at. */
	private function shop_url() {
		$shop = wc_get_page_id( 'shop' );
		if ( $shop && get_post_status( $shop ) === 'publish' ) {
			return get_permalink( $shop );
		}
		return home_url( '/' );
	}

	public function continue_shopping() {
		printf(
			'<a class="button cm-continue-shopping" href="%s">%s</a>',
			esc_url( $this->shop_url() ),
			esc_html__( 'Continue shopping', 'corpmerch' )
		);
	}

	public function payment_marks() {
		if ( ! class_exists( 'Corpmerch_Branding' ) || ! method_exists( 'Corpmerch_Branding', 'payment_marks_html' ) ) {
			return;
		}
		echo '<div class="cm-cart-pay">';
		echo '<span class="cm-cart-pay-label">' . esc_html__( 'Accepted payments', 'corpmerch' ) . '</span>';
		echo Corpmerch_Branding::payment_marks_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
		echo '</div>';
	}

	/**
	 * "You may also like": products related (by category) to whatever is in the
	 * cart, excluding items already in the cart. Falls back to recent products
	 * when no related items resolve. Honours the related_count setting.
	 */
	public function you_may_also_like() {
		if ( WC()->cart && WC()->cart->is_empty() ) {
			return;
		}

		$count = function_exists( 'corpmerch_option' ) ? (int) corpmerch_option( 'related_count', 4 ) : 4;
		$count = $count > 0 ? min( $count, 12 ) : 4;

		$in_cart = array();
		$cat_ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) $item['product_id'];
			$in_cart[ $pid ] = true;
			$terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				$cat_ids = array_merge( $cat_ids, $terms );
			}
		}
		$cat_ids = array_values( array_unique( array_filter( $cat_ids ) ) );

		$args = array(
			'status'   => 'publish',
			'limit'    => $count,
			'orderby'  => 'rand',
			'exclude'  => array_keys( $in_cart ),
			'visibility' => 'catalog',
		);
		if ( $cat_ids ) {
			$args['category'] = array_map( 'sanitize_title', wp_list_pluck( get_terms( array( 'include' => $cat_ids, 'taxonomy' => 'product_cat' ) ), 'slug' ) );
		}

		$products = wc_get_products( $args );

		// Fallback: recent products if category match yielded nothing.
		if ( empty( $products ) ) {
			$products = wc_get_products( array(
				'status'     => 'publish',
				'limit'      => $count,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'exclude'    => array_keys( $in_cart ),
				'visibility' => 'catalog',
			) );
		}
		if ( empty( $products ) ) {
			return;
		}

		$heading = function_exists( 'corpmerch_option' )
			? corpmerch_option( 'related_heading', __( 'You may also like', 'corpmerch' ) )
			: __( 'You may also like', 'corpmerch' );

		echo '<section class="cm-cart-related" aria-label="' . esc_attr( $heading ) . '">';
		echo '<h2 class="cm-cart-related-title">' . esc_html( $heading ) . '</h2>';

		// Reuse the WooCommerce product loop for consistent card styling.
		echo '<ul class="products columns-4 cm-cart-related-grid">';
		$original = $GLOBALS['post'];
		foreach ( $products as $product ) {
			$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $GLOBALS['post'] );
			wc_get_template_part( 'content', 'product' );
		}
		$GLOBALS['post'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();
		echo '</ul>';

		echo '</section>';
	}
}

new Corpmerch_Cart();
