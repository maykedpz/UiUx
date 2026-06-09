<?php
/**
 * Front page — Corpmerch homepage.
 *
 * Sections render in the order, and with the on/off visibility, configured in
 * Corpmerch → Homepage Sections. The order is resolved by
 * Corpmerch_Settings::homepage_section_order(); each key maps to a
 * corpmerch_home_section_{key}() renderer below.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();


/* ========================================================================
 * Section renderers. Each is self-contained and safe to call in any order.
 * ===================================================================== */

if ( ! function_exists( 'corpmerch_home_categories' ) ) {
	/** Top-level product categories (excluding hidden buckets). Cached per request. */
	function corpmerch_home_categories() {
		static $cats = null;
		if ( null !== $cats ) {
			return $cats;
		}
		$exclude = function_exists( 'corpmerch_hidden_cat_ids' ) ? corpmerch_hidden_cat_ids() : array();
		$cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'parent'     => 0,
			'exclude'    => $exclude,
			'orderby'    => 'name',
			'number'     => 12,
		) );
		if ( is_wp_error( $cats ) ) {
			$cats = array();
		}

		/**
		 * Optionally pin specific categories to the front, in a chosen order.
		 * Return an array of category slugs. Pinned categories appear first in
		 * the given order; any remaining categories follow alphabetically.
		 * Affects the sidebar and both homepage tile grids.
		 *
		 * Example:
		 *   add_filter( 'corpmerch_pinned_categories', function () {
		 *       return array( 'homeware', 'sport', 'chef-wear', 'work-wear', 'display', 'gifting' );
		 *   } );
		 */
		$pinned = apply_filters( 'corpmerch_pinned_categories', array() );
		if ( ! empty( $pinned ) && is_array( $pinned ) ) {
			$lookup = array();
			foreach ( $cats as $c ) {
				$lookup[ strtolower( $c->slug ) ] = $c;
				$lookup[ strtolower( $c->name ) ] = $c;
			}
			$ordered = array();
			$used    = array();
			foreach ( $pinned as $key ) {
				$k = strtolower( trim( (string) $key ) );
				if ( isset( $lookup[ $k ] ) && ! isset( $used[ $lookup[ $k ]->term_id ] ) ) {
					$ordered[] = $lookup[ $k ];
					$used[ $lookup[ $k ]->term_id ] = true;
				}
			}
			$rest = array();
			foreach ( $cats as $c ) {
				if ( ! isset( $used[ $c->term_id ] ) ) {
					$rest[] = $c;
				}
			}
			$cats = array_merge( $ordered, $rest );
		}

		return $cats;
	}
}

if ( ! function_exists( 'corpmerch_home_shop_url' ) ) {
	function corpmerch_home_shop_url() {
		return isset( $GLOBALS['cm_home_ctx']['shop'] ) ? $GLOBALS['cm_home_ctx']['shop'] : home_url( '/shop/' );
	}
}

if ( ! function_exists( 'corpmerch_home_img_url' ) ) {
	/**
	 * Resolve a stored image value to a URL. Settings store attachment IDs,
	 * but accept a raw URL too (defensive). Returns '' when empty/invalid.
	 *
	 * @param mixed  $val  Attachment ID or URL.
	 * @param string $size Image size for ID lookups.
	 */
	function corpmerch_home_img_url( $val, $size = 'full' ) {
		if ( empty( $val ) ) {
			return '';
		}
		if ( is_numeric( $val ) ) {
			$url = wp_get_attachment_image_url( (int) $val, $size );
			return $url ? $url : '';
		}
		return esc_url_raw( (string) $val );
	}
}

