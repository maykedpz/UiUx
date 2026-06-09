<?php
/**
 * Corpmerch theme bootstrap.
 *
 * Standalone WooCommerce storefront for corporate merchandise. Products
 * import from the Kevro supplier feed (engine in /inc) and file into the
 * corporate-merchandise taxonomy. Design layer is Corpmerch's own.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CORPMERCH_VERSION', '2.0.0' );
define( 'CORPMERCH_DIR', get_template_directory() );
define( 'CORPMERCH_URI', get_template_directory_uri() );

define( 'CORPMERCH_MIN_WP', '6.9' );
define( 'CORPMERCH_MIN_WC', '10.0' );
define( 'CORPMERCH_MIN_PHP', '8.2' );

/* =========================================================================
 * Theme setup
 * ========================================================================= */
function corpmerch_setup() {
	load_theme_textdomain( 'corpmerch', CORPMERCH_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 300,
		'flex-width'  => true,
		'flex-height' => true,
	) );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );

	// WooCommerce.
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary' => __( 'Primary Menu', 'corpmerch' ),
		'footer'  => __( 'Footer Menu', 'corpmerch' ),
	) );
}
add_action( 'after_setup_theme', 'corpmerch_setup' );

function corpmerch_content_width() {
	$GLOBALS['content_width'] = 1280;
}
add_action( 'after_setup_theme', 'corpmerch_content_width', 0 );

/* =========================================================================
 * Footer widget areas (4 columns)
 * ========================================================================= */
function corpmerch_widgets() {
	for ( $i = 1; $i <= 4; $i++ ) {
		register_sidebar( array(
			'name'          => sprintf( __( 'Footer %d', 'corpmerch' ), $i ),
			'id'            => 'footer-' . $i,
			'before_widget' => '<div class="sia-widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="sia-widget-title">',
			'after_title'   => '</h3>',
		) );
	}
}
add_action( 'widgets_init', 'corpmerch_widgets' );

/* =========================================================================
 * Asset version helper — theme version + file mtime for cache-busting
 * ========================================================================= */
function corpmerch_asset_ver( $rel ) {
	$path = CORPMERCH_DIR . '/' . ltrim( $rel, '/' );
	$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
	return CORPMERCH_VERSION . '.' . $mtime;
}

/* =========================================================================
 * Front-end assets
 * ========================================================================= */
function corpmerch_enqueue_assets() {
	// Fonts: Fraunces (display) + Outfit (body).
	wp_enqueue_style(
		'corpmerch-fonts',
		'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Outfit:wght@300;400;500;600;700&display=swap',
		array(),
		null
	);
	// Main stylesheet (the theme's style.css = design system).
	wp_enqueue_style( 'corpmerch-style', get_stylesheet_uri(), array(), corpmerch_asset_ver( 'style.css' ) );

	// Interaction JS (drawer, dropdowns).
	wp_enqueue_script( 'corpmerch-main', CORPMERCH_URI . '/assets/js/main.js', array(), corpmerch_asset_ver( 'assets/js/main.js' ), true );

	// Hero slider (front page only).
	if ( is_front_page() || is_home() ) {
		wp_enqueue_script( 'corpmerch-hero-slider', CORPMERCH_URI . '/assets/js/hero-slider.js', array(), corpmerch_asset_ver( 'assets/js/hero-slider.js' ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'corpmerch_enqueue_assets', 20 );

/* =========================================================================
 * WooCommerce HPOS / feature compatibility
 * ========================================================================= */
function corpmerch_declare_wc_compat() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'corpmerch_declare_wc_compat' );

/* =========================================================================
 * Engine cluster — load order matters:
 *   settings first (defines OPTION_KEY the helpers read),
 *   then category mapping (CatMap2 admin + Cat_Map resolver),
 *   then feed/branding/sizing, then the Kevro engine last.
 * ========================================================================= */
require_once CORPMERCH_DIR . '/inc/corpmerch-helpers.php';
require_once CORPMERCH_DIR . '/inc/corpmerch-taxonomy.php';

require_once CORPMERCH_DIR . '/inc/class-corpmerch-settings.php';
require_once CORPMERCH_DIR . '/inc/class-corpmerch-policy-pages.php';

require_once CORPMERCH_DIR . '/inc/class-corpmerch-catmap2.php';   // manual mapping admin screen
require_once CORPMERCH_DIR . '/inc/class-corpmerch-catmap.php';    // static auto-resolver (Corpmerch_Cat_Map)

require_once CORPMERCH_DIR . '/inc/class-corpmerch-branding.php';
require_once CORPMERCH_DIR . '/inc/class-corpmerch-cart.php';
require_once CORPMERCH_DIR . '/inc/class-corpmerch-sizing.php';

require_once CORPMERCH_DIR . '/inc/class-corpmerch-feed.php';      // Merchant feed URL/registration
require_once CORPMERCH_DIR . '/inc/class-corpmerch-feed-xml.php';  // Merchant feed XML output

require_once CORPMERCH_DIR . '/inc/class-corpmerch-kevro.php';     // the import engine (self-instantiates)

if ( is_admin() ) {
	require_once CORPMERCH_DIR . '/inc/class-corpmerch-products-admin.php'; // Product Manager (filters + image kick)
	require_once CORPMERCH_DIR . '/inc/class-corpmerch-social-admin.php';   // Social Posts (FB post generator)
}

/* =========================================================================
 * Category importer — creates product_cat terms from corpmerch_taxonomy_data()
 * Lightweight inline version (the heavy upstream importer class is not
 * carried over). Idempotent: skips terms that already exist.
 * ========================================================================= */
function corpmerch_import_taxonomy() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return new WP_Error( 'no_taxonomy', __( 'WooCommerce product categories are not available yet.', 'corpmerch' ) );
	}
	$data    = corpmerch_taxonomy_data();
	$created = 0;

	foreach ( $data as $tier1 => $children ) {
		$p1 = corpmerch_ensure_term( $tier1, 0, $created );
		if ( ! $p1 ) {
			continue;
		}
		foreach ( $children as $tier2 => $tier3s ) {
			$p2 = corpmerch_ensure_term( $tier2, $p1, $created );
			if ( ! $p2 ) {
				continue;
			}
			if ( is_array( $tier3s ) ) {
				foreach ( $tier3s as $tier3 ) {
					corpmerch_ensure_term( $tier3, $p2, $created );
				}
			}
		}
	}
	if ( class_exists( 'Corpmerch_Cat_Map' ) ) {
		Corpmerch_Cat_Map::flush_cache();
	}
	return $created;
}

