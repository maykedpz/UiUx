<?php
/**
 * Header — Corpmerch.
 *
 * Opens the site-wide app shell: a persistent premium dark sidebar (the
 * category rail) on desktop that collapses into an off-canvas drawer on
 * mobile, plus a slim top bar for search / account / cart. The sidebar nav
 * is built from the top-level product_cat terms and their children, so the
 * menu always mirrors the imported taxonomy.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch top-level product categories with their immediate children,
 * excluding the hidden fallback buckets (see corpmerch_hidden_cat_slugs()).
 */
function corpmerch_nav_tree() {
	$exclude = function_exists( 'corpmerch_hidden_cat_ids' ) ? corpmerch_hidden_cat_ids() : array();

	$tops = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'parent'     => 0,
		'exclude'    => $exclude,
		'orderby'    => 'name',
	) );

	$tree = array();
	if ( ! is_wp_error( $tops ) ) {
		foreach ( $tops as $top ) {
			$kids = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => (int) $top->term_id,
				'orderby'    => 'name',
			) );
			$tree[] = array(
				'term'     => $top,
				'children' => is_wp_error( $kids ) ? array() : $kids,
			);
		}
	}
	return $tree;
}

$cm_nav     = corpmerch_nav_tree();
$cm_cart    = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
$cm_shop    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
$cm_count   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
$cm_account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
if ( '' === (string) $cm_account ) {
	$cm_account = home_url( '/account/' );
}
$cm_phone = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( 'phone', '' ) ) : '';

/*
 * Resolve the active product category so the sidebar rail can highlight the
 * current location and auto-expand its parent group.
 *   $cm_active_term_id — the exact term being viewed (top-level or child)
 *   $cm_active_top_id  — its top-level ancestor (the branch to open/mark)
 *   $cm_is_shop_active — true on the main Shop page ("All products")
 */
$cm_active_term_id = 0;
$cm_active_top_id  = 0;
$cm_is_shop_active = false;

if ( function_exists( 'is_product_category' ) && is_product_category() ) {
	$cm_qo = get_queried_object();
	if ( $cm_qo instanceof WP_Term ) {
		$cm_active_term_id = (int) $cm_qo->term_id;
		$cm_anc            = get_ancestors( $cm_qo->term_id, 'product_cat' );
		$cm_active_top_id  = ! empty( $cm_anc ) ? (int) end( $cm_anc ) : (int) $cm_qo->term_id;
	}
} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
	$cm_is_shop_active = true;
} elseif ( function_exists( 'is_product' ) && is_product() ) {
	$cm_pterms = get_the_terms( get_the_ID(), 'product_cat' );
	if ( $cm_pterms && ! is_wp_error( $cm_pterms ) ) {
		$cm_pfirst         = reset( $cm_pterms );
		$cm_active_term_id = (int) $cm_pfirst->term_id;
		$cm_anc            = get_ancestors( $cm_pfirst->term_id, 'product_cat' );
		$cm_active_top_id  = ! empty( $cm_anc ) ? (int) end( $cm_anc ) : (int) $cm_pfirst->term_id;
	}
}