if ( ! function_exists( 'corpmerch_home_section_hero' ) ) {
	/** HERO: category sidebar + image hero + two stacked side banners. */
	function corpmerch_home_section_hero() {
		$cm_shop = corpmerch_home_shop_url();
		$cats    = corpmerch_home_categories();
		$cat0    = isset( $cats[0] ) ? $cats[0] : null;
		$cat1    = isset( $cats[1] ) ? $cats[1] : null;

		$slides   = function_exists( 'corpmerch_option' ) ? corpmerch_option( 'hero_slides', array() ) : array();
		$slides   = is_array( $slides ) ? $slides : array();
		$autoplay = function_exists( 'corpmerch_bool' ) ? corpmerch_bool( 'hero_autoplay', true ) : true;
		$interval = function_exists( 'corpmerch_option' ) ? (int) corpmerch_option( 'hero_interval', 6 ) : 6;
		if ( $interval < 2 ) {
			$interval = 6;
		}
		$stack_img_1 = corpmerch_home_img_url( function_exists( 'corpmerch_option' ) ? corpmerch_option( 'home_banner_1_image', '' ) : '' );
		$stack_img_2 = corpmerch_home_img_url( function_exists( 'corpmerch_option' ) ? corpmerch_option( 'home_banner_2_image', '' ) : '' );
		?>
		<section class="cm-home-top cm-section--tight">
			<div class="cm-container">
				<div class="cm-home-top__grid">

					<?php // Category navigation now lives in the site-wide sidebar rail (see header.php). ?>

					<?php if ( ! empty( $slides ) ) : ?>
						<div class="cm-heroslider<?php echo count( $slides ) > 1 ? ' has-multiple' : ''; ?>"
							data-autoplay="<?php echo $autoplay && count( $slides ) > 1 ? '1' : '0'; ?>"
							data-interval="<?php echo esc_attr( $interval * 1000 ); ?>"
							role="region" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'Promotions', 'corpmerch' ); ?>">
							<div class="cm-heroslider__track">
								<?php
								$first = true;
								foreach ( $slides as $slide ) :
									$d_url = corpmerch_home_img_url( isset( $slide['image'] ) ? $slide['image'] : '', 'full' );
									$m_url = corpmerch_home_img_url( isset( $slide['image_mobile'] ) ? $slide['image_mobile'] : '', 'full' );
									if ( ! $d_url && ! $m_url ) {
										continue;
									}
									$m_url    = $m_url ? $m_url : $d_url;
									$d_url    = $d_url ? $d_url : $m_url;
									$eyebrow  = isset( $slide['eyebrow'] ) ? $slide['eyebrow'] : '';
									$title    = isset( $slide['title'] ) ? $slide['title'] : '';
									$subtitle = isset( $slide['subtitle'] ) ? $slide['subtitle'] : '';
									$cta_text = isset( $slide['cta_text'] ) ? $slide['cta_text'] : '';
									$cta_link = ! empty( $slide['cta_link'] ) ? $slide['cta_link'] : $cm_shop;
									?>
									<div class="cm-heroslide<?php echo $first ? ' is-active' : ''; ?>" role="group" aria-roledescription="slide">
										<a class="cm-heroslide__media" href="<?php echo esc_url( $cta_link ); ?>">
											<picture>
												<source media="(max-width: 899px)" srcset="<?php echo esc_url( $m_url ); ?>" />
												<img src="<?php echo esc_url( $d_url ); ?>" alt="<?php echo esc_attr( $title ? $title : __( 'Promotion', 'corpmerch' ) ); ?>" loading="<?php echo $first ? 'eager' : 'lazy'; ?>" />
											</picture>
											<?php if ( $eyebrow || $title || $subtitle || $cta_text ) : ?>
												<div class="cm-heroslide__body">
													<?php if ( $eyebrow ) : ?><span class="cm-eyebrow"><?php echo esc_html( $eyebrow ); ?></span><?php endif; ?>
													<?php
													if ( $title ) {
														// First slide's title is the page H1.
														$tag = $first ? 'h1' : 'h2';
														echo '<' . $tag . ' class="cm-heroslide__title">' . esc_html( $title ) . '</' . $tag . '>';
													}
													?>
													<?php if ( $subtitle ) : ?><p class="cm-heroslide__sub"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
													<?php if ( $cta_text ) : ?><span class="cm-btn cm-heroslide__cta"><?php echo esc_html( $cta_text ); ?></span><?php endif; ?>
												</div>
											<?php endif; ?>
										</a>
									</div>
									<?php
									$first = false;
								endforeach;
								?>
							</div>
							<?php if ( count( $slides ) > 1 ) : ?>
								<button class="cm-heroslider__nav cm-heroslider__prev" type="button" aria-label="<?php esc_attr_e( 'Previous slide', 'corpmerch' ); ?>">&#8249;</button>
								<button class="cm-heroslider__nav cm-heroslider__next" type="button" aria-label="<?php esc_attr_e( 'Next slide', 'corpmerch' ); ?>">&#8250;</button>
								<div class="cm-heroslider__dots" role="tablist">
									<?php for ( $i = 0; $i < count( $slides ); $i++ ) : ?>
										<button class="cm-heroslider__dot<?php echo 0 === $i ? ' is-active' : ''; ?>" type="button" data-i="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Go to slide %d', 'corpmerch' ), $i + 1 ) ); ?>"></button>
									<?php endfor; ?>
								</div>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<a class="cm-herobanner is-placeholder" href="<?php echo esc_url( $cm_shop ); ?>">
							<div class="cm-herobanner__ph">
								<span class="cm-eyebrow"><?php esc_html_e( 'Hero banner', 'corpmerch' ); ?></span>
								<p><?php esc_html_e( 'Add hero slides in Corpmerch → Hero & Sliders. This area links to the catalogue.', 'corpmerch' ); ?></p>
							</div>
						</a>
					<?php endif; ?>

					<div class="cm-herostack">
						<a class="cm-herostack__item cm-banner--accent<?php echo $stack_img_1 ? ' has-img' : ''; ?>"
							href="<?php echo esc_url( $cat0 ? get_term_link( $cat0 ) : $cm_shop ); ?>"
							<?php if ( $stack_img_1 ) : ?>style="background-image:url('<?php echo esc_url( $stack_img_1 ); ?>');"<?php endif; ?>>
							<div class="cm-banner__body">
								<span class="cm-banner__kicker"><?php esc_html_e( 'Featured', 'corpmerch' ); ?></span>
								<h3><?php echo esc_html( $cat0 ? $cat0->name : __( 'Apparel', 'corpmerch' ) ); ?></h3>
								<span class="cm-banner__link"><?php esc_html_e( 'Shop now →', 'corpmerch' ); ?></span>
							</div>
						</a>
						<a class="cm-herostack__item<?php echo $stack_img_2 ? ' has-img' : ''; ?>"
							href="<?php echo esc_url( $cat1 ? get_term_link( $cat1 ) : $cm_shop ); ?>"
							<?php if ( $stack_img_2 ) : ?>style="background-image:url('<?php echo esc_url( $stack_img_2 ); ?>');"<?php endif; ?>>
							<div class="cm-banner__body">
								<span class="cm-banner__kicker"><?php esc_html_e( 'Popular', 'corpmerch' ); ?></span>
								<h3><?php echo esc_html( $cat1 ? $cat1->name : __( 'Bags', 'corpmerch' ) ); ?></h3>
								<span class="cm-banner__link"><?php esc_html_e( 'Explore →', 'corpmerch' ); ?></span>
							</div>
						</a>
					</div>

					<ul class="cm-hometrust" aria-label="<?php esc_attr_e( 'Why shop with us', 'corpmerch' ); ?>">
						<li class="cm-hometrust__item">
							<span class="cm-hometrust__ico" aria-hidden="true">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 2l8 3v6c0 5-3.4 8.3-8 11-4.6-2.7-8-6-8-11V5l8-3z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</span>
							<span class="cm-hometrust__text">
								<span class="cm-hometrust__title"><?php esc_html_e( 'Secure payments', 'corpmerch' ); ?></span>
								<span class="cm-hometrust__sub"><?php esc_html_e( 'Encrypted &amp; protected checkout', 'corpmerch' ); ?></span>
							</span>
						</li>
						<li class="cm-hometrust__item">
							<span class="cm-hometrust__ico" aria-hidden="true">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="17" r="1.8" stroke="currentColor" stroke-width="1.7"/><circle cx="17" cy="17" r="1.8" stroke="currentColor" stroke-width="1.7"/></svg>
							</span>
							<span class="cm-hometrust__text">
								<span class="cm-hometrust__title"><?php esc_html_e( 'Fast delivery', 'corpmerch' ); ?></span>
								<span class="cm-hometrust__sub"><?php esc_html_e( 'Quick nationwide dispatch', 'corpmerch' ); ?></span>
							</span>
						</li>
						<li class="cm-hometrust__item">
							<span class="cm-hometrust__ico" aria-hidden="true">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 9h16v11H4zM3 6h18v3H3z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M12 6v14" stroke="currentColor" stroke-width="1.7"/><path d="M12 6S10 2.5 8 3.5 10 6 12 6zm0 0s2-3.5 4-2.5S14 6 12 6z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
							</span>
							<span class="cm-hometrust__text">
								<span class="cm-hometrust__title"><?php esc_html_e( 'Free delivery over R1000', 'corpmerch' ); ?></span>
								<span class="cm-hometrust__sub"><?php esc_html_e( 'On qualifying orders', 'corpmerch' ); ?></span>
							</span>
						</li>
						<li class="cm-hometrust__item">
							<span class="cm-hometrust__ico" aria-hidden="true">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 6h18v12H3z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M3 7l9 6 9-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</span>
							<span class="cm-hometrust__text">
								<span class="cm-hometrust__title"><?php esc_html_e( 'Email support', 'corpmerch' ); ?></span>
								<span class="cm-hometrust__sub"><?php esc_html_e( 'We reply within 1 business day', 'corpmerch' ); ?></span>
							</span>
						</li>
					</ul>

				</div>
			</div>
		</section>
		<?php
	}
}

