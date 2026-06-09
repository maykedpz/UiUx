<?php
/**
 * WooCommerce wrapper — Corpmerch.
 * Wraps all WooCommerce content (shop, archives, single product, cart,
 * checkout) in the theme container. Branding options, brand info, and the
 * size guide are injected by the engine via WooCommerce hooks, so no custom
 * single-product template is required here.
 *
 * @package Corpmerch
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<div class="cm-container cm-section cm-woo">
	<?php woocommerce_content(); ?>
</div>
<?php
get_footer();