/** Brand mark, reused in the rail head and the mobile top bar. */
if ( ! function_exists( 'corpmerch_brand_mark' ) ) {
	function corpmerch_brand_mark( $img_class = '' ) {
		$img = function_exists( 'corpmerch_logo_img' ) ? corpmerch_logo_img( $img_class ) : '';
		if ( $img ) {
			return $img; // wp_get_attachment_image is safe.
		}
		if ( has_custom_logo() ) {
			ob_start();
			the_custom_logo();
			return (string) ob_get_clean();
		}
		return 'Corp<span>merch</span>';
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="screen-reader-text" href="#cm-content"><?php esc_html_e( 'Skip to content', 'corpmerch' ); ?></a>

<div class="cm-shell">

	<!-- Sidebar: persistent rail on desktop, off-canvas drawer on mobile -->
	<aside class="cm-rail" id="cm-sidebar" aria-label="<?php esc_attr_e( 'Site navigation', 'corpmerch' ); ?>">
		<div class="cm-rail__head">
			<a class="cm-logo cm-logo--rail" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php
				echo corpmerch_brand_mark( 'cm-logo__img cm-logo__img--rail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?></a>
			<button class="cm-iconbtn cm-rail__close" id="cm-rail-close" aria-label="<?php esc_attr_e( 'Close menu', 'corpmerch' ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
		</div>

		<nav class="cm-rail__nav" aria-label="<?php esc_attr_e( 'Shop categories', 'corpmerch' ); ?>">
			<p class="cm-rail__label"><?php esc_html_e( 'Shop by category', 'corpmerch' ); ?></p>
			<ul class="cm-rail__list">
				<li class="cm-rail__item">
					<a class="cm-rail__link cm-rail__link--all<?php echo $cm_is_shop_active ? ' is-active' : ''; ?>"<?php echo $cm_is_shop_active ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $cm_shop ); ?>">
						<svg class="cm-rail__ico" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
						<span><?php esc_html_e( 'All products', 'corpmerch' ); ?></span>
					</a>
				</li>
				<?php foreach ( $cm_nav as $node ) :
					$t          = $node['term'];
					$has        = ! empty( $node['children'] );
					$is_promo   = preg_match( '/clearance|deal|sale|special/i', $t->name );
					$top_id     = (int) $t->term_id;
					$grp_active = ( $cm_active_top_id === $top_id );   // current branch (top or one of its children)
					$top_self   = ( $cm_active_term_id === $top_id );  // the top term itself is being viewed
					?>
					<li class="cm-rail__item<?php echo $is_promo ? ' is-promo' : ''; ?><?php echo $grp_active ? ' is-current' : ''; ?>">
						<?php if ( $has ) : ?>
							<button class="cm-rail__toggle<?php echo $grp_active ? ' is-open is-active' : ''; ?>" aria-expanded="<?php echo $grp_active ? 'true' : 'false'; ?>">
								<span class="cm-rail__dot" aria-hidden="true"></span>
								<span class="cm-rail__text"><?php echo esc_html( $t->name ); ?></span>
								<svg class="cm-rail__chev" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
							<ul class="cm-rail__sub<?php echo $grp_active ? ' is-open' : ''; ?>">
								<li><a<?php echo $top_self ? ' class="is-active" aria-current="page"' : ''; ?> href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php printf( esc_html__( 'All %s', 'corpmerch' ), esc_html( $t->name ) ); ?></a></li>
								<?php foreach ( $node['children'] as $kid ) :
									$kid_active = ( $cm_active_term_id === (int) $kid->term_id ); ?>
									<li><a<?php echo $kid_active ? ' class="is-active" aria-current="page"' : ''; ?> href="<?php echo esc_url( get_term_link( $kid ) ); ?>"><?php echo esc_html( $kid->name ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<a class="cm-rail__link<?php echo $top_self ? ' is-active' : ''; ?>"<?php echo $top_self ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( get_term_link( $t ) ); ?>">
								<span class="cm-rail__dot" aria-hidden="true"></span>
								<span class="cm-rail__text"><?php echo esc_html( $t->name ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<div class="cm-rail__foot">
			<ul class="cm-rail__util">
				<li><a href="<?php echo esc_url( $cm_account ); ?>"><?php esc_html_e( 'Business Login', 'corpmerch' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/track-order/' ) ); ?>"><?php esc_html_e( 'Track my order', 'corpmerch' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/returns/' ) ); ?>"><?php esc_html_e( 'Returns', 'corpmerch' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/help/' ) ); ?>"><?php esc_html_e( 'Help &amp; Support', 'corpmerch' ); ?></a></li>
			</ul>
			<?php if ( $cm_phone ) : ?>
				<a class="cm-rail__phone" href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $cm_phone ) ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" fill="currentColor"/></svg>
					<span><?php echo esc_html( $cm_phone ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</aside>

	<div class="cm-scrim" id="cm-scrim"></div>

	<div class="cm-shell__main">

		<header class="cm-topbar">
			<div class="cm-topbar__bar">
				<button class="cm-iconbtn cm-iconbtn--menu" id="cm-menu-open" aria-label="<?php esc_attr_e( 'Open menu', 'corpmerch' ); ?>" aria-controls="cm-sidebar" aria-expanded="false">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</button>

				<a class="cm-logo cm-logo--top" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php
					echo corpmerch_brand_mark( 'cm-logo__img cm-logo__img--top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?></a>

				<div class="cm-topbar__spacer"></div>

				<button type="button" class="cm-iconbtn" id="cm-search-toggle" aria-label="<?php esc_attr_e( 'Search products', 'corpmerch' ); ?>" aria-controls="cm-searchbar" aria-expanded="false">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</button>
				<a class="cm-iconbtn cm-iconbtn--account" href="<?php echo esc_url( $cm_account ); ?>" aria-label="<?php esc_attr_e( 'Account', 'corpmerch' ); ?>">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="2"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</a>
				<a class="cm-iconbtn cm-iconbtn--cart" href="<?php echo esc_url( $cm_cart ); ?>" aria-label="<?php esc_attr_e( 'Cart', 'corpmerch' ); ?>">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6h15l-1.5 9h-12L5 3H2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.5" fill="currentColor"/><circle cx="18" cy="20" r="1.5" fill="currentColor"/></svg>
					<span class="cm-cart-count"<?php echo $cm_count > 0 ? '' : ' style="display:none"'; ?>><?php echo $cm_count > 0 ? esc_html( $cm_count ) : ''; ?></span>
				</a>
			</div>
		</header>

		<!-- Product search bar (toggled by the top-bar search icon) -->
		<div class="cm-searchbar" id="cm-searchbar" aria-hidden="true">
			<form role="search" method="get" class="cm-searchbar__form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<input type="search" class="cm-searchbar__input" name="s" placeholder="<?php esc_attr_e( 'Search products…', 'corpmerch' ); ?>" aria-label="<?php esc_attr_e( 'Search products', 'corpmerch' ); ?>" autocomplete="off" />
				<input type="hidden" name="post_type" value="product" />
				<button type="submit" class="cm-searchbar__submit" aria-label="<?php esc_attr_e( 'Submit search', 'corpmerch' ); ?>">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</button>
			</form>
		</div>

		<main id="cm-content" class="cm-main">