if ( ! function_exists( 'corpmerch_home_section_category_grid' ) ) {
	/** Shop-by-category tiles. */
	function corpmerch_home_section_category_grid() {
		$cats = corpmerch_home_categories();
		if ( empty( $cats ) ) {
			return;
		}
		?>
		<section class="cm-section--tight">
			<div class="cm-container">
				<div class="cm-row__head"><h2><?php esc_html_e( 'Shop by category', 'corpmerch' ); ?></h2></div>
				<div class="cm-cat-tiles">
					<?php foreach ( $cats as $cat ) : ?>
						<a class="cm-cat-tile" href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}
}

if ( ! function_exists( 'corpmerch_home_section_recent_home' ) ) {
	/** Continue where you left off (recently viewed). Renders nothing if empty. */
	function corpmerch_home_section_recent_home() {
		if ( ! function_exists( 'corpmerch_recently_viewed_ids' ) ) {
			return;
		}
		$rv_ids = corpmerch_recently_viewed_ids( 8 );
		if ( empty( $rv_ids ) ) {
			return;
		}
		corpmerch_product_row( __( 'Continue where you left off', 'corpmerch' ), corpmerch_get_products( 'ids', 8, $rv_ids ), corpmerch_home_shop_url() );
	}
}

if ( ! function_exists( 'corpmerch_home_section_bestsellers' ) ) {
	/** Most popular. */
	function corpmerch_home_section_bestsellers() {
		corpmerch_product_row( __( 'Most popular', 'corpmerch' ), corpmerch_get_products( 'popular', 8 ), corpmerch_home_shop_url() );
	}
}