/** Ensure a product_cat term exists under $parent_id; return its term_id. */
function corpmerch_ensure_term( $name, $parent_id, &$created ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return 0;
	}
	// Disambiguate duplicate sub-names (e.g. "Outdoor" under several parents)
	// by checking name within the same parent rather than a global slug.
	$existing = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'parent'     => $parent_id,
		'name'       => $name,
		'number'     => 1,
	) );
	if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
		return (int) $existing[0]->term_id;
	}
	$res = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );
	if ( ! is_wp_error( $res ) && isset( $res['term_id'] ) ) {
		$created++;
		return (int) $res['term_id'];
	}
	return 0;
}

/* =========================================================================
 * Admin: run the category importer from a button on the Corpmerch settings
 * page (hooked generically so it works regardless of the settings UI).
 * ========================================================================= */
function corpmerch_maybe_run_import() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_GET['corpmerch_import_taxonomy'] ) && check_admin_referer( 'corpmerch_import_taxonomy' ) ) {
		$result = corpmerch_import_taxonomy();
		$count  = is_wp_error( $result ) ? 0 : (int) $result;
		add_action( 'admin_notices', function () use ( $count ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( 'Corpmerch: category import complete. %d new categories created.', 'corpmerch' ), $count ) )
			);
		} );
	}
}
add_action( 'admin_init', 'corpmerch_maybe_run_import' );

/* =========================================================================
 * Admin assets
 * ========================================================================= */
function corpmerch_admin_assets( $hook ) {
	if ( file_exists( CORPMERCH_DIR . '/assets/css/admin.css' ) ) {
		wp_enqueue_style( 'corpmerch-admin', CORPMERCH_URI . '/assets/css/admin.css', array(), CORPMERCH_VERSION );
	}
}
add_action( 'admin_enqueue_scripts', 'corpmerch_admin_assets' );

/* =========================================================================
 * Body class hook for styling states
 * ========================================================================= */
function corpmerch_body_class( $classes ) {
	$classes[] = 'corpmerch';
	return $classes;
}
add_filter( 'body_class', 'corpmerch_body_class' );

/* =========================================================================
 * Cart count fragment for the header icon
 * ========================================================================= */
function corpmerch_cart_count_fragment( $fragments ) {
	$count = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	ob_start();
	if ( $count > 0 ) {
		echo '<span class="cm-cart-count">' . esc_html( $count ) . '</span>';
	}
	$fragments['span.cm-cart-count'] = ob_get_clean();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'corpmerch_cart_count_fragment' );

/* =========================================================================
 * Homepage data helpers — recently viewed, popular, newest, featured
 * ========================================================================= */

