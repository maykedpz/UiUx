<?php
/**
 * The shipitall_vendor role.
 *
 * Vendors get just enough capability to manage their own products in
 * wp-admin (WooCommerce's product editor is reused rather than
 * rebuilt) and their own store post. They do NOT get manage_woocommerce
 * or edit_others_products, which is what actually keeps them off other
 * vendors' products and site-wide settings — WordPress's own
 * map_meta_cap ownership check does the enforcement, this role just
 * grants the baseline.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Roles {

	const ROLE = 'shipitall_vendor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'restrict_admin_menu' ), 999 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_vendor_admin_access' ) );
	}

	public static function register_role() {
		if ( get_role( self::ROLE ) ) {
			return;
		}

		add_role(
			self::ROLE,
			__( 'Vendor', 'shipitall-marketplace' ),
			array(
				'read'                          => true,
				'upload_files'                  => true,
				'edit_products'                 => true,
				'edit_published_products'       => true,
				'delete_products'               => true,
				'delete_published_products'     => true,
				'edit_shipitall_stores'         => true,
				'edit_published_shipitall_stores' => true,
			)
		);
	}

	public static function is_vendor( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * WooCommerce locks non-shop_manager/admin users out of wp-admin by
	 * default (woocommerce_prevent_admin_access). Vendors legitimately
	 * need wp-admin for the product editor, so let them through — the
	 * menu restriction below and per-screen capability checks are what
	 * actually scope what they can see and do there.
	 */
	public static function allow_vendor_admin_access( $prevent ) {
		if ( self::is_vendor() ) {
			return false;
		}
		return $prevent;
	}

	/**
	 * Trim the wp-admin menu down to what a vendor actually needs:
	 * their products, media, and the Shipitall vendor screens. Menu
	 * hiding is a UX convenience, not a security boundary — the real
	 * boundary is capabilities, set above.
	 */
	public static function restrict_admin_menu() {
		if ( ! self::is_vendor() ) {
			return;
		}

		$remove = array(
			'index.php',
			'edit-comments.php',
			'themes.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
			'edit.php', // default "Posts" menu
		);

		foreach ( $remove as $slug ) {
			remove_menu_page( $slug );
		}

		remove_submenu_page( 'woocommerce', 'wc-settings' );
		remove_submenu_page( 'woocommerce', 'wc-status' );
		remove_submenu_page( 'woocommerce', 'wc-addons' );
	}
}