if ( ! function_exists( 'corpmerch_home_section_newest' ) ) {
	/** Newly added. */
	function corpmerch_home_section_newest() {
		corpmerch_product_row( __( 'Newly added', 'corpmerch' ), corpmerch_get_products( 'newest', 8 ), corpmerch_home_shop_url() );
	}
}

if ( ! function_exists( 'corpmerch_home_section_featured' ) ) {
	/** Featured picks. */
	function corpmerch_home_section_featured() {
		corpmerch_product_row( __( 'Featured picks', 'corpmerch' ), corpmerch_get_products( 'featured', 8 ), corpmerch_home_shop_url() );
	}
}

if ( ! function_exists( 'corpmerch_home_section_how_it_works' ) ) {
	/** How it works — the brand/process explainer. */
	function corpmerch_home_section_how_it_works() {
		$cm_shop = corpmerch_home_shop_url();
		?>
		<section class="cm-section cm-how">
			<div class="cm-container">
				<div class="cm-row__head" style="justify-content:center;text-align:center;flex-direction:column;">
					<p class="cm-eyebrow"><?php esc_html_e( 'How it works', 'corpmerch' ); ?></p>
					<h2><?php esc_html_e( 'Pick. Brand. Proof. Produce.', 'corpmerch' ); ?></h2>
				</div>
				<div class="cm-how__grid">
					<div class="cm-how__step"><span class="cm-how__num">1</span><h3><?php esc_html_e( 'Pick a product', 'corpmerch' ); ?></h3><p><?php esc_html_e( 'Browse live stock with real corporate pricing.', 'corpmerch' ); ?></p></div>
					<div class="cm-how__step"><span class="cm-how__num">2</span><h3><?php esc_html_e( 'Add your branding', 'corpmerch' ); ?></h3><p><?php esc_html_e( 'Choose positions and upload your logo or artwork.', 'corpmerch' ); ?></p></div>
					<div class="cm-how__step"><span class="cm-how__num">3</span><h3><?php esc_html_e( 'Approve a proof', 'corpmerch' ); ?></h3><p><?php esc_html_e( 'We send a proof sample to confirm placement.', 'corpmerch' ); ?></p></div>
					<div class="cm-how__step"><span class="cm-how__num">4</span><h3><?php esc_html_e( 'We produce', 'corpmerch' ); ?></h3><p><?php esc_html_e( 'Your branded merchandise, delivered.', 'corpmerch' ); ?></p></div>
				</div>
				<div style="text-align:center;margin-top:var(--cm-space-6);">
					<a class="cm-btn" href="<?php echo esc_url( $cm_shop ); ?>"><?php esc_html_e( 'Start browsing', 'corpmerch' ); ?></a>
				</div>
			</div>
		</section>
		<?php
	}
}


