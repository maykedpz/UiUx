<?php
/**
 * Plugin Name: Shipitall Marketplace
 * Plugin URI: https://example.com/shipitall
 * Description: Proprietary multivendor layer for Shipitall, built on top of WooCommerce. Vendor onboarding, product ownership, multi-seller order splitting, commission and payout tracking.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Shipitall
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shipitall-marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHIPITALL_MARKETPLACE_VERSION', '0.1.0' );
define( 'SHIPITALL_MARKETPLACE_FILE', __FILE__ );
define( 'SHIPITALL_MARKETPLACE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIPITALL_MARKETPLACE_URL', plugin_dir_url( __FILE__ ) );

/**
 * WooCommerce is a hard dependency — the whole point of this plugin is
 * the vendor layer on top of it, not a replacement for it. Refuse to
 * run without it rather than fail confusingly deeper in the code.
 */
function shipitall_marketplace_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'Shipitall Marketplace requires WooCommerce to be installed and active.',
				'shipitall-marketplace'
			);
			?>
		</p>
	</div>
	<?php
}

function shipitall_marketplace_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'shipitall_marketplace_woocommerce_missing_notice' );
		return;
	}

	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-install.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-roles.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-store-cpt.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-onboarding.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-products.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-commissions.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-order-splitter.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-payouts.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-admin-settings.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-vendor-dashboard.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-admin-menu.php';

	Shipitall_Roles::init();
	Shipitall_Store_CPT::init();
	Shipitall_Onboarding::init();
	Shipitall_Products::init();
	Shipitall_Order_Splitter::init();
	Shipitall_Admin_Settings::init();
	Shipitall_Vendor_Dashboard::init();
	Shipitall_Admin_Menu::init();
}
add_action( 'plugins_loaded', 'shipitall_marketplace_bootstrap', 20 );

function shipitall_marketplace_activate() {
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-install.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-roles.php';
	require_once SHIPITALL_MARKETPLACE_DIR . 'includes/class-shipitall-store-cpt.php';

	Shipitall_Install::create_tables();
	Shipitall_Roles::register_role();
	Shipitall_Store_CPT::register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'shipitall_marketplace_activate' );

function shipitall_marketplace_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'shipitall_marketplace_deactivate' );