/** Track recently-viewed products in a cookie (single-product views). */
function corpmerch_track_recently_viewed() {
	if ( ! is_singular( 'product' ) || is_admin() ) {
		return;
	}
	$pid = get_queried_object_id();
	if ( ! $pid ) {
		return;
	}
	$ids = array();
	if ( ! empty( $_COOKIE['corpmerch_rv'] ) ) {
		$ids = array_filter( array_map( 'absint', explode( ',', wp_unslash( $_COOKIE['corpmerch_rv'] ) ) ) );
	}
	$ids = array_diff( $ids, array( $pid ) );   // move to front
	array_unshift( $ids, $pid );
	$ids = array_slice( array_unique( $ids ), 0, 12 );
	// 30-day cookie; path-wide. Sent before output via template_redirect.
	setcookie( 'corpmerch_rv', implode( ',', $ids ), time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
	$_COOKIE['corpmerch_rv'] = implode( ',', $ids );
}
add_action( 'template_redirect', 'corpmerch_track_recently_viewed' );

/** IDs of recently-viewed products (excluding the current one). */
function corpmerch_recently_viewed_ids( $limit = 8 ) {
	if ( empty( $_COOKIE['corpmerch_rv'] ) ) {
		return array();
	}
	$ids = array_filter( array_map( 'absint', explode( ',', wp_unslash( $_COOKIE['corpmerch_rv'] ) ) ) );
	if ( is_singular( 'product' ) ) {
		$ids = array_diff( $ids, array( get_queried_object_id() ) );
	}
	return array_slice( $ids, 0, $limit );
}

/**
 * Query helper used by the homepage rows.
 *
 * @param string $mode  one of: newest | popular | featured | ids
 * @param int    $limit number of products
 * @param array  $ids   explicit IDs (mode=ids)
 * @return WC_Product[] array of product objects
 */
function corpmerch_get_products( $mode = 'newest', $limit = 8, $ids = array() ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$args = array(
		'status'  => 'publish',
		'limit'   => $limit,
		'orderby' => 'date',
		'order'   => 'DESC',
	);

	switch ( $mode ) {
		case 'ids':
			if ( empty( $ids ) ) {
				return array();
			}
			$args['include'] = $ids;
			$args['orderby'] = 'include';
			$args['limit']   = count( $ids );
			break;

		case 'popular':
			// WooCommerce stores lifetime sales in the total_sales meta.
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'total_sales';
			$args['order']    = 'DESC';
			break;

		case 'featured':
			$args['featured'] = true;
			break;

		case 'newest':
		default:
			// defaults already set
			break;
	}

	$products = wc_get_products( $args );

	// Fallbacks so rows are never empty on a fresh store.
	if ( empty( $products ) && 'ids' !== $mode ) {
		$products = wc_get_products( array(
			'status'  => 'publish',
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );
	}
	return $products;
}

/**
 * Render a product card (reused across homepage rows). Echoes markup.
 *
 * @param WC_Product $product
 */
function corpmerch_product_card( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$pid   = $product->get_id();
	$brand = '';
	$bt    = wp_get_post_terms( $pid, 'product_brand', array( 'fields' => 'names' ) );
	if ( ! is_wp_error( $bt ) && ! empty( $bt ) ) {
		$brand = $bt[0];
	}
	$permalink = get_permalink( $pid );
	?>
	<div class="cm-card">
		<a class="cm-card__link" href="<?php echo esc_url( $permalink ); ?>">
			<div class="cm-card__media">
				<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<div class="cm-card__body">
				<?php if ( $brand ) : ?><span class="cm-card__brand"><?php echo esc_html( $brand ); ?></span><?php endif; ?>
				<h3 class="cm-card__title"><?php echo esc_html( $product->get_name() ); ?></h3>
				<div class="cm-card__foot">
					<span class="cm-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<span class="cm-badge-brandable"><?php esc_html_e( 'Brandable', 'corpmerch' ); ?></span>
				</div>
			</div>
		</a>
		<?php
		// Add-to-cart / Choose-options button. Woo gives the right label & URL
		// automatically: "Add to cart" (simple), "Select options" (variable /
		// brandable), "Read more" (out of stock). Matches the archive cards.
		printf(
			'<a href="%s" data-quantity="1" class="cm-card__cta button %s" %s rel="nofollow">%s</a>',
			esc_url( $product->add_to_cart_url() ),
			esc_attr( $product->is_purchasable() && $product->is_in_stock() ? 'add_to_cart_button' : '' ),
			$product->supports( 'ajax_add_to_cart' ) && $product->is_purchasable() && $product->is_in_stock() ? 'data-product_id="' . esc_attr( $pid ) . '"' : '',
			esc_html( $product->add_to_cart_text() )
		);
		?>
	</div>
	<?php
}

/**
 * Render a full homepage product row (heading + horizontal-scroll grid).
 *
 * @param string $title
 * @param WC_Product[] $products
 * @param string $view_all_url
 */
function corpmerch_product_row( $title, $products, $view_all_url = '' ) {
	if ( empty( $products ) ) {
		return;
	}
	?>
	<section class="cm-section cm-row">
		<div class="cm-container">
			<div class="cm-row__head">
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $view_all_url ) : ?>
					<a class="cm-row__all" href="<?php echo esc_url( $view_all_url ); ?>"><?php esc_html_e( 'View all →', 'corpmerch' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="cm-row__track">
				<?php foreach ( $products as $p ) { corpmerch_product_card( $p ); } ?>
			</div>
		</div>
	</section>
	<?php
}

/**
 * Category archive: scrollable row of child categories (or siblings if the
 * current category is a leaf). Single line, horizontally scrollable. Mirrors
 * the front-page category chip strip.
 */
function corpmerch_archive_subcats() {
	if ( ! is_product_category() ) {
		return;
	}
	$current = get_queried_object();
	if ( ! $current instanceof WP_Term ) {
		return;
	}

	$hidden = corpmerch_hidden_cat_ids();
	$args   = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'parent'     => (int) $current->term_id,
		'exclude'    => $hidden,
	);

	$terms      = get_terms( $args );
	$is_sibling = false;

	// Leaf category: show siblings instead so navigation never dead-ends.
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		$args['parent'] = (int) $current->parent;
		$args['exclude'] = array_merge( $hidden, array( (int) $current->term_id ) );
		$terms           = get_terms( $args );
		$is_sibling      = true;
	}

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return;
	}

	$label = $is_sibling
		? __( 'Related categories', 'corpmerch' )
		: __( 'Browse', 'corpmerch' );
	?>
	<nav class="cm-subcats" aria-label="<?php echo esc_attr( $label ); ?>">
		<div class="cm-subcats__track">
			<?php if ( $is_sibling && $current->parent ) :
				$parent = get_term( $current->parent, 'product_cat' );
				if ( $parent && ! is_wp_error( $parent ) ) : ?>
					<a class="cm-subcat cm-subcat--all" href="<?php echo esc_url( get_term_link( $parent ) ); ?>">
						<?php echo esc_html( sprintf( __( 'All %s', 'corpmerch' ), $parent->name ) ); ?>
					</a>
				<?php endif;
			endif; ?>
			<?php foreach ( $terms as $term ) :
				$is_active = ( $term->term_id === $current->term_id ); ?>
				<a class="cm-subcat<?php echo $is_active ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( get_term_link( $term ) ); ?>"
				   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $term->name ); ?>
					<span class="cm-subcat__count"><?php echo (int) $term->count; ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</nav>
	<?php
}
add_action( 'woocommerce_before_shop_loop', 'corpmerch_archive_subcats', 5 );