if ( ! function_exists( 'corpmerch_home_section_brand_banners' ) ) {
	/**
	 * Brand & promo banners — a feature tile + grid of promo/category tiles,
	 * each with image, heading below, and a "Shop now" link (FirstShop style).
	 * Grid on desktop, single stacked column on mobile.
	 */
	function corpmerch_home_section_brand_banners() {
		$promos = function_exists( 'corpmerch_option' ) ? corpmerch_option( 'promo_banners', array() ) : array();
		$promos = is_array( $promos ) ? $promos : array();

		// Build a unified tile list: promo banners first, then category tiles
		// to fill out the grid.
		$tiles = array();
		foreach ( $promos as $p ) {
			$img = corpmerch_home_img_url( isset( $p['image'] ) ? $p['image'] : '', 'large' );
			$tiles[] = array(
				'img'     => $img,
				'eyebrow' => isset( $p['eyebrow'] ) ? $p['eyebrow'] : '',
				'title'   => isset( $p['title'] ) ? $p['title'] : '',
				'cta'     => ! empty( $p['cta_text'] ) ? $p['cta_text'] : __( 'Shop now', 'corpmerch' ),
				'link'    => ! empty( $p['link'] ) ? $p['link'] : corpmerch_home_shop_url(),
			);
		}
		// Top categories as tiles (image from category thumbnail if set).
		$cats = corpmerch_home_categories();
		foreach ( array_slice( $cats, 0, 6 ) as $cat ) {
			$thumb_id = (int) get_term_meta( $cat->term_id, 'thumbnail_id', true );
			$img = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
			$tiles[] = array(
				'img'     => $img,
				'eyebrow' => '',
				'title'   => $cat->name,
				'cta'     => __( 'Shop now', 'corpmerch' ),
				'link'    => get_term_link( $cat ),
			);
		}
		if ( empty( $tiles ) ) {
			return;
		}
		$feature = array_shift( $tiles ); // first tile is the large feature
		?>
		<section class="cm-section cm-brandbanners">
			<div class="cm-container">
				<div class="cm-row__head"><h2><?php esc_html_e( 'Shop our brands &amp; deals', 'corpmerch' ); ?></h2>
					<a class="cm-row__all" href="<?php echo esc_url( corpmerch_home_shop_url() ); ?>"><?php esc_html_e( 'Shop now', 'corpmerch' ); ?> &rarr;</a>
				</div>
				<div class="cm-bb-grid">
					<a class="cm-bb-tile cm-bb-tile--feature" href="<?php echo esc_url( $feature['link'] ); ?>">
						<span class="cm-bb-tile__media<?php echo $feature['img'] ? '' : ' is-ph'; ?>"<?php echo $feature['img'] ? ' style="background-image:url(\'' . esc_url( $feature['img'] ) . '\')"' : ''; ?>></span>
						<span class="cm-bb-tile__cap">
							<?php if ( $feature['eyebrow'] ) : ?><span class="cm-eyebrow"><?php echo esc_html( $feature['eyebrow'] ); ?></span><?php endif; ?>
							<span class="cm-bb-tile__title"><?php echo esc_html( $feature['title'] ); ?></span>
							<span class="cm-bb-tile__cta"><?php echo esc_html( $feature['cta'] ); ?> &rarr;</span>
						</span>
					</a>
					<?php foreach ( $tiles as $t ) : ?>
						<a class="cm-bb-tile" href="<?php echo esc_url( $t['link'] ); ?>">
							<span class="cm-bb-tile__media<?php echo $t['img'] ? '' : ' is-ph'; ?>"<?php echo $t['img'] ? ' style="background-image:url(\'' . esc_url( $t['img'] ) . '\')"' : ''; ?>></span>
							<span class="cm-bb-tile__cap">
								<?php if ( $t['eyebrow'] ) : ?><span class="cm-eyebrow"><?php echo esc_html( $t['eyebrow'] ); ?></span><?php endif; ?>
								<span class="cm-bb-tile__title"><?php echo esc_html( $t['title'] ); ?></span>
								<span class="cm-bb-tile__cta"><?php echo esc_html( $t['cta'] ); ?> &rarr;</span>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}
}


/* ========================================================================
 * Dispatch — runs AFTER all section renderers are defined above (PHP does
 * not hoist conditionally-defined functions, so this must come last).
 * ===================================================================== */

$GLOBALS['cm_home_ctx'] = array(
	'shop' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
);

if ( class_exists( 'Corpmerch_Settings' ) ) {
	$cm_sections = Corpmerch_Settings::homepage_section_order();
} else {
	$cm_sections = array( 'hero', 'category_grid', 'recent_home', 'bestsellers', 'newest', 'featured', 'how_it_works' );
}
foreach ( $cm_sections as $cm_key ) {
	$fn = 'corpmerch_home_section_' . $cm_key;
	if ( function_exists( $fn ) ) {
		call_user_func( $fn );
	}
}

get_footer();