/**
 * Archive loop cards: brand label above the title, to match the homepage
 * cm-card. Uses the same product_brand term lookup as corpmerch_product_card().
 */
function corpmerch_loop_brand_label() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$bt = wp_get_post_terms( $product->get_id(), 'product_brand', array( 'fields' => 'names' ) );
	if ( ! is_wp_error( $bt ) && ! empty( $bt ) ) {
		echo '<span class="cm-card__brand">' . esc_html( $bt[0] ) . '</span>';
	}
}
add_action( 'woocommerce_shop_loop_item_title', 'corpmerch_loop_brand_label', 5 );

/**
 * Archive loop cards: replace Woo's standalone price with a price + Brandable
 * row (matching the homepage card's cm-card__foot). We remove the default
 * price callback and render both together so they share one flex row.
 */
function corpmerch_loop_price_and_badge() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$price = $product->get_price_html();
	echo '<span class="cm-loop-foot">';
	echo '<span class="cm-loop-price">' . wp_kses_post( $price ) . '</span>';
	echo '<span class="cm-badge-brandable">' . esc_html__( 'Brandable', 'corpmerch' ) . '</span>';
	echo '</span>';
}
add_action( 'init', function () {
	// Swap Woo's loop price for our combined price+badge foot row.
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	add_action( 'woocommerce_after_shop_loop_item_title', 'corpmerch_loop_price_and_badge', 10 );
} );

/**
 * Pin the homepage category tiles (and sidebar order) to a chosen set/order.
 * Matched case-insensitively by category name or slug; unmatched entries are
 * ignored, and any categories not listed follow alphabetically behind these.
 */
add_filter( 'corpmerch_pinned_categories', function () {
	return array( 'Homeware', 'Sport', 'Chef Wear', 'Work Wear', 'Display', 'Gifting' );
} );
