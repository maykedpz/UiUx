<?php
/**
 * Corpmerch Settings panel.
 *
 * Top-level admin menu, tabbed, capability manage_options.
 * Every visual element of the store registers as a field here.
 * All values live in one option array: corpmerch_options.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Settings {

	const OPTION_KEY = 'corpmerch_options';
	const SLUG       = 'corpmerch';

	/** @var array Tab slug => label. */
	private $tabs = array();

	/** @var array Generic repeater schemas: option_key => fields[]. */
	private $repeaters = array();
	private $selects   = array();
	private $select_defaults = array();
	/** @var array Section id => description text. */
	private $section_desc = array();

	public function __construct() {
		$this->tabs = array(
			'branding'    => __( 'Branding', 'corpmerch' ),
			'header'      => __( 'Header & Announcement', 'corpmerch' ),
			'hero'        => __( 'Hero & Sliders', 'corpmerch' ),
			'homepage'    => __( 'Homepage Sections', 'corpmerch' ),
			'product'     => __( 'Product Cards', 'corpmerch' ),
			'shop'        => __( 'Shop & Filters', 'corpmerch' ),
			'performance' => __( 'Performance & Bunny', 'corpmerch' ),
			'seo'         => __( 'SEO & Feed', 'corpmerch' ),
			'widgets'     => __( 'Floating & Widgets', 'corpmerch' ),
			'footer'      => __( 'Footer & Policies', 'corpmerch' ),
			'business'    => __( 'Business Info', 'corpmerch' ),
			'kevro'       => __( 'Kevro Feed', 'corpmerch' ),
		);

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Corpmerch Settings', 'corpmerch' ),
			'Corpmerch',
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-store',
			3
		);
	}

	public function enqueue_admin( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	public function register_settings() {
		register_setting(
			self::OPTION_KEY . '_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		// ── Branding tab ──
		$this->section( 'cm_brand_identity', __( 'Logo & Favicon', 'corpmerch' ), 'branding', __( 'Used in the header and browser tab. SVG logo preferred.', 'corpmerch' ) );
		$this->add_image( 'logo', __( 'Logo', 'corpmerch' ), 'branding', __( 'SVG preferred. Max 300×80px.', 'corpmerch' ), 'cm_brand_identity' );
		$this->add_image( 'favicon', __( 'Favicon', 'corpmerch' ), 'branding', __( '512×512 PNG/ICO.', 'corpmerch' ), 'cm_brand_identity' );

		$this->section( 'cm_brand_colours', __( 'Brand Colours', 'corpmerch' ), 'branding', __( 'Drive the storefront CSS variables (buttons, badges, accents).', 'corpmerch' ) );
		$this->add_color( 'color_primary', __( 'Primary Colour', 'corpmerch' ), 'branding', '#1e3a8a', 'cm_brand_colours' );
		$this->add_color( 'color_accent', __( 'Accent Colour', 'corpmerch' ), 'branding', '#2563eb', 'cm_brand_colours' );

		// ── Header & Announcement tab ──
		$this->section( 'cm_announce_bar', __( 'Announcement Bar', 'corpmerch' ), 'header', __( 'Thin promo strip above the header.', 'corpmerch' ) );
		$this->add_checkbox( 'announce_on', __( 'Show announcement bar', 'corpmerch' ), 'header', 'cm_announce_bar' );
		$this->add_text( 'announce_text', __( 'Announcement text', 'corpmerch' ), 'header', __( 'e.g. Free delivery over R1000', 'corpmerch' ), 'cm_announce_bar' );
		$this->add_select(
			'announce_style',
			__( 'Top bar design', 'corpmerch' ),
			'header',
			array(
				'classic'  => __( '1 · Classic (solid accent)', 'corpmerch' ),
				'gradient' => __( '2 · Gradient sweep', 'corpmerch' ),
				'marquee'  => __( '3 · Scrolling marquee', 'corpmerch' ),
				'ticker'   => __( '4 · Dot-separated ticker', 'corpmerch' ),
				'pill'     => __( '5 · Centered pill', 'corpmerch' ),
				'split'    => __( '6 · Split (text + phone right)', 'corpmerch' ),
				'outline'  => __( '7 · Minimal underline', 'corpmerch' ),
				'dark'     => __( '8 · Dark contrast bar', 'corpmerch' ),
				'badge'    => __( '9 · Leading badge tag', 'corpmerch' ),
				'glow'     => __( '10 · Animated glow', 'corpmerch' ),
			),
			'marquee',
			'cm_announce_bar'
		);

		$this->section( 'cm_header_contact', __( 'Header Contact', 'corpmerch' ), 'header' );
		$this->add_text( 'phone', __( 'Support phone', 'corpmerch' ), 'header', '0800 000 000', 'cm_header_contact' );

		$this->section( 'cm_usp_strip', __( 'USP / Trust Strip', 'corpmerch' ), 'header', __( 'Thin strip of trust points under the header (e.g. fast delivery, secure payment). Up to 4 shown.', 'corpmerch' ) );
		$this->add_checkbox( 'usp_on', __( 'Show trust strip', 'corpmerch' ), 'header', 'cm_usp_strip' );
		$this->add_repeater(
			'usp_items',
			__( 'Trust points', 'corpmerch' ),
			'header',
			array(
				array( 'key' => 'icon', 'type' => 'text', 'label' => __( 'Icon (emoji or dashicon name)', 'corpmerch' ), 'ph' => __( '🚚 or truck', 'corpmerch' ) ),
				array( 'key' => 'title', 'type' => 'text', 'label' => __( 'Title', 'corpmerch' ), 'ph' => __( 'Fast delivery', 'corpmerch' ) ),
				array( 'key' => 'subtitle', 'type' => 'text', 'label' => __( 'Subtitle', 'corpmerch' ), 'ph' => __( 'Nationwide in 2-4 days', 'corpmerch' ) ),
			),
			__( 'Leave empty to show four sensible defaults.', 'corpmerch' ),
			'cm_usp_strip'
		);

		$this->section( 'cm_newsletter', __( 'Newsletter Capture', 'corpmerch' ), 'header', __( 'Email signup block shown above the footer columns.', 'corpmerch' ) );
		$this->add_checkbox( 'news_on', __( 'Show newsletter block', 'corpmerch' ), 'header', 'cm_newsletter' );
		$this->add_text( 'news_heading', __( 'Heading', 'corpmerch' ), 'header', __( 'Join the list', 'corpmerch' ), 'cm_newsletter' );
		$this->add_text( 'news_sub', __( 'Subtext', 'corpmerch' ), 'header', __( 'Deals & new arrivals, straight to your inbox.', 'corpmerch' ), 'cm_newsletter' );
		$this->add_text( 'news_action', __( 'Form action URL', 'corpmerch' ), 'header', __( 'Your Mailchimp/ESP POST URL (optional)', 'corpmerch' ), 'cm_newsletter' );

		$this->section( 'cm_header_layout', __( 'Header Layout', 'corpmerch' ), 'header', __( 'Overall arrangement of logo, search, actions and nav.', 'corpmerch' ) );
		$this->add_select(
			'header_layout',
			__( 'Header layout', 'corpmerch' ),
			'header',
			array(
				'classic'     => __( '1 · Classic', 'corpmerch' ),
				'centered'    => __( '2 · Centered logo', 'corpmerch' ),
				'searchbig'   => __( '3 · Search-dominant', 'corpmerch' ),
				'pill'        => __( '4 · Floating pill', 'corpmerch' ),
				'dark'        => __( '5 · Dark (matches footer)', 'corpmerch' ),
				'boxed'       => __( '6 · Boxed / contained', 'corpmerch' ),
				'tworow'      => __( '7 · Two-row (search full width)', 'corpmerch' ),
				'minimal'     => __( '8 · Minimal (search icon)', 'corpmerch' ),
				'megastore'   => __( '9 · Mega-store (accent nav)', 'corpmerch' ),
				'rail'        => __( '10 · Square rail', 'corpmerch' ),
				'gradient'    => __( '11 · Gradient brand bar', 'corpmerch' ),
				'stacked'     => __( '12 · Stacked center', 'corpmerch' ),
				'actionpills' => __( '13 · Action pills', 'corpmerch' ),
				'underline'   => __( '14 · Underlined nav, XL logo', 'corpmerch' ),
				'iconactions' => __( '15 · Icon-only actions', 'corpmerch' ),
				'inline'      => __( '16 · Inline nav (single row)', 'corpmerch' ),
				'tinted'      => __( '17 · Soft tinted', 'corpmerch' ),
				'cta'         => __( '18 · Big CTA button', 'corpmerch' ),
				'editorial'   => __( '19 · Editorial serif', 'corpmerch' ),
			),
			'dark',
			'cm_header_layout'
		);

		// ── Hero & Sliders tab ──
		$this->section( 'cm_hero_slider', __( 'Hero Slider', 'corpmerch' ), 'hero', __( 'Main banner. The first slide becomes the page H1.', 'corpmerch' ) );
		$this->add_checkbox( 'hero_autoplay', __( 'Autoplay slider', 'corpmerch' ), 'hero', 'cm_hero_slider' );
		$this->add_text( 'hero_interval', __( 'Autoplay interval (seconds)', 'corpmerch' ), 'hero', '6', 'cm_hero_slider' );
		add_settings_field( 'hero_slides', __( 'Hero slides', 'corpmerch' ), array( $this, 'field_hero_slides' ), self::SLUG . '_hero', 'cm_hero_slider' );

		$this->section( 'cm_hero_side', __( 'Side Promo Tiles', 'corpmerch' ), 'hero' );
		$this->add_repeater(
			'side_promos',
			__( 'Side promo tiles', 'corpmerch' ),
			'hero',
			array(
				array( 'key' => 'image', 'type' => 'image', 'label' => __( 'Background image', 'corpmerch' ), 'hint' => __( '600×600 (optional)', 'corpmerch' ) ),
				array( 'key' => 'kicker', 'type' => 'text', 'label' => __( 'Badge', 'corpmerch' ), 'ph' => __( 'New', 'corpmerch' ) ),
				array( 'key' => 'title', 'type' => 'text', 'label' => __( 'Title', 'corpmerch' ) ),
				array( 'key' => 'subtitle', 'type' => 'text', 'label' => __( 'Subtitle', 'corpmerch' ) ),
				array( 'key' => 'link', 'type' => 'url', 'label' => __( 'Link', 'corpmerch' ) ),
			),
			__( 'Small tiles beside the hero. Empty = the two default tiles.', 'corpmerch' ),
			'cm_hero_side'
		);

		// ── Homepage Banners (used by the front-page sidebar/hero layout) ──
		$this->section( 'cm_home_banners', __( 'Homepage Banners', 'corpmerch' ), 'hero', __( 'Two stacked banners shown beside the hero slider. Leave blank to show styled placeholders. They link to your first two categories.', 'corpmerch' ) );
		$this->add_image( 'home_banner_1_image', __( 'Right banner — top', 'corpmerch' ), 'hero', __( '~560×340 recommended.', 'corpmerch' ), 'cm_home_banners' );
		$this->add_image( 'home_banner_2_image', __( 'Right banner — bottom', 'corpmerch' ), 'hero', __( '~560×340 recommended.', 'corpmerch' ), 'cm_home_banners' );

		// ── Homepage Sections tab ──
		$this->section( 'cm_home_layout', __( 'Section Order & Visibility', 'corpmerch' ), 'homepage', __( 'Drag to reorder homepage sections; untick to hide.', 'corpmerch' ) );
		add_settings_field( 'homepage_sections', __( 'Sections', 'corpmerch' ), array( $this, 'field_homepage_sections' ), self::SLUG . '_homepage', 'cm_home_layout' );

		$this->section( 'cm_home_banners', __( 'Promo Banners', 'corpmerch' ), 'homepage' );
		$this->add_repeater(
			'promo_banners',
			__( 'Promo banners', 'corpmerch' ),
			'homepage',
			array(
				array( 'key' => 'image', 'type' => 'image', 'label' => __( 'Background image', 'corpmerch' ), 'hint' => __( '800×600', 'corpmerch' ) ),
				array( 'key' => 'eyebrow', 'type' => 'text', 'label' => __( 'Eyebrow', 'corpmerch' ), 'ph' => __( 'Deal of the week', 'corpmerch' ) ),
				array( 'key' => 'title', 'type' => 'text', 'label' => __( 'Title', 'corpmerch' ) ),
				array( 'key' => 'cta_text', 'type' => 'text', 'label' => __( 'Button text', 'corpmerch' ), 'ph' => __( 'Shop now', 'corpmerch' ) ),
				array( 'key' => 'link', 'type' => 'url', 'label' => __( 'Link', 'corpmerch' ) ),
			),
			__( 'Full-width promo blocks below the category grid. Empty = the two default banners.', 'corpmerch' ),
			'cm_home_banners'
		);

		$this->section( 'cm_home_deals', __( 'Deal Carousels', 'corpmerch' ), 'homepage', __( 'Live per-department product carousels.', 'corpmerch' ) );
		$this->add_text( 'deals_heading', __( 'Deals heading', 'corpmerch' ), 'homepage', __( 'Deals by department', 'corpmerch' ), 'cm_home_deals' );
		$this->add_select(
			'deals_source',
			__( 'Deal source', 'corpmerch' ),
			'homepage',
			array(
				'onsale'   => __( 'On sale', 'corpmerch' ),
				'featured' => __( 'Featured', 'corpmerch' ),
				'latest'   => __( 'Newest', 'corpmerch' ),
			),
			'onsale',
			'cm_home_deals'
		);
		$this->add_text( 'deals_per', __( 'Products per carousel', 'corpmerch' ), 'homepage', '8', 'cm_home_deals' );
		$this->add_text( 'deals_dept_limit', __( 'Max departments shown', 'corpmerch' ), 'homepage', '6', 'cm_home_deals' );
		$this->add_checkbox( 'deals_fallback', __( 'Fall back to newest when no deals exist', 'corpmerch' ), 'homepage', 'cm_home_deals' );

		$this->section( 'cm_home_more', __( 'Featured / Best Sellers / Recently Viewed', 'corpmerch' ), 'homepage', __( 'Extra product rows. Each self-hides when it has nothing to show, and can be reordered or turned off in Section Order & Visibility above.', 'corpmerch' ) );
		$this->add_checkbox( 'home_recent_enable', __( 'Show "Pick up where you left off" (recently viewed)', 'corpmerch' ), 'homepage', 'cm_home_more' );
		$this->add_text( 'home_recent_heading', __( 'Recently viewed heading', 'corpmerch' ), 'homepage', __( 'Pick up where you left off', 'corpmerch' ), 'cm_home_more' );
		$this->add_text( 'home_recent_count', __( 'Recently viewed to show', 'corpmerch' ), 'homepage', '8', 'cm_home_more' );
		$this->add_checkbox( 'home_featured_enable', __( 'Show featured products', 'corpmerch' ), 'homepage', 'cm_home_more' );
		$this->add_text( 'home_featured_heading', __( 'Featured heading', 'corpmerch' ), 'homepage', __( 'Featured products', 'corpmerch' ), 'cm_home_more' );
		$this->add_text( 'home_featured_count', __( 'Featured to show', 'corpmerch' ), 'homepage', '8', 'cm_home_more' );
		$this->add_checkbox( 'home_bestsellers_enable', __( 'Show best sellers', 'corpmerch' ), 'homepage', 'cm_home_more' );
		$this->add_text( 'home_bestsellers_heading', __( 'Best sellers heading', 'corpmerch' ), 'homepage', __( 'Best sellers', 'corpmerch' ), 'cm_home_more' );
		$this->add_text( 'home_bestsellers_count', __( 'Best sellers to show', 'corpmerch' ), 'homepage', '8', 'cm_home_more' );

		// ── Product Cards tab ──
		$this->section( 'cm_card_badges', __( 'Badges', 'corpmerch' ), 'product', __( 'Shown on the top-left of every product card.', 'corpmerch' ) );
		$this->add_checkbox( 'card_badge_sale', __( 'Show sale badge', 'corpmerch' ), 'product', 'cm_card_badges' );
		$this->add_checkbox( 'card_badge_pct', __( 'Show discount % on sale badge', 'corpmerch' ), 'product', 'cm_card_badges' );
		$this->add_checkbox( 'card_badge_new', __( 'Show "New" badge', 'corpmerch' ), 'product', 'cm_card_badges' );
		$this->add_text( 'card_new_days', __( '"New" window (days)', 'corpmerch' ), 'product', '21', 'cm_card_badges' );
		$this->add_checkbox( 'card_badge_hot', __( 'Show "Hot" badge on featured products', 'corpmerch' ), 'product', 'cm_card_badges' );
		$this->add_checkbox( 'card_badge_oos', __( 'Show "Sold out" badge', 'corpmerch' ), 'product', 'cm_card_badges' );

		$this->section( 'cm_card_behaviour', __( 'Card Behaviour', 'corpmerch' ), 'product', __( 'Interactive features on product cards site-wide.', 'corpmerch' ) );
		$this->add_checkbox( 'card_ajax_cart', __( 'AJAX add-to-cart + slide-out side cart', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_quick_view', __( 'Quick view button', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_wishlist', __( 'Wishlist heart', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_rating', __( 'Show star rating', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_hover_actions', __( 'Reveal action buttons on hover (desktop)', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_img_swap', __( 'Swap to 2nd gallery image on hover', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_checkbox( 'card_stock_urgency', __( 'Show "Only X left" pill on low stock', 'corpmerch' ), 'product', 'cm_card_behaviour' );
		$this->add_text( 'card_stock_threshold', __( 'Low-stock threshold', 'corpmerch' ), 'product', '5', 'cm_card_behaviour' );

		$this->section( 'cm_discovery', __( 'Related & Recently Viewed', 'corpmerch' ), 'product', __( 'Product-page carousels. Recently viewed can also be placed anywhere with the [corpmerch_recently_viewed] shortcode.', 'corpmerch' ) );
		$this->add_checkbox( 'related_enable', __( 'Show related products carousel', 'corpmerch' ), 'product', 'cm_discovery' );
		$this->add_text( 'related_heading', __( 'Related heading', 'corpmerch' ), 'product', __( 'You may also like', 'corpmerch' ), 'cm_discovery' );
		$this->add_text( 'related_count', __( 'Related products to show', 'corpmerch' ), 'product', '8', 'cm_discovery' );
		$this->add_checkbox( 'recent_enable', __( 'Track & enable recently viewed', 'corpmerch' ), 'product', 'cm_discovery' );
		$this->add_checkbox( 'recent_on_product', __( 'Auto-show recently viewed on product pages', 'corpmerch' ), 'product', 'cm_discovery' );
		$this->add_text( 'recent_heading', __( 'Recently viewed heading', 'corpmerch' ), 'product', __( 'Recently viewed', 'corpmerch' ), 'cm_discovery' );
		$this->add_text( 'recent_count', __( 'Recently viewed to show', 'corpmerch' ), 'product', '8', 'cm_discovery' );

		$this->section( 'cm_sizing', __( 'Size Charts', 'corpmerch' ), 'product', __( 'Apparel size guides. Charts are auto-detected from the Kevro CDN during the branding sync (item number → sizing image) and shown as an expandable thumbnail on the product page. Use the fallback list below for categories Kevro has no chart for.', 'corpmerch' ) );
		$this->add_text( 'sizing_base_url', __( 'CDN base URL', 'corpmerch' ), 'product', 'https://paznsaapp02.blob.core.windows.net/product-images', 'cm_sizing' );
		$this->add_text( 'sizing_suffixes', __( 'Filename suffix(es)', 'corpmerch' ), 'product', '-sizing.jpg, -sizing.png', 'cm_sizing' );
		$this->add_textarea( 'sizing_fallbacks', __( 'Category fallback charts', 'corpmerch' ), 'product', __( "apparel | https://example.com/charts/apparel.jpg\nfootwear | https://example.com/charts/footwear.jpg", 'corpmerch' ), 5, 'cm_sizing' );

		// ── Shop & Filters tab ──
		$this->section( 'cm_shop_layout', __( 'Shop Layout', 'corpmerch' ), 'shop', __( 'Controls the shop and category archive pages.', 'corpmerch' ) );
		$this->add_checkbox( 'shop_filters', __( 'Show filter sidebar', 'corpmerch' ), 'shop', 'cm_shop_layout' );
		$this->add_text( 'shop_per_page', __( 'Products per page', 'corpmerch' ), 'shop', '12', 'cm_shop_layout' );
		$this->add_select(
			'shop_load_mode',
			__( 'Pagination style', 'corpmerch' ),
			'shop',
			array(
				'button'   => __( 'Load-more button', 'corpmerch' ),
				'infinite' => __( 'Infinite scroll', 'corpmerch' ),
				'paged'    => __( 'Classic numbered pages', 'corpmerch' ),
			),
			'button',
			'cm_shop_layout'
		);
		$this->add_select(
			'shop_orderby',
			__( 'Default sort', 'corpmerch' ),
			'shop',
			array(
				''           => __( 'WooCommerce default', 'corpmerch' ),
				'menu_order' => __( 'Default (custom order)', 'corpmerch' ),
				'popularity' => __( 'Popularity', 'corpmerch' ),
				'rating'     => __( 'Average rating', 'corpmerch' ),
				'date'       => __( 'Newest', 'corpmerch' ),
				'price'      => __( 'Price: low to high', 'corpmerch' ),
				'price-desc' => __( 'Price: high to low', 'corpmerch' ),
			),
			'',
			'cm_shop_layout'
		);

		$this->section( 'cm_shop_filters', __( 'Filters', 'corpmerch' ), 'shop', __( 'Which filter groups appear in the sidebar.', 'corpmerch' ) );
		$this->add_checkbox( 'shop_filter_price', __( 'Price filter', 'corpmerch' ), 'shop', 'cm_shop_filters' );
		$this->add_checkbox( 'shop_filter_brand', __( 'Brand filter (auto-detected brand taxonomy)', 'corpmerch' ), 'shop', 'cm_shop_filters' );
		$this->add_checkbox( 'shop_filter_attrs', __( 'Product attribute filters', 'corpmerch' ), 'shop', 'cm_shop_filters' );

		$this->section( 'cm_cart_extras', __( 'Cart', 'corpmerch' ), 'shop', __( 'Side-cart enhancements.', 'corpmerch' ) );
		$this->add_checkbox( 'freeship_on', __( 'Show free-shipping progress bar in side cart', 'corpmerch' ), 'shop', 'cm_cart_extras' );
		$this->add_text( 'freeship_threshold', __( 'Free-shipping threshold', 'corpmerch' ), 'shop', __( 'e.g. 150 (store currency, numbers only)', 'corpmerch' ), 'cm_cart_extras' );

		$this->section( 'cm_shop_single', __( 'Product Page', 'corpmerch' ), 'shop' );
		$this->add_checkbox( 'shop_sticky_atc', __( 'Sticky add-to-cart bar on mobile', 'corpmerch' ), 'shop', 'cm_shop_single' );
		$this->add_checkbox( 'shop_skeletons', __( 'Show skeleton placeholders while loading more products', 'corpmerch' ), 'shop', 'cm_shop_single' );

		// ── Performance & Bunny tab ──
		$this->section( 'cm_bunny', __( 'Bunny.net CDN', 'corpmerch' ), 'performance', __( 'Serve images, CSS, JS and fonts from a Bunny pull zone. Set up the pull zone in Bunny first (origin = this site).', 'corpmerch' ) );
		$this->add_checkbox( 'bunny_cdn_on', __( 'Enable CDN URL rewrite', 'corpmerch' ), 'performance', 'cm_bunny' );
		$this->add_text( 'bunny_host', __( 'Pull zone hostname', 'corpmerch' ), 'performance', 'corpmerch.b-cdn.net', 'cm_bunny' );
		$this->add_checkbox( 'bunny_optimizer', __( 'Use Bunny Optimizer (auto WebP/AVIF + resize)', 'corpmerch' ), 'performance', 'cm_bunny' );
		$this->add_text( 'bunny_quality', __( 'Image quality (1–100)', 'corpmerch' ), 'performance', '85', 'cm_bunny' );

		$this->section( 'cm_perf_render', __( 'Render Performance', 'corpmerch' ), 'performance', __( 'Reduce render-blocking resources for a faster LCP.', 'corpmerch' ) );
		$this->add_checkbox( 'perf_preconnect', __( 'Preconnect to CDN + font hosts', 'corpmerch' ), 'performance', 'cm_perf_render' );
		$this->add_checkbox( 'perf_preload_hero', __( 'Preload hero image (LCP)', 'corpmerch' ), 'performance', 'cm_perf_render' );
		$this->add_checkbox( 'perf_lazyload', __( 'Lazy-load + async-decode below-fold images', 'corpmerch' ), 'performance', 'cm_perf_render' );
		$this->add_checkbox( 'perf_defer_js', __( 'Defer theme JavaScript', 'corpmerch' ), 'performance', 'cm_perf_render' );
		$this->add_checkbox( 'perf_defer_css', __( 'Load theme CSS non-blocking (requires critical CSS below)', 'corpmerch' ), 'performance', 'cm_perf_render' );
		$this->add_textarea( 'perf_critical_css', __( 'Critical CSS', 'corpmerch' ), 'performance', __( 'Above-the-fold CSS, inlined in <head>. Paste minified CSS only.', 'corpmerch' ), 'cm_perf_render', 8 );

		// ── SEO & Feed tab ──
		$this->section( 'cm_seo_general', __( 'SEO Output', 'corpmerch' ), 'seo', __( 'The theme outputs its own titles, meta, canonical, OpenGraph and JSON-LD. It stands down automatically if a dedicated SEO plugin is active.', 'corpmerch' ) );
		$this->add_checkbox( 'seo_enable', __( 'Enable theme SEO', 'corpmerch' ), 'seo', 'cm_seo_general' );
		$this->add_checkbox( 'seo_og', __( 'OpenGraph + Twitter cards', 'corpmerch' ), 'seo', 'cm_seo_general' );
		$this->add_checkbox( 'seo_jsonld', __( 'JSON-LD structured data', 'corpmerch' ), 'seo', 'cm_seo_general' );
		$this->add_checkbox( 'seo_sitemap', __( 'Enable XML sitemap (wp-sitemap.xml)', 'corpmerch' ), 'seo', 'cm_seo_general' );
		$this->add_text( 'seo_title_sep', __( 'Title separator', 'corpmerch' ), 'seo', '–', 'cm_seo_general' );

		$this->section( 'cm_seo_home', __( 'Homepage Meta', 'corpmerch' ), 'seo' );
		$this->add_text( 'seo_home_title', __( 'Home title', 'corpmerch' ), 'seo', get_bloginfo( 'name' ), 'cm_seo_home' );
		$this->add_textarea( 'seo_home_desc', __( 'Home meta description', 'corpmerch' ), 'seo', __( 'Up to ~160 characters.', 'corpmerch' ), 'cm_seo_home', 3 );
		$this->add_textarea( 'seo_default_desc', __( 'Fallback meta description', 'corpmerch' ), 'seo', __( 'Used when a page has no description of its own.', 'corpmerch' ), 'cm_seo_home', 3 );
		$this->add_image( 'seo_og_image', __( 'Default share image', 'corpmerch' ), 'seo', __( '1200×630 recommended.', 'corpmerch' ), 'cm_seo_home' );

		$this->section( 'cm_seo_org', __( 'Organization & Social', 'corpmerch' ), 'seo', __( 'Feeds the Organization JSON-LD (sameAs) and Twitter card.', 'corpmerch' ) );
		$this->add_text( 'seo_org_name', __( 'Organization name', 'corpmerch' ), 'seo', get_bloginfo( 'name' ), 'cm_seo_org' );
		$this->add_text( 'seo_twitter', __( 'Twitter/X handle', 'corpmerch' ), 'seo', '@corpmerch', 'cm_seo_org' );
		$this->add_text( 'seo_facebook', __( 'Facebook URL', 'corpmerch' ), 'seo', 'https://facebook.com/…', 'cm_seo_org' );
		$this->add_text( 'seo_instagram', __( 'Instagram URL', 'corpmerch' ), 'seo', 'https://instagram.com/…', 'cm_seo_org' );
		$this->add_text( 'seo_x', __( 'X (Twitter) URL', 'corpmerch' ), 'seo', 'https://x.com/…', 'cm_seo_org' );
		$this->add_text( 'seo_youtube', __( 'YouTube URL', 'corpmerch' ), 'seo', 'https://youtube.com/@…', 'cm_seo_org' );
		$this->add_text( 'seo_tiktok', __( 'TikTok URL', 'corpmerch' ), 'seo', 'https://tiktok.com/@…', 'cm_seo_org' );
		$this->add_text( 'seo_linkedin', __( 'LinkedIn URL', 'corpmerch' ), 'seo', 'https://linkedin.com/company/…', 'cm_seo_org' );

		// ── Product Feed (Google Merchant) ──
		$feed_url = class_exists( 'Corpmerch_Feed' ) ? Corpmerch_Feed::url() : home_url( '/product-feed.xml' );
		$this->section(
			'cm_feed',
			__( 'Google Merchant Feed', 'corpmerch' ),
			'seo',
			sprintf(
				/* translators: %s: feed URL */
				__( 'Generates a Google Shopping RSS-XML feed at %s — add that URL as a scheduled fetch in Google Merchant Center. Set each product\'s GTIN (WC inventory tab), brand taxonomy and _mpn meta for full approval.', 'corpmerch' ),
				esc_url( $feed_url )
			)
		);
		$this->add_checkbox( 'feed_enable', __( 'Enable product feed', 'corpmerch' ), 'seo', 'cm_feed' );
		$this->add_select(
			'feed_condition',
			__( 'Default condition', 'corpmerch' ),
			'seo',
			array(
				'new'         => __( 'New', 'corpmerch' ),
				'refurbished' => __( 'Refurbished', 'corpmerch' ),
				'used'        => __( 'Used', 'corpmerch' ),
			),
			'new',
			'cm_feed'
		);
		$this->add_text( 'feed_brand_fallback', __( 'Fallback brand', 'corpmerch' ), 'seo', get_bloginfo( 'name' ), 'cm_feed' );
		$this->add_text( 'feed_ship_country', __( 'Shipping country (ISO 2-letter)', 'corpmerch' ), 'seo', 'ZA', 'cm_feed' );
		$this->add_text( 'feed_ship_price', __( 'Flat shipping price', 'corpmerch' ), 'seo', '0.00', 'cm_feed' );
		$this->add_text( 'feed_ship_service', __( 'Shipping service name (optional)', 'corpmerch' ), 'seo', __( 'Standard', 'corpmerch' ), 'cm_feed' );

		// ── Categories (taxonomy importer) ──
		$this->section(
			'cm_taxonomy',
			__( 'Shop Categories', 'corpmerch' ),
			'seo',
			__( 'Create or refresh the corporate-merchandise category tree (Apparel, Bags, Headwear, …) used by the menu and by product auto-filing. Idempotent: existing categories are kept, only missing ones are added. Run this once before your first feed import.', 'corpmerch' )
		);
		add_settings_field( 'taxonomy_tools', __( 'Actions', 'corpmerch' ), array( $this, 'field_taxonomy_tools' ), self::SLUG . '_seo', 'cm_taxonomy' );

		// ── Floating & Widgets tab ──
		$this->section( 'cm_whatsapp', __( 'WhatsApp Button', 'corpmerch' ), 'widgets', __( 'A floating WhatsApp chat button. Pick a side and nudge it into place with the offsets.', 'corpmerch' ) );
		$this->add_checkbox( 'wa_enable', __( 'Show WhatsApp button', 'corpmerch' ), 'widgets', 'cm_whatsapp' );
		$this->add_text( 'wa_number', __( 'WhatsApp number', 'corpmerch' ), 'widgets', __( 'International format, digits only e.g. 27821234567', 'corpmerch' ), 'cm_whatsapp' );
		$this->add_text( 'wa_message', __( 'Prefilled message', 'corpmerch' ), 'widgets', __( 'Hi! I have a question about…', 'corpmerch' ), 'cm_whatsapp' );
		$this->add_text( 'wa_label', __( 'Tooltip / accessible label', 'corpmerch' ), 'widgets', __( 'Chat with us on WhatsApp', 'corpmerch' ), 'cm_whatsapp' );
		$this->add_select(
			'wa_position',
			__( 'Side', 'corpmerch' ),
			'widgets',
			array(
				'right' => __( 'Right', 'corpmerch' ),
				'left'  => __( 'Left', 'corpmerch' ),
			),
			'right',
			'cm_whatsapp'
		);
		$this->add_text( 'wa_offset_side', __( 'Side offset (px)', 'corpmerch' ), 'widgets', '20', 'cm_whatsapp' );
		$this->add_text( 'wa_offset_bottom', __( 'Bottom offset (px)', 'corpmerch' ), 'widgets', '20', 'cm_whatsapp' );

		$this->section( 'cm_backtop', __( 'Back to Top', 'corpmerch' ), 'widgets' );
		$this->add_checkbox( 'backtop_enable', __( 'Show back-to-top button', 'corpmerch' ), 'widgets', 'cm_backtop' );

		// ── Footer & Policies tab ──
		$this->section( 'cm_footer_pricing', __( 'Honest-Pricing Statement', 'corpmerch' ), 'footer', __( 'Corpmerch\'s core promise. The headline line always shows in the footer — this is locked brand positioning. The sub-line is optional extra wording.', 'corpmerch' ) );
		$this->add_text( 'footer_pricing_line', __( 'Honest-pricing headline', 'corpmerch' ), 'footer', __( 'The price you see is the price you pay.', 'corpmerch' ), 'cm_footer_pricing' );
		$this->add_checkbox( 'footer_pricing_sub_on', __( 'Show sub-line under the headline', 'corpmerch' ), 'footer', 'cm_footer_pricing' );
		$this->add_text( 'footer_pricing_sub', __( 'Sub-line text', 'corpmerch' ), 'footer', __( 'No fake discounts, no inflated "was" prices, no countdown gimmicks.', 'corpmerch' ), 'cm_footer_pricing' );

		$this->section( 'cm_footer_policies', __( 'Policy Page Links', 'corpmerch' ), 'footer', __( 'Link each footer item to the matching WordPress Page. Leave blank to hide that link. Google Merchant Center requires these pages to actually exist and be linked — create them under Pages first (draft content provided).', 'corpmerch' ) );
		$this->add_text( 'footer_url_returns', __( 'Returns / Refund Policy URL', 'corpmerch' ), 'footer', 'https://yourstore.co.za/returns-refund-policy/', 'cm_footer_policies' );
		$this->add_text( 'footer_url_shipping', __( 'Shipping Policy URL', 'corpmerch' ), 'footer', 'https://yourstore.co.za/shipping-policy/', 'cm_footer_policies' );
		$this->add_text( 'footer_url_terms', __( 'Terms & Conditions URL', 'corpmerch' ), 'footer', 'https://yourstore.co.za/terms-conditions/', 'cm_footer_policies' );
		$this->add_text( 'footer_url_privacy', __( 'Privacy Policy URL', 'corpmerch' ), 'footer', 'https://yourstore.co.za/privacy-policy/', 'cm_footer_policies' );

		$this->section( 'cm_footer_autopages', __( 'Auto-Create Policy Pages', 'corpmerch' ), 'footer', __( 'One click creates the four policy pages and fills the URL fields above. Required for Google Merchant approval — the footer only links pages that actually exist.', 'corpmerch' ) );
		add_settings_field( 'footer_autopages', __( 'Generate pages', 'corpmerch' ), array( $this, 'field_policy_tools' ), self::SLUG . '_footer', 'cm_footer_autopages' );

		$this->section( 'cm_footer_contact', __( 'Business Contact', 'corpmerch' ), 'footer', __( 'Shown in the footer and used for trust / Merchant compliance. Fill these with your registered business details.', 'corpmerch' ) );
		$this->add_text( 'footer_biz_name', __( 'Registered business name', 'corpmerch' ), 'footer', __( 'Corpmerch (Pty) Ltd', 'corpmerch' ), 'cm_footer_contact' );
		$this->add_textarea( 'footer_biz_address', __( 'Physical address', 'corpmerch' ), 'footer', __( "123 Example Street\nSuburb, City, 0000\nSouth Africa", 'corpmerch' ), 'cm_footer_contact', 3 );
		$this->add_text( 'footer_biz_email', __( 'Support email', 'corpmerch' ), 'footer', 'support@yourstore.co.za', 'cm_footer_contact' );
		$this->add_text( 'footer_biz_phone', __( 'Support phone', 'corpmerch' ), 'footer', '0800 000 000', 'cm_footer_contact' );
		$this->add_text( 'footer_biz_reg', __( 'Company / VAT reg no. (optional)', 'corpmerch' ), 'footer', __( 'Reg 0000/000000/00', 'corpmerch' ), 'cm_footer_contact' );
		$this->add_image( 'footer_payment_image', __( 'Payment methods image', 'corpmerch' ), 'footer', __( 'Strip of accepted-payment logos shown in the footer (e.g. Visa, Mastercard). Wide PNG/SVG with transparent background, ~400×40px.', 'corpmerch' ), 'cm_footer_contact' );

		// ── Business Information tab ──
		$this->section( 'cm_biz_identity', __( 'Company Identity', 'corpmerch' ), 'business', __( 'Fill these once. Your policy pages (Returns, Shipping, Privacy, Terms, Contact, Help, Track Order) are auto-created and read live from these fields — edit here and every page updates instantly.', 'corpmerch' ) );
		$this->add_text( 'biz_name', __( 'Registered business name', 'corpmerch' ), 'business', __( 'Corpmerch (Pty) Ltd', 'corpmerch' ), 'cm_biz_identity' );
		$this->add_text( 'biz_reg', __( 'Company registration number', 'corpmerch' ), 'business', __( '0000/000000/00', 'corpmerch' ), 'cm_biz_identity' );

		$this->section( 'cm_biz_vat', __( 'VAT', 'corpmerch' ), 'business', __( 'Tick this only once you are VAT-registered and have a VAT number. While unticked, no VAT wording appears anywhere on the site or policy pages.', 'corpmerch' ) );
		$this->add_checkbox( 'biz_vat_enabled', __( 'We are VAT-registered', 'corpmerch' ), 'business', 'cm_biz_vat' );
		$this->add_text( 'biz_vat_number', __( 'VAT number', 'corpmerch' ), 'business', __( '4000000000', 'corpmerch' ), 'cm_biz_vat' );

		$this->section( 'cm_biz_contact', __( 'Contact Details', 'corpmerch' ), 'business', __( 'Shown on the Contact, Help and policy pages and in the footer.', 'corpmerch' ) );
		$this->add_textarea( 'biz_address', __( 'Physical / registered address', 'corpmerch' ), 'business', __( "123 Example Street\nSuburb, City, 0000\nSouth Africa", 'corpmerch' ), 'cm_biz_contact', 3 );
		$this->add_text( 'biz_email', __( 'Support email', 'corpmerch' ), 'business', 'support@corpmerch.co.za', 'cm_biz_contact' );
		$this->add_text( 'biz_phone', __( 'Support phone', 'corpmerch' ), 'business', '0800 000 000', 'cm_biz_contact' );
		$this->add_text( 'biz_hours', __( 'Support hours', 'corpmerch' ), 'business', __( 'Mon–Fri, 08:00–17:00 SAST', 'corpmerch' ), 'cm_biz_contact' );
		$this->add_text( 'biz_response_time', __( 'Email response time', 'corpmerch' ), 'business', __( 'within 1 business day', 'corpmerch' ), 'cm_biz_contact' );
		$this->add_text( 'biz_info_officer', __( 'POPIA Information Officer (name)', 'corpmerch' ), 'business', __( 'e.g. the business owner / managing director', 'corpmerch' ), 'cm_biz_contact' );

		$this->section( 'cm_biz_ops', __( 'Operations', 'corpmerch' ), 'business', __( 'These feed the Shipping and Returns pages so the wording matches how you actually operate.', 'corpmerch' ) );
		$this->add_text( 'biz_delivery_areas', __( 'Where you deliver', 'corpmerch' ), 'business', __( 'addresses across South Africa', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_delivery_partner', __( 'Delivery / courier partner', 'corpmerch' ), 'business', __( 'our courier partner', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_processing_time', __( 'Order processing time', 'corpmerch' ), 'business', __( '1–2 business days', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_lead_time', __( 'Delivery lead time (after dispatch)', 'corpmerch' ), 'business', __( '2–4 business days', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_freeship_threshold', __( 'Free-shipping threshold', 'corpmerch' ), 'business', __( 'R1000', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_return_window', __( 'Returns window', 'corpmerch' ), 'business', __( '14 days', 'corpmerch' ), 'cm_biz_ops' );
		$this->add_text( 'biz_refund_time', __( 'Refund processing time', 'corpmerch' ), 'business', __( '5–10 business days', 'corpmerch' ), 'cm_biz_ops' );

		$this->section( 'cm_biz_pages', __( 'Policy Pages', 'corpmerch' ), 'business', __( 'Pages are created and published automatically. Use this to re-check or force re-creation if one was deleted.', 'corpmerch' ) );
		add_settings_field( 'biz_pages_tools', __( 'Status', 'corpmerch' ), array( $this, 'field_policy_tools' ), self::SLUG . '_business', 'cm_biz_pages' );

		// ── Kevro Feed tab ──
		$this->section( 'cm_kevro_creds', __( 'API Credentials', 'corpmerch' ), 'kevro', __( 'From the Kevro onboarding email. Secrets are stored in the database, never in the theme files. Leave a password blank to keep the saved value.', 'corpmerch' ) );
		$this->add_text( 'kevro_entity_id', __( 'Entity ID', 'corpmerch' ), 'kevro', '45780', 'cm_kevro_creds' );
		$this->add_text( 'kevro_entity_name', __( 'Entity name', 'corpmerch' ), 'kevro', 'Customer-AITAM-45780', 'cm_kevro_creds' );
		$this->add_text( 'kevro_ws_user', __( 'Web-service username', 'corpmerch' ), 'kevro', 'AITAM', 'cm_kevro_creds' );
		$this->add_password( 'kevro_ws_pass', __( 'Web-service password', 'corpmerch' ), 'kevro', __( 'paste from email', 'corpmerch' ), 'cm_kevro_creds' );
		$this->add_password( 'kevro_token', __( 'Token key', 'corpmerch' ), 'kevro', __( 'paste from email', 'corpmerch' ), 'cm_kevro_creds' );
		$this->add_text( 'kevro_basic_user', __( 'HTTPS (Basic) username', 'corpmerch' ), 'kevro', 'stkusr', 'cm_kevro_creds' );
		$this->add_password( 'kevro_basic_pass', __( 'HTTPS (Basic) password', 'corpmerch' ), 'kevro', __( 'paste from email', 'corpmerch' ), 'cm_kevro_creds' );
		$this->add_select( 'kevro_return_type', __( 'Return type', 'corpmerch' ), 'kevro', array( 'JSON' => 'JSON', 'XML' => 'XML' ), 'JSON', 'cm_kevro_creds' );

		$this->section( 'cm_kevro_opts', __( 'Import Options', 'corpmerch' ), 'kevro', __( 'Rows are grouped by StockHeaderID into variable products (Colour/Size variations). Quantities from the feed become WooCommerce stock.', 'corpmerch' ) );
		$this->add_select( 'kevro_status', __( 'Imported product status', 'corpmerch' ), 'kevro', array( 'draft' => __( 'Draft (review first)', 'corpmerch' ), 'publish' => __( 'Publish live', 'corpmerch' ) ), 'draft', 'cm_kevro_opts' );
		$this->add_checkbox( 'kevro_import_images', __( 'Download product images', 'corpmerch' ), 'kevro', 'cm_kevro_opts' );
		$this->add_checkbox( 'kevro_manage_stock', __( 'Manage stock from feed quantities', 'corpmerch' ), 'kevro', 'cm_kevro_opts' );
		$this->add_checkbox( 'kevro_oos_draft', __( 'Set out-of-stock products to Draft (auto-republish when restocked)', 'corpmerch' ), 'kevro', 'cm_kevro_opts' );
		$this->add_text( 'kevro_qty_field', __( 'Quantity field name (optional override)', 'corpmerch' ), 'kevro', __( 'auto-detected (Quantity, Qty, SOH…)', 'corpmerch' ), 'cm_kevro_opts' );
		$this->add_text( 'kevro_markup', __( 'Price markup %', 'corpmerch' ), 'kevro', '0', 'cm_kevro_opts' );
		$this->add_text( 'kevro_branding_markup', __( 'Branding price markup %', 'corpmerch' ), 'kevro', '30', 'cm_kevro_opts' );
		$this->add_checkbox( 'kevro_skip_zero_qty', __( 'Skip variants with zero stock', 'corpmerch' ), 'kevro', 'cm_kevro_opts' );
		$this->add_text( 'kevro_colorstatus_blocklist', __( 'ColorStatus blocklist (comma-separated)', 'corpmerch' ), 'kevro', __( 'e.g. Discontinued, Clearance', 'corpmerch' ), 'cm_kevro_opts' );
		$this->add_text( 'kevro_batch', __( 'Products per import batch', 'corpmerch' ), 'kevro', '15', 'cm_kevro_opts' );
		$this->add_select( 'kevro_schedule', __( 'Auto-sync schedule', 'corpmerch' ), 'kevro', array( 'off' => __( 'Off (manual only)', 'corpmerch' ), 'hourly' => __( 'Hourly', 'corpmerch' ), 'twicedaily' => __( 'Twice daily', 'corpmerch' ), 'daily' => __( 'Daily', 'corpmerch' ) ), 'off', 'cm_kevro_opts' );
		$this->add_checkbox( 'kevro_prune', __( 'Remove products no longer in the feed (after each import)', 'corpmerch' ), 'kevro', 'cm_kevro_opts' );
		$this->add_select( 'kevro_prune_action', __( 'Removal method', 'corpmerch' ), 'kevro', array( 'trash' => __( 'Move to Trash (recoverable)', 'corpmerch' ), 'draft' => __( 'Set to Draft', 'corpmerch' ) ), 'trash', 'cm_kevro_opts' );

		$this->section( 'cm_kevro_tools', __( 'Connection & Import', 'corpmerch' ), 'kevro', __( 'Save credentials first, then test, preview, and run the import.', 'corpmerch' ) );
		add_settings_field( 'kevro_tools', __( 'Actions', 'corpmerch' ), array( $this, 'field_kevro_tools' ), self::SLUG . '_kevro', 'cm_kevro_tools' );
	}

	/* Section helper — each feature gets its own titled heading. */
	private function section( $id, $title, $tab, $desc = '' ) {
		if ( '' !== $desc ) {
			$this->section_desc[ $id ] = $desc;
		}
		add_settings_section( $id, $title, array( $this, 'render_section_desc' ), self::SLUG . '_' . $tab );
	}
	public function render_section_desc( $args ) {
		$id = isset( $args['id'] ) ? $args['id'] : '';
		if ( isset( $this->section_desc[ $id ] ) ) {
			echo '<p class="description">' . esc_html( $this->section_desc[ $id ] ) . '</p>';
		}
	}

	/* Field registration helpers. */
	private function add_text( $key, $label, $tab, $ph = '', $section = '' ) {
		add_settings_field( $key, $label, array( $this, 'field_text' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'ph' => $ph ) );
	}
	private function add_checkbox( $key, $label, $tab, $section = '' ) {
		add_settings_field( $key, $label, array( $this, 'field_checkbox' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key ) );
	}
	private function add_color( $key, $label, $tab, $default = '', $section = '' ) {
		add_settings_field( $key, $label, array( $this, 'field_color' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'default' => $default ) );
	}
	private function add_image( $key, $label, $tab, $hint = '', $section = '' ) {
		add_settings_field( $key, $label, array( $this, 'field_image' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'hint' => $hint ) );
	}
	private function add_select( $key, $label, $tab, $choices, $default = '', $section = '' ) {
		$this->selects[ $key ] = array_keys( $choices );
		$this->select_defaults[ $key ] = ( '' !== $default ) ? $default : ( array_key_first( $choices ) ?? '' );
		add_settings_field( $key, $label, array( $this, 'field_select' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'choices' => $choices, 'default' => $default ) );
	}
	private function add_textarea( $key, $label, $tab, $ph = '', $section = '', $rows = 6 ) {
		add_settings_field( $key, $label, array( $this, 'field_textarea' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'ph' => $ph, 'rows' => $rows ) );
	}
	private function add_password( $key, $label, $tab, $ph = '', $section = '' ) {
		add_settings_field( $key, $label, array( $this, 'field_password' ), self::SLUG . '_' . $tab, $section ? $section : 'cm_' . $tab, array( 'key' => $key, 'ph' => $ph ) );
	}
	private function add_repeater( $key, $label, $tab, $fields, $desc = '', $section = '' ) {
		$this->repeaters[ $key ] = $fields;
		add_settings_field(
			$key,
			$label,
			array( $this, 'field_repeater' ),
			self::SLUG . '_' . $tab,
			$section ? $section : 'cm_' . $tab,
			array( 'key' => $key, 'fields' => $fields, 'desc' => $desc )
		);
	}

	/* Value + name helpers. */
	private function val( $key, $default = '' ) {
		$o = get_option( self::OPTION_KEY, array() );
		return isset( $o[ $key ] ) ? $o[ $key ] : $default;
	}
	private function name( $key ) {
		return esc_attr( self::OPTION_KEY . '[' . $key . ']' );
	}

	/* Field renderers. */
	public function field_text( $a ) {
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
			$this->name( $a['key'] ),
			esc_attr( $this->val( $a['key'] ) ),
			esc_attr( $a['ph'] )
		);
	}

	public function field_textarea( $a ) {
		$rows = isset( $a['rows'] ) ? (int) $a['rows'] : 6;
		printf(
			'<textarea name="%s" rows="%d" class="large-text code" placeholder="%s">%s</textarea>',
			$this->name( $a['key'] ),
			$rows,
			esc_attr( isset( $a['ph'] ) ? $a['ph'] : '' ),
			esc_textarea( $this->val( $a['key'] ) )
		);
	}

	public function field_password( $a ) {
		$has = '' !== $this->val( $a['key'] );
		printf(
			'<input type="password" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s" />',
			$this->name( $a['key'] ),
			esc_attr( $has ? __( '•••••••• saved — blank keeps it', 'corpmerch' ) : $a['ph'] )
		);
	}

	public function field_policy_tools() {
		if ( class_exists( 'Corpmerch_Policy_Pages' ) ) {
			$pp = new Corpmerch_Policy_Pages();
			$pp->render_button();
		} else {
			echo '<p class="description">' . esc_html__( 'Policy-page generator unavailable.', 'corpmerch' ) . '</p>';
		}
	}

	public function field_taxonomy_tools() {
		// Current term counts for context.
		$top = 0;
		$total = 0;
		if ( taxonomy_exists( 'product_cat' ) ) {
			$total = (int) wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$tops  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'fields' => 'ids' ) );
			$top   = is_wp_error( $tops ) ? 0 : count( $tops );
		}
		$import_url = wp_nonce_url(
			admin_url( '?corpmerch_import_taxonomy=1' ),
			'corpmerch_import_taxonomy'
		);
		?>
		<div id="cm-taxonomy-tools">
			<p>
				<a href="<?php echo esc_url( $import_url ); ?>" class="button button-primary"><?php esc_html_e( 'Import / refresh categories', 'corpmerch' ); ?></a>
			</p>
			<p class="description" style="margin-top:4px;">
				<?php
				printf(
					/* translators: 1: top-level count, 2: total count */
					esc_html__( 'Currently %1$d top-level categories, %2$d total. Clicking the button creates any missing categories from the built-in corporate-merchandise taxonomy and never deletes existing ones.', 'corpmerch' ),
					(int) $top,
					(int) $total
				);
				?>
			</p>
		</div>
		<?php
	}

	public function field_kevro_tools() {
		$log = get_option( 'cm_kevro_last_run', array() );
		$nonce = wp_create_nonce( 'cm_kevro' );
		?>
		<div id="sia-kevro-tools" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<button type="button" class="button" id="sia-k-test"><?php esc_html_e( 'Test connection', 'corpmerch' ); ?></button>
				<button type="button" class="button" id="sia-k-preview"><?php esc_html_e( 'Fetch &amp; preview', 'corpmerch' ); ?></button>
				<button type="button" class="button button-primary" id="sia-k-import"><?php esc_html_e( 'Run import now', 'corpmerch' ); ?></button>
				<button type="button" class="button" id="sia-k-stop" style="display:none;"><?php esc_html_e( 'Stop import', 'corpmerch' ); ?></button>
				<button type="button" class="button" id="sia-k-retryimg" style="display:none;"><?php esc_html_e( 'Retry failed images', 'corpmerch' ); ?></button>
				<button type="button" class="button button-link-delete" id="sia-k-purge" style="color:#b32d2e;"><?php esc_html_e( 'Remove all imported products', 'corpmerch' ); ?></button>
			</p>
			<div id="sia-k-status" style="margin:8px 0;font-weight:600;"></div>
			<div id="sia-k-bar" style="display:none;height:18px;background:#e0e0e0;border-radius:9px;overflow:hidden;max-width:480px;"><div id="sia-k-fill" style="height:100%;width:0;background:#2271b1;transition:width .3s;"></div></div>
			<div id="sia-k-counts" style="display:none;margin:8px 0;font-size:13px;color:#1d2327;"></div>
			<div id="sia-k-imgcounts" style="display:none;margin:8px 0;font-size:13px;color:#1d2327;"></div>
			<p class="description" style="margin-top:4px;"><?php esc_html_e( 'The import runs on the server in the background — you can safely leave this page. Re-open it any time to watch live progress (refreshes every 10s). For guaranteed pace when nobody is on the site, configure a real server cron for WP-Cron.', 'corpmerch' ); ?></p>
			<div id="sia-k-recent" style="display:none;margin:8px 0;max-width:680px;font-size:12px;"></div>
			<div id="sia-k-errlog" style="display:none;margin:8px 0;max-width:680px;font-size:12px;"></div>
			<pre id="sia-k-out" style="display:none;max-width:680px;max-height:260px;overflow:auto;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;font-size:12px;"></pre>
			<hr style="margin:18px 0;max-width:680px;">
			<p style="margin:0 0 6px;font-weight:600;"><?php esc_html_e( 'Branding / Features inspector', 'corpmerch' ); ?></p>
			<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Enter a Kevro StockHeaderID to dump the field names returned by the branding/features endpoints. Used to map the schema before display.', 'corpmerch' ); ?></p>
			<p>
				<input type="number" id="sia-k-bh-id" placeholder="StockHeaderID" style="max-width:180px;">
				<button type="button" class="button" id="sia-k-brand"><?php esc_html_e( 'Inspect branding API', 'corpmerch' ); ?></button>
			</p>
			<pre id="sia-k-bout" style="display:none;max-width:680px;max-height:320px;overflow:auto;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;font-size:12px;"></pre>
			<hr style="margin:18px 0;max-width:680px;">
			<p style="margin:0 0 6px;font-weight:600;"><?php esc_html_e( 'Sync branding &amp; features', 'corpmerch' ); ?></p>
			<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Fetches Product Features and branding positions for every imported product and caches them. Runs in the background; safe to leave the page. Run once after import, or whenever branding data changes.', 'corpmerch' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="sia-k-bsync"><?php esc_html_e( 'Sync branding for all products', 'corpmerch' ); ?></button>
				<label style="margin-left:12px;"><input type="checkbox" id="sia-k-bforce" /> <?php esc_html_e( 'Force re-sync (ignore 30-day freshness)', 'corpmerch' ); ?></label>
			</p>
			<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Normally, products checked within the last 30 days are skipped for a fast run. Tick Force re-sync to re-fetch every product.', 'corpmerch' ); ?></p>
			<div id="sia-k-bsync-status" style="margin:6px 0;font-weight:600;"></div>
			<?php if ( ! empty( $log['time'] ) ) : ?>
				<p class="description"><?php
					printf(
						/* translators: 1: count, 2: date */
						esc_html__( 'Last run: %1$d products on %2$s', 'corpmerch' ),
						isset( $log['count'] ) ? (int) $log['count'] : 0,
						esc_html( date_i18n( 'Y-m-d H:i', (int) $log['time'] ) )
					);
				?></p>
			<?php endif; ?>
		</div>
		<script>
		jQuery(function($){
			var box=$('#sia-kevro-tools'), nonce=box.data('nonce'), ajax=ajaxurl, poll=null;
			function status(m,c){ $('#sia-k-status').css('color',c||'#1d2327').text(m); }
			function out(o){ $('#sia-k-out').show().text(typeof o==='string'?o:JSON.stringify(o,null,2)); }
			function busy(b){ $('#sia-k-test,#sia-k-preview,#sia-k-import,#sia-k-purge').prop('disabled',b); }
			function esc(s){ return $('<div>').text(s||'').html(); }
			function running(on){ $('#sia-k-stop').toggle(on); $('#sia-k-import').prop('disabled',on); $('#sia-k-bar').toggle(on||$('#sia-k-fill').width()>0); }
			function render(d){
				var pct = d.total ? Math.round(d.offset/d.total*100) : 0;
				$('#sia-k-bar').show(); $('#sia-k-fill').css('width',pct+'%');
				$('#sia-k-counts').show().html(
					'<strong>'+d.offset+' / '+d.total+'</strong> ('+pct+'%) &nbsp;•&nbsp; '+
					'New: '+(d.created||0)+' &nbsp; Re-synced (full): '+(d.refreshed||0)+' &nbsp; '+
					'Price/stock only: '+(d.updated||0)+(d.errors?(' &nbsp; <span style="color:#b32d2e;">Errors: '+d.errors+'</span>'):''));
				// Image download tracking.
				var iexp=d.img_expected||0, iok=d.img_ok||0, ifail=d.img_failed||0, ipend=d.img_failed_pending||0;
				if(iexp||ipend){
					$('#sia-k-imgcounts').show().html(
						'<strong>Images:</strong> '+iexp+' expected &nbsp; '+
						'<span style="color:#1a7f37;">'+iok+' downloaded</span> &nbsp; '+
						(ifail?('<span style="color:#b32d2e;">'+ifail+' failed this run</span> &nbsp; '):'')+
						(ipend?('<span style="color:#b32d2e;">'+ipend+' awaiting retry</span>'):'<span style="color:#1a7f37;">all images present</span>'));
				}
				$('#sia-k-retryimg').toggle(ipend>0);
				if(d.recent && d.recent.length){
					var rows=d.recent.map(function(r){
						var tag={created:'NEW',refreshed:'FULL',updated:'PRICE/QTY',skipped:'SKIP'}[r.mode]||r.mode;
						var col={created:'#1a7f37',refreshed:'#2271b1',updated:'#996800',skipped:'#888'}[r.mode]||'#555';
						return '<div style="padding:2px 0;border-bottom:1px solid #eee;"><span style="display:inline-block;min-width:74px;font-weight:600;color:'+col+';">'+tag+'</span> '+esc(r.name)+'</div>';
					}).join('');
					$('#sia-k-recent').show().html('<strong>Recently synced:</strong>'+rows);
				}
				if(d.error_log && d.error_log.length){
					var erows=d.error_log.map(function(e){
						var loc=e.where?(' <span style="color:#888;">['+esc(e.where)+']</span>'):'';
						var hid=e.header_id?(' <span style="color:#888;">#'+esc(e.header_id)+'</span>'):'';
						return '<div style="padding:3px 0;border-bottom:1px solid #f0d6d6;"><span style="color:#b32d2e;font-weight:600;">'+esc(e.name||'(unnamed)')+'</span>'+hid+'<br><span style="color:#b32d2e;">'+esc(e.message||'Unknown error')+'</span>'+loc+'</div>';
					}).join('');
					$('#sia-k-errlog').show().html('<strong style="color:#b32d2e;">Errors ('+d.error_log.length+'):</strong>'+erows);
				} else {
					$('#sia-k-errlog').hide();
				}
			}
			function stopPoll(){ if(poll){clearInterval(poll);poll=null;} }
			function tick(){
				$.post(ajax,{action:'cm_kevro_status',nonce:nonce}).done(function(r){
					if(!r.success)return;
					var d=r.data; render(d);
					if(d.status==='running'){ running(true); status('Importing in background… '+d.message,'#2271b1'); }
					else if(d.status==='done'){ running(false); stopPoll(); var m='✓ '+d.message; if(d.pruned){m+=' Removed '+d.pruned+' no longer in feed.';} m+=' Review under Corpmerch → All Products.'; status(m,'#1a7f37'); }
					else if(d.status==='error'){ running(false); stopPoll(); status('✕ '+d.message,'#b32d2e'); }
					else { running(false); stopPoll(); }
				});
			}
			function startPoll(){ stopPoll(); tick(); poll=setInterval(tick,10000); }
			$('#sia-k-test').on('click',function(){ busy(true); status('Testing…'); $('#sia-k-out').hide();
				$.post(ajax,{action:'cm_kevro_test',nonce:nonce}).done(function(r){
					status(r.success?('✓ '+r.data.msg):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(function(x){var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e');}).always(function(){busy(false);});
			});
			$('#sia-k-preview').on('click',function(){ busy(true); status('Fetching feed…'); $('#sia-k-out').hide();
				$.post(ajax,{action:'cm_kevro_preview',nonce:nonce}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');return;}
					status('✓ '+r.data.rows+' rows → '+r.data.products+' products. Quantity field detected: '+(r.data.qty_ok?'YES':'NO'),'#1a7f37');
					out(r.data.debug ? {fields:r.data.fields, sample:r.data.sample, debug:r.data.debug} : {fields:r.data.fields, sample:r.data.sample});
				}).fail(function(x){var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e');}).always(function(){busy(false);});
			});
			$('#sia-k-import').on('click',function(){
				if(!confirm('Start the Kevro import in the background? Products import as set on this tab.'))return;
				busy(true); status('Preparing feed…'); $('#sia-k-out').hide();
				$.post(ajax,{action:'cm_kevro_start',nonce:nonce}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');busy(false);return;}
					status('✓ Queued '+r.data.total+' products. Running in background…','#2271b1'); startPoll();
				}).fail(function(x){var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e');busy(false);});
			});
			$('#sia-k-stop').on('click',function(){
				if(!confirm('Stop the running import? Already-synced products stay; you can start again later.'))return;
				$.post(ajax,{action:'cm_kevro_stop',nonce:nonce}).done(function(){ stopPoll(); running(false); status('Import stopped.','#996800'); });
			});
			$('#sia-k-purge').on('click',function(){
				if(!confirm('Move ALL Kevro-imported products to Trash? You can restore them from the Trash.'))return;
				busy(true); status('Removing…'); $('#sia-k-out').hide();
				$.post(ajax,{action:'cm_kevro_purge',nonce:nonce}).done(function(r){
					status(r.success?('✓ Moved '+r.data.count+' products to Trash.'):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(function(x){var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e');}).always(function(){busy(false);});
			});
			$('#sia-k-retryimg').on('click',function(){
				var b=$(this); b.prop('disabled',true); status('Retrying failed images…','#2271b1');
				$.post(ajax,{action:'cm_kevro_retry_images',nonce:nonce}).done(function(r){
					if(!r.success){status('✕ '+(r.data&&r.data.msg?r.data.msg:'Failed'),'#b32d2e');return;}
					var d=r.data;
					status('✓ Retried '+d.attempted+' — recovered '+d.recovered+', '+d.remaining+' still failing.', d.remaining?'#996800':'#1a7f37');
					tick();
				}).fail(function(x){var s=x&&x.status?x.status:0;status('✕ Request failed (HTTP '+s+')','#b32d2e');}).always(function(){b.prop('disabled',false);});
			});
			// Re-attach to an import already running when the page loads.
			tick();
			$('#sia-k-brand').on('click',function(){
				var id=parseInt($('#sia-k-bh-id').val(),10);
				if(!id){status('Enter a StockHeaderID first.','#b32d2e');return;}
				var b=$(this); b.prop('disabled',true); status('Inspecting branding API for '+id+'…','#2271b1'); $('#sia-k-bout').hide();
				$.post(ajax,{action:'cm_kevro_branding_inspect',nonce:nonce,header_id:id}).done(function(r){
					if(!r.success){status('✕ '+(r.data&&r.data.msg?r.data.msg:'Failed'),'#b32d2e');return;}
					status('✓ Branding API inspected for '+id,'#1a7f37');
					$('#sia-k-bout').show().text(JSON.stringify(r.data,null,2));
				}).fail(function(x){var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e');}).always(function(){b.prop('disabled',false);});
			});
			var bpoll=null;
			function bstat(m,c){ $('#sia-k-bsync-status').css('color',c||'#1d2327').text(m); }
			function btick(){
				$.post(ajax,{action:'cm_kevro_branding_sync_status',nonce:nonce}).done(function(r){
					if(!r.success)return; var d=r.data;
					if(d.status==='running'){ bstat('Syncing branding… '+(d.message||''),'#2271b1'); }
					else if(d.status==='done'){ if(bpoll){clearInterval(bpoll);bpoll=null;} $('#sia-k-bsync').prop('disabled',false); bstat('✓ '+d.message,'#1a7f37'); }
					else { if(bpoll){clearInterval(bpoll);bpoll=null;} $('#sia-k-bsync').prop('disabled',false); }
				});
			}
			$('#sia-k-bsync').on('click',function(){
				if(!confirm('Sync branding & features for all imported products? This runs in the background.'))return;
				var b=$(this); b.prop('disabled',true); bstat('Starting…','#2271b1');
				var force=$('#sia-k-bforce').is(':checked')?1:0;
				$.post(ajax,{action:'cm_kevro_branding_sync',nonce:nonce,force:force}).done(function(r){
					if(!r.success){bstat('✕ '+(r.data&&r.data.msg?r.data.msg:'Failed'),'#b32d2e');b.prop('disabled',false);return;}
					bstat('Queued '+r.data.total+' products…','#2271b1');
					if(bpoll)clearInterval(bpoll); btick(); bpoll=setInterval(btick,5000);
				}).fail(function(x){var s=x&&x.status?x.status:0;bstat('✕ Request failed (HTTP '+s+')','#b32d2e');b.prop('disabled',false);});
			});
			btick();
		});
		</script>
		<?php
	}

	/** Esquire feed tools: per-org test/preview/import with background polling. */
	public function field_esquire_tools() {
		$log   = get_option( 'cm_esquire_last_run', array() );
		$nonce = wp_create_nonce( 'cm_esquire' );
		?>
		<div id="sia-esq-tools" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<label><strong><?php esc_html_e( 'Feed:', 'corpmerch' ); ?></strong>
				<select id="sia-esq-org">
					<option value="all"><?php esc_html_e( 'All four orgs', 'corpmerch' ); ?></option>
					<option value="esquire">Esquire</option>
					<option value="noble">Noble</option>
					<option value="casey">Casey</option>
					<option value="brainware">Brainware</option>
				</select></label>
			</p>
			<p>
				<button type="button" class="button" id="sia-e-test"><?php esc_html_e( 'Test feeds', 'corpmerch' ); ?></button>
				<button type="button" class="button" id="sia-e-preview"><?php esc_html_e( 'Fetch &amp; preview', 'corpmerch' ); ?></button>
				<button type="button" class="button button-primary" id="sia-e-import"><?php esc_html_e( 'Run import now', 'corpmerch' ); ?></button>
				<button type="button" class="button" id="sia-e-stop" style="display:none;"><?php esc_html_e( 'Stop import', 'corpmerch' ); ?></button>
				<button type="button" class="button button-link-delete" id="sia-e-purge" style="color:#b32d2e;"><?php esc_html_e( 'Remove all imported products', 'corpmerch' ); ?></button>
			</p>
			<div id="sia-e-status" style="margin:8px 0;font-weight:600;"></div>
			<div id="sia-e-bar" style="display:none;height:18px;background:#e0e0e0;border-radius:9px;overflow:hidden;max-width:480px;"><div id="sia-e-fill" style="height:100%;width:0;background:#2271b1;transition:width .3s;"></div></div>
			<div id="sia-e-counts" style="display:none;margin:8px 0;font-size:13px;color:#1d2327;"></div>
			<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Runs on the server in the background — safe to leave this page. Re-open it any time to watch live progress (refreshes every 10s). Stock comes from the MID location only.', 'corpmerch' ); ?></p>
			<div id="sia-e-recent" style="display:none;margin:8px 0;max-width:680px;font-size:12px;"></div>
			<pre id="sia-e-out" style="display:none;max-width:680px;max-height:260px;overflow:auto;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;font-size:12px;"></pre>
			<?php if ( ! empty( $log['time'] ) ) : ?>
				<p class="description"><?php
					printf(
						/* translators: 1: count, 2: date */
						esc_html__( 'Last run: %1$d products on %2$s', 'corpmerch' ),
						isset( $log['count'] ) ? (int) $log['count'] : 0,
						esc_html( date_i18n( 'Y-m-d H:i', (int) $log['time'] ) )
					);
				?></p>
			<?php endif; ?>
		</div>
		<script>
		jQuery(function($){
			var box=$('#sia-esq-tools'), nonce=box.data('nonce'), ajax=ajaxurl, poll=null;
			function org(){ return $('#sia-esq-org').val()||'all'; }
			function status(m,c){ $('#sia-e-status').css('color',c||'#1d2327').text(m); }
			function out(o){ $('#sia-e-out').show().text(typeof o==='string'?o:JSON.stringify(o,null,2)); }
			function busy(b){ $('#sia-e-test,#sia-e-preview,#sia-e-import,#sia-e-purge').prop('disabled',b); }
			function esc(s){ return $('<div>').text(s||'').html(); }
			function running(on){ $('#sia-e-stop').toggle(on); $('#sia-e-import').prop('disabled',on); }
			function render(d){
				var pct=d.total?Math.round(d.offset/d.total*100):0;
				$('#sia-e-bar').show(); $('#sia-e-fill').css('width',pct+'%');
				$('#sia-e-counts').show().html('<strong>'+d.offset+' / '+d.total+'</strong> ('+pct+'%) &nbsp;•&nbsp; New: '+(d.created||0)+' &nbsp; Re-synced (full): '+(d.refreshed||0)+' &nbsp; Price/stock only: '+(d.updated||0)+(d.errors?(' &nbsp; <span style="color:#b32d2e;">Errors: '+d.errors+'</span>'):''));
				if(d.recent&&d.recent.length){
					var rows=d.recent.map(function(r){
						var tag={created:'NEW',refreshed:'FULL',updated:'PRICE/QTY',skipped:'SKIP'}[r.mode]||r.mode;
						var col={created:'#1a7f37',refreshed:'#2271b1',updated:'#996800',skipped:'#888'}[r.mode]||'#555';
						return '<div style="padding:2px 0;border-bottom:1px solid #eee;"><span style="display:inline-block;min-width:74px;font-weight:600;color:'+col+';">'+tag+'</span> '+esc(r.name)+'</div>';
					}).join('');
					$('#sia-e-recent').show().html('<strong>Recently synced:</strong>'+rows);
				}
			}
			function stopPoll(){ if(poll){clearInterval(poll);poll=null;} }
			function tick(){
				$.post(ajax,{action:'cm_esquire_status',nonce:nonce}).done(function(r){
					if(!r.success)return; var d=r.data; render(d);
					if(d.status==='running'){ running(true); status('Importing in background… '+d.message,'#2271b1'); }
					else if(d.status==='done'){ running(false); stopPoll(); var m='✓ '+d.message; if(d.pruned){m+=' Removed '+d.pruned+' no longer in feed.';} m+=' Review under Corpmerch → All Products.'; status(m,'#1a7f37'); }
					else if(d.status==='error'){ running(false); stopPoll(); status('✕ '+d.message,'#b32d2e'); }
					else { running(false); stopPoll(); }
				});
			}
			function startPoll(){ stopPoll(); tick(); poll=setInterval(tick,10000); }
			function fail(x){ var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e'); }
			$('#sia-e-test').on('click',function(){ busy(true); status('Testing feeds…'); $('#sia-e-out').hide();
				$.post(ajax,{action:'cm_esquire_test',nonce:nonce,orgs:org()}).done(function(r){
					status(r.success?('✓ '+r.data.msg):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(fail).always(function(){busy(false);});
			});
			$('#sia-e-preview').on('click',function(){ busy(true); status('Fetching feed…'); $('#sia-e-out').hide();
				$.post(ajax,{action:'cm_esquire_preview',nonce:nonce,orgs:org()}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');return;}
					status('✓ '+r.data.org+': '+r.data.products+' products. MID stock detected: '+(r.data.mid_ok?'YES':'NO'),'#1a7f37');
					out({org:r.data.org,sample:r.data.sample});
				}).fail(fail).always(function(){busy(false);});
			});
			$('#sia-e-import').on('click',function(){
				if(!confirm('Start the Esquire import ('+org()+') in the background?'))return;
				busy(true); status('Preparing feed…'); $('#sia-e-out').hide();
				$.post(ajax,{action:'cm_esquire_start',nonce:nonce,orgs:org()}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');busy(false);return;}
					status('✓ Queued '+r.data.total+' products. Running in background…','#2271b1'); startPoll();
				}).fail(function(x){fail(x);busy(false);});
			});
			$('#sia-e-stop').on('click',function(){
				if(!confirm('Stop the running import? Already-synced products stay.'))return;
				$.post(ajax,{action:'cm_esquire_stop',nonce:nonce}).done(function(){ stopPoll(); running(false); status('Import stopped.','#996800'); });
			});
			$('#sia-e-purge').on('click',function(){
				if(!confirm('Move ALL Esquire-imported products to Trash? You can restore them from the Trash.'))return;
				busy(true); status('Removing…'); $('#sia-e-out').hide();
				$.post(ajax,{action:'cm_esquire_purge',nonce:nonce}).done(function(r){
					status(r.success?('✓ Moved '+r.data.count+' products to Trash.'):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(fail).always(function(){busy(false);});
			});
			tick(); // re-attach to a running import on load.
		});
		</script>
		<?php
	}

	/** Generic XML-feed tools (Scoop / Parrot). $args = array( source, label ). */
	public function field_xmlfeed_tools( $args ) {
		$source = isset( $args['source'] ) ? $args['source'] : '';
		$label  = isset( $args['label'] ) ? $args['label'] : ucfirst( $source );
		$log    = get_option( 'cm_xmlfeed_last_run', array() );
		$nonce  = wp_create_nonce( 'cm_xmlfeed' );
		?>
		<div class="sia-xml-tools" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-source="<?php echo esc_attr( $source ); ?>">
			<p>
				<button type="button" class="button t-test"><?php esc_html_e( 'Test feed', 'corpmerch' ); ?></button>
				<button type="button" class="button t-preview"><?php esc_html_e( 'Fetch &amp; preview', 'corpmerch' ); ?></button>
				<button type="button" class="button button-primary t-import"><?php esc_html_e( 'Run import now', 'corpmerch' ); ?></button>
				<button type="button" class="button t-stop" style="display:none;"><?php esc_html_e( 'Stop import', 'corpmerch' ); ?></button>
				<button type="button" class="button button-link-delete t-purge" style="color:#b32d2e;"><?php printf( esc_html__( 'Remove all %s products', 'corpmerch' ), esc_html( $label ) ); ?></button>
			</p>
			<div class="t-status" style="margin:8px 0;font-weight:600;"></div>
			<div class="t-bar" style="display:none;height:18px;background:#e0e0e0;border-radius:9px;overflow:hidden;max-width:480px;"><div class="t-fill" style="height:100%;width:0;background:#2271b1;transition:width .3s;"></div></div>
			<div class="t-counts" style="display:none;margin:8px 0;font-size:13px;color:#1d2327;"></div>
			<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Runs on the server in the background — safe to leave this page. Re-open it any time to watch live progress (refreshes every 10s). Only one XML feed import runs at a time.', 'corpmerch' ); ?></p>
			<div class="t-recent" style="display:none;margin:8px 0;max-width:680px;font-size:12px;"></div>
			<pre class="t-out" style="display:none;max-width:680px;max-height:260px;overflow:auto;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;font-size:12px;"></pre>
			<?php if ( ! empty( $log['time'] ) ) : ?>
				<p class="description"><?php
					printf(
						/* translators: 1: count, 2: source, 3: date */
						esc_html__( 'Last XML import: %1$d products (%2$s) on %3$s', 'corpmerch' ),
						isset( $log['count'] ) ? (int) $log['count'] : 0,
						esc_html( isset( $log['source'] ) ? $log['source'] : '' ),
						esc_html( date_i18n( 'Y-m-d H:i', (int) $log['time'] ) )
					);
				?></p>
			<?php endif; ?>
		</div>
		<script>
		jQuery(function($){
			var box=$('.sia-xml-tools[data-source="<?php echo esc_js( $source ); ?>"]');
			if(!box.length||box.data('wired'))return; box.data('wired',1);
			var nonce=box.data('nonce'), source=box.data('source'), ajax=ajaxurl, poll=null;
			function $$(s){ return box.find(s); }
			function status(m,c){ $$('.t-status').css('color',c||'#1d2327').text(m); }
			function out(o){ $$('.t-out').show().text(typeof o==='string'?o:JSON.stringify(o,null,2)); }
			function busy(b){ $$('.t-test,.t-preview,.t-import,.t-purge').prop('disabled',b); }
			function esc(s){ return $('<div>').text(s||'').html(); }
			function running(on){ $$('.t-stop').toggle(on); $$('.t-import').prop('disabled',on); }
			function render(d){
				var pct=d.total?Math.round(d.offset/d.total*100):0;
				$$('.t-bar').show(); $$('.t-fill').css('width',pct+'%');
				$$('.t-counts').show().html('<strong>'+d.offset+' / '+d.total+'</strong> ('+pct+'%) &nbsp;•&nbsp; New: '+(d.created||0)+' &nbsp; Re-synced (full): '+(d.refreshed||0)+' &nbsp; Price/stock only: '+(d.updated||0)+(d.errors?(' &nbsp; <span style="color:#b32d2e;">Errors: '+d.errors+'</span>'):''));
				if(d.recent&&d.recent.length){
					var rows=d.recent.map(function(r){
						var tag={created:'NEW',refreshed:'FULL',updated:'PRICE/QTY',skipped:'SKIP'}[r.mode]||r.mode;
						var col={created:'#1a7f37',refreshed:'#2271b1',updated:'#996800',skipped:'#888'}[r.mode]||'#555';
						return '<div style="padding:2px 0;border-bottom:1px solid #eee;"><span style="display:inline-block;min-width:74px;font-weight:600;color:'+col+';">'+tag+'</span> '+esc(r.name)+'</div>';
					}).join('');
					$$('.t-recent').show().html('<strong>Recently synced:</strong>'+rows);
				}
			}
			function stopPoll(){ if(poll){clearInterval(poll);poll=null;} }
			function mine(d){ return d.source===source; }
			function tick(){
				$.post(ajax,{action:'cm_xmlfeed_status',nonce:nonce}).done(function(r){
					if(!r.success)return; var d=r.data;
					if(d.status==='preparing'&&mine(d)){ running(true); status('Preparing feed… '+d.message,'#2271b1'); }
					else if(d.status==='running'&&mine(d)){ render(d); running(true); status('Importing in background… '+d.message,'#2271b1'); }
					else if(d.status==='done'&&mine(d)){ render(d); running(false); stopPoll(); var m='✓ '+d.message; if(d.pruned){m+=' Removed '+d.pruned+' no longer in feed.';} m+=' Review under Corpmerch → All Products.'; status(m,'#1a7f37'); }
					else if(d.status==='error'&&mine(d)){ running(false); stopPoll(); status('✕ '+d.message,'#b32d2e'); }
					else if(d.status==='running'&&!mine(d)){ running(false); status('Another feed ('+d.source+') is importing — '+d.offset+'/'+d.total+'. Wait for it to finish.','#996800'); }
					else { running(false); stopPoll(); }
				});
			}
			function startPoll(){ stopPoll(); tick(); poll=setInterval(tick,10000); }
			function fail(x){ var s=x&&x.status?x.status:0;var h=x&&x.responseText?(' — '+String(x.responseText).replace(/<[^>]+>/g,'').slice(0,160)):'';status('✕ Request failed (HTTP '+s+')'+h,'#b32d2e'); }
			$$('.t-test').on('click',function(){ busy(true); status('Testing feed…'); $$('.t-out').hide();
				$.post(ajax,{action:'cm_xmlfeed_test',nonce:nonce,source:source}).done(function(r){
					status(r.success?('✓ '+r.data.msg):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(fail).always(function(){busy(false);});
			});
			$$('.t-preview').on('click',function(){ busy(true); status('Fetching feed…'); $$('.t-out').hide();
				$.post(ajax,{action:'cm_xmlfeed_preview',nonce:nonce,source:source}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');return;}
					status('✓ '+r.data.products+' products. Stock/price detected: '+(r.data.stock_ok?'YES':'NO'),'#1a7f37');
					out({sample:r.data.sample});
				}).fail(fail).always(function(){busy(false);});
			});
			$$('.t-import').on('click',function(){
				if(!confirm('Start the '+source+' import in the background?'))return;
				busy(true); status('Preparing feed…'); $$('.t-out').hide();
				$.post(ajax,{action:'cm_xmlfeed_start',nonce:nonce,source:source}).done(function(r){
					if(!r.success){status('✕ '+r.data.msg,'#b32d2e');busy(false);return;}
					status('✓ Import started. Preparing feed in background…','#2271b1'); startPoll();
				}).fail(function(x){fail(x);busy(false);});
			});
			$$('.t-stop').on('click',function(){
				if(!confirm('Stop the running import? Already-synced products stay.'))return;
				$.post(ajax,{action:'cm_xmlfeed_stop',nonce:nonce}).done(function(){ stopPoll(); running(false); status('Import stopped.','#996800'); });
			});
			$$('.t-purge').on('click',function(){
				if(!confirm('Move ALL '+source+' products to Trash? You can restore them from the Trash.'))return;
				busy(true); status('Removing…'); $$('.t-out').hide();
				$.post(ajax,{action:'cm_xmlfeed_purge',nonce:nonce,source:source}).done(function(r){
					status(r.success?('✓ Moved '+r.data.count+' products to Trash.'):('✕ '+r.data.msg), r.success?'#1a7f37':'#b32d2e');
				}).fail(fail).always(function(){busy(false);});
			});
			tick(); // re-attach to a running import on load.
		});
		</script>
		<?php
	}

	public function field_checkbox( $a ) {		// Presence marker so saving another tab never wipes this checkbox.
		printf( '<input type="hidden" name="%s[_present][%s]" value="1" />', esc_attr( self::OPTION_KEY ), esc_attr( $a['key'] ) );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			$this->name( $a['key'] ),
			checked( $this->val( $a['key'] ), '1', false ),
			esc_html__( 'Enabled', 'corpmerch' )
		);
	}

	public function field_color( $a ) {
		printf(
			'<input type="text" class="sia-color" name="%s" value="%s" data-default-color="%s" />',
			$this->name( $a['key'] ),
			esc_attr( $this->val( $a['key'], $a['default'] ) ),
			esc_attr( $a['default'] )
		);
	}

	public function field_select( $a ) {
		$cur = $this->val( $a['key'], $a['default'] );
		printf( '<select name="%s">', $this->name( $a['key'] ) );
		foreach ( $a['choices'] as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $cur, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function field_image( $a ) {
		$id  = (int) $this->val( $a['key'] );
		$src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<div class="sia-image-field">
			<img src="<?php echo esc_url( $src ); ?>" class="sia-preview" style="max-width:160px;height:auto;display:<?php echo $src ? 'block' : 'none'; ?>;margin-bottom:6px;" />
			<input type="hidden" name="<?php echo $this->name( $a['key'] ); // phpcs:ignore ?>" value="<?php echo esc_attr( $id ); ?>" class="sia-image-id" />
			<button type="button" class="button sia-image-upload"><?php esc_html_e( 'Select image', 'corpmerch' ); ?></button>
			<button type="button" class="button sia-image-remove" style="<?php echo $id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'corpmerch' ); ?></button>
			<?php if ( $a['hint'] ) : ?><p class="description"><?php echo esc_html( $a['hint'] ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	/* ---- Repeater image sub-field (reuses the delegated media JS). ---- */
	private function rep_image( $okey, $index, $sub, $value, $hint ) {
		$id  = (int) $value;
		$src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		ob_start();
		?>
		<div class="sia-image-field">
			<img src="<?php echo esc_url( $src ); ?>" class="sia-preview" style="max-width:120px;height:auto;display:<?php echo $src ? 'block' : 'none'; ?>;margin-bottom:6px;border-radius:6px;" />
			<input type="hidden" class="sia-image-id" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $okey . '][' . $index . '][' . $sub . ']' ); ?>" value="<?php echo esc_attr( $id ); ?>" />
			<button type="button" class="button button-small sia-image-upload"><?php esc_html_e( 'Select', 'corpmerch' ); ?></button>
			<button type="button" class="button button-small sia-image-remove" style="<?php echo $id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'corpmerch' ); ?></button>
			<?php if ( $hint ) : ?><p class="description" style="margin:4px 0 0;"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---- Generic repeater ---- */
	private function repeater_row( $okey, $index, $fields, $values ) {
		$base = self::OPTION_KEY . '[' . $okey . '][' . $index . ']';
		ob_start();
		?>
		<div class="sia-rep-row">
			<div class="sia-rep-head">
				<span class="sia-rep-drag dashicons dashicons-move" title="<?php esc_attr_e( 'Drag to reorder', 'corpmerch' ); ?>"></span>
				<strong class="sia-rep-title"><?php esc_html_e( 'Item', 'corpmerch' ); ?></strong>
				<button type="button" class="button-link sia-rep-remove"><?php esc_html_e( 'Remove', 'corpmerch' ); ?></button>
			</div>
			<div class="sia-rep-body">
				<?php
				foreach ( $fields as $f ) {
					$v   = isset( $values[ $f['key'] ] ) ? $values[ $f['key'] ] : '';
					$nm  = esc_attr( $base . '[' . $f['key'] . ']' );
					$ph  = isset( $f['ph'] ) ? $f['ph'] : '';
					$lbl = esc_html( $f['label'] );
					switch ( $f['type'] ) {
						case 'image':
							echo '<p><label>' . $lbl . '</label>' . $this->rep_image( $okey, $index, $f['key'], $v, isset( $f['hint'] ) ? $f['hint'] : '' ) . '</p>'; // phpcs:ignore
							break;
						case 'url':
							printf( '<p><label>%s<br><input type="url" class="regular-text" name="%s" value="%s" placeholder="%s" /></label></p>', $lbl, $nm, esc_attr( $v ), esc_attr( $ph ? $ph : 'https://' ) );
							break;
						case 'textarea':
							printf( '<p><label>%s<br><textarea class="large-text" rows="2" name="%s">%s</textarea></label></p>', $lbl, $nm, esc_textarea( $v ) );
							break;
						default:
							printf( '<p><label>%s<br><input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" /></label></p>', $lbl, $nm, esc_attr( $v ), esc_attr( $ph ) );
					}
				}
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function field_repeater( $a ) {
		$rows = $this->val( $a['key'], array() );
		$rows = is_array( $rows ) ? $rows : array();
		?>
		<div class="sia-repeater" data-next="<?php echo esc_attr( count( $rows ) ); ?>">
			<div class="sia-rep-rows">
				<?php
				$i = 0;
				foreach ( $rows as $row ) {
					echo $this->repeater_row( $a['key'], $i, $a['fields'], is_array( $row ) ? $row : array() ); // phpcs:ignore
					$i++;
				}
				?>
			</div>
			<p><button type="button" class="button button-secondary sia-rep-add"><?php esc_html_e( '+ Add item', 'corpmerch' ); ?></button></p>
			<script type="text/html" class="sia-rep-tpl"><?php echo $this->repeater_row( $a['key'], '__i__', $a['fields'], array() ); // phpcs:ignore ?></script>
			<?php if ( ! empty( $a['desc'] ) ) : ?><p class="description"><?php echo esc_html( $a['desc'] ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	/* ---- Homepage section order & visibility ---- */
	private function section_labels() {
		return array(
			'hero'          => __( 'Hero (image banner + category sidebar + side banners)', 'corpmerch' ),
			'category_grid' => __( 'Shop by category tiles', 'corpmerch' ),
			'brand_banners' => __( 'Brand &amp; promo banners', 'corpmerch' ),
			'recent_home'   => __( 'Continue where you left off', 'corpmerch' ),
			'bestsellers'   => __( 'Most popular', 'corpmerch' ),
			'newest'        => __( 'Newly added', 'corpmerch' ),
			'featured'      => __( 'Featured picks', 'corpmerch' ),
			'how_it_works'  => __( 'How it works', 'corpmerch' ),
		);
	}

	/** Section keys in their natural default order (all enabled by default). */
	private static function default_section_keys() {
		return array( 'hero', 'category_grid', 'brand_banners', 'recent_home', 'bestsellers', 'newest', 'featured', 'how_it_works' );
	}

	/**
	 * Ordered list of enabled homepage section keys, honoring the saved
	 * order + on/off. Unsaved or unknown keys fall back to the natural
	 * order, all enabled. Used by front-page.php to render.
	 *
	 * @return string[] Section keys in render order.
	 */
	public static function homepage_section_order() {
		$valid = self::default_section_keys();
		$opts  = get_option( self::OPTION_KEY, array() );
		$saved = ( is_array( $opts ) && isset( $opts['homepage_sections'] ) && is_array( $opts['homepage_sections'] ) )
			? $opts['homepage_sections'] : array();

		$out  = array();
		$seen = array();
		foreach ( $saved as $r ) {
			if ( ! is_array( $r ) || empty( $r['key'] ) ) {
				continue;
			}
			$k = $r['key'];
			if ( in_array( $k, $valid, true ) && ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = 1;
				if ( ! empty( $r['on'] ) ) {
					$out[] = $k;
				}
			}
		}
		// Sections not present in saved data default to on, in natural order.
		foreach ( $valid as $k ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	public function field_homepage_sections() {
		$labels = $this->section_labels();
		$saved  = $this->val( 'homepage_sections', array() );
		$order  = array();
		$seen   = array();

		if ( is_array( $saved ) ) {
			foreach ( $saved as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$k = isset( $r['key'] ) ? $r['key'] : '';
				if ( isset( $labels[ $k ] ) && ! isset( $seen[ $k ] ) ) {
					$order[]      = array( 'key' => $k, 'on' => ! empty( $r['on'] ) );
					$seen[ $k ]   = 1;
				}
			}
		}
		foreach ( $labels as $k => $l ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$order[] = array( 'key' => $k, 'on' => true );
			}
		}

		echo '<ul class="sia-sections-sort">';
		$i = 0;
		foreach ( $order as $o ) {
			printf(
				'<li class="sia-sec-item"><span class="sia-rep-drag dashicons dashicons-move"></span><input type="hidden" name="%1$s[homepage_sections][%2$d][key]" value="%3$s" /><label><input type="checkbox" name="%1$s[homepage_sections][%2$d][on]" value="1" %4$s /> %5$s</label></li>',
				esc_attr( self::OPTION_KEY ),
				$i,
				esc_attr( $o['key'] ),
				checked( $o['on'], true, false ),
				esc_html( $labels[ $o['key'] ] )
			);
			$i++;
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Drag to reorder. Untick to hide a section.', 'corpmerch' ) . '</p>';
	}

	/* ---- Hero slides repeater (custom two-image layout) ---- */
	private function slide_row( $index, $slide ) {
		$slide = wp_parse_args(
			$slide,
			array( 'image' => 0, 'image_mobile' => 0, 'eyebrow' => '', 'title' => '', 'subtitle' => '', 'cta_text' => '', 'cta_link' => '' )
		);
		$base = self::OPTION_KEY . '[hero_slides][' . $index . ']';
		ob_start();
		?>
		<div class="sia-rep-row">
			<div class="sia-rep-head">
				<span class="sia-rep-drag dashicons dashicons-move" title="<?php esc_attr_e( 'Drag to reorder', 'corpmerch' ); ?>"></span>
				<strong class="sia-rep-title"><?php esc_html_e( 'Slide', 'corpmerch' ); ?></strong>
				<button type="button" class="button-link sia-rep-remove"><?php esc_html_e( 'Remove', 'corpmerch' ); ?></button>
			</div>
			<div class="sia-rep-body">
				<div class="sia-rep-images">
					<div><label><?php esc_html_e( 'Desktop image', 'corpmerch' ); ?></label><?php echo $this->rep_image( 'hero_slides', $index, 'image', $slide['image'], __( '1920×800', 'corpmerch' ) ); // phpcs:ignore ?></div>
					<div><label><?php esc_html_e( 'Mobile image', 'corpmerch' ); ?></label><?php echo $this->rep_image( 'hero_slides', $index, 'image_mobile', $slide['image_mobile'], __( '800×1000 (portrait)', 'corpmerch' ) ); // phpcs:ignore ?></div>
				</div>
				<p><label><?php esc_html_e( 'Eyebrow', 'corpmerch' ); ?><br><input type="text" class="regular-text" name="<?php echo esc_attr( $base . '[eyebrow]' ); ?>" value="<?php echo esc_attr( $slide['eyebrow'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Everyday low prices', 'corpmerch' ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Title (H1 on first slide)', 'corpmerch' ); ?><br><input type="text" class="large-text" name="<?php echo esc_attr( $base . '[title]' ); ?>" value="<?php echo esc_attr( $slide['title'] ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Subtitle', 'corpmerch' ); ?><br><textarea class="large-text" rows="2" name="<?php echo esc_attr( $base . '[subtitle]' ); ?>"><?php echo esc_textarea( $slide['subtitle'] ); ?></textarea></label></p>
				<p class="sia-rep-cta">
					<label><?php esc_html_e( 'Button text', 'corpmerch' ); ?><br><input type="text" name="<?php echo esc_attr( $base . '[cta_text]' ); ?>" value="<?php echo esc_attr( $slide['cta_text'] ); ?>" placeholder="<?php esc_attr_e( 'Shop now', 'corpmerch' ); ?>" /></label>
					<label><?php esc_html_e( 'Button link', 'corpmerch' ); ?><br><input type="url" class="regular-text" name="<?php echo esc_attr( $base . '[cta_link]' ); ?>" value="<?php echo esc_attr( $slide['cta_link'] ); ?>" placeholder="https://" /></label>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function field_hero_slides() {
		$slides = $this->val( 'hero_slides', array() );
		$slides = is_array( $slides ) ? $slides : array();
		?>
		<div class="sia-repeater" data-next="<?php echo esc_attr( count( $slides ) ); ?>">
			<div class="sia-rep-rows">
				<?php
				$i = 0;
				foreach ( $slides as $slide ) {
					echo $this->slide_row( $i, is_array( $slide ) ? $slide : array() ); // phpcs:ignore
					$i++;
				}
				?>
			</div>
			<p><button type="button" class="button button-secondary sia-rep-add"><?php esc_html_e( '+ Add slide', 'corpmerch' ); ?></button></p>
			<script type="text/html" class="sia-rep-tpl"><?php echo $this->slide_row( '__i__', array() ); // phpcs:ignore ?></script>
			<p class="description"><?php esc_html_e( 'No slides = the default hero shows. First slide title renders as the page H1.', 'corpmerch' ); ?></p>
		</div>
		<?php
	}

	/* Sanitize — preserves options from tabs not currently on screen. */
	public function sanitize( $input ) {
		$clean = array();
		$prev  = get_option( self::OPTION_KEY, array() );
		$prev  = is_array( $prev ) ? $prev : array();
		$input = is_array( $input ) ? $input : array();

		$present = ( isset( $input['_present'] ) && is_array( $input['_present'] ) ) ? $input['_present'] : array();

		$text_keys  = array( 'announce_text', 'phone', 'deals_heading', 'seo_title_sep', 'seo_home_title', 'seo_org_name', 'seo_twitter', 'feed_brand_fallback', 'feed_ship_country', 'feed_ship_price', 'feed_ship_service', 'wa_message', 'wa_label', 'related_heading', 'recent_heading', 'home_recent_heading', 'home_featured_heading', 'home_bestsellers_heading', 'kevro_entity_name', 'kevro_ws_user', 'kevro_basic_user', 'kevro_qty_field', 'kevro_markup', 'kevro_branding_markup', 'kevro_colorstatus_blocklist', 'esquire_key', 'esquire_id', 'esquire_round', 'esquire_markup', 'scoop_markup', 'parrot_markup', 'micropoint_markup', 'card_stock_threshold', 'freeship_threshold', 'news_heading', 'news_sub', 'sizing_base_url', 'sizing_suffixes', 'footer_pricing_line', 'footer_pricing_sub', 'footer_biz_name', 'footer_biz_email', 'footer_biz_phone', 'footer_biz_reg', 'biz_name', 'biz_reg', 'biz_vat_number', 'biz_email', 'biz_phone', 'biz_hours', 'biz_response_time', 'biz_info_officer', 'biz_delivery_areas', 'biz_delivery_partner', 'biz_processing_time', 'biz_lead_time', 'biz_freeship_threshold', 'biz_return_window', 'biz_refund_time' );
		$color_keys = array( 'color_primary', 'color_accent' );
		$int_keys   = array( 'logo', 'favicon', 'seo_og_image', 'home_banner_1_image', 'home_banner_2_image', 'footer_payment_image' );
		$url_keys   = array( 'seo_facebook', 'seo_instagram', 'seo_x', 'seo_youtube', 'seo_tiktok', 'seo_linkedin', 'news_action', 'footer_url_returns', 'footer_url_shipping', 'footer_url_terms', 'footer_url_privacy', 'footer_url_popia', 'footer_url_contact', 'footer_url_help', 'footer_url_track' );
		$ta_keys    = array( 'seo_home_desc', 'seo_default_desc', 'sizing_fallbacks', 'footer_biz_address', 'biz_address' );
		$bool_keys  = array(
			'announce_on', 'hero_autoplay', 'deals_fallback',
			'card_badge_sale', 'card_badge_pct', 'card_badge_new', 'card_badge_hot', 'card_badge_oos',
			'card_ajax_cart', 'card_quick_view', 'card_wishlist', 'card_rating', 'card_hover_actions',
			'card_img_swap', 'card_stock_urgency',
			'shop_filters', 'shop_filter_price', 'shop_filter_brand', 'shop_filter_attrs', 'shop_sticky_atc', 'shop_skeletons',
			'home_recent_enable', 'home_featured_enable', 'home_bestsellers_enable',
			'bunny_cdn_on', 'bunny_optimizer', 'perf_preconnect', 'perf_preload_hero', 'perf_lazyload', 'perf_defer_js', 'perf_defer_css',
			'seo_enable', 'seo_og', 'seo_jsonld', 'seo_sitemap',
			'feed_enable',
			'wa_enable',
			'related_enable', 'recent_enable', 'recent_on_product', 'backtop_enable',
			'usp_on', 'news_on', 'freeship_on', 'footer_pricing_sub_on',
			'biz_vat_enabled',
			'kevro_import_images', 'kevro_manage_stock', 'kevro_oos_draft', 'kevro_prune', 'kevro_skip_zero_qty',
			'esquire_import_images', 'esquire_manage_stock', 'esquire_prune',
			'scoop_import_images', 'scoop_manage_stock', 'scoop_prune',
			'parrot_import_images', 'parrot_manage_stock', 'parrot_prune',
			'micropoint_import_images', 'micropoint_manage_stock', 'micropoint_prune',
		);

		foreach ( $text_keys as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : ( $prev[ $k ] ?? '' );
		}
		foreach ( $url_keys as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? esc_url_raw( trim( wp_unslash( $input[ $k ] ) ) ) : ( $prev[ $k ] ?? '' );
		}
		foreach ( $ta_keys as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? sanitize_textarea_field( wp_unslash( $input[ $k ] ) ) : ( $prev[ $k ] ?? '' );
		}
		foreach ( $color_keys as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? sanitize_hex_color( $input[ $k ] ) : ( $prev[ $k ] ?? '' );
		}
		foreach ( $int_keys as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? absint( $input[ $k ] ) : ( $prev[ $k ] ?? 0 );
		}
		// Booleans: only write when the field was on-screen (present marker) or
		// already saved. Never-saved keys stay unset so code-level defaults apply.
		foreach ( $bool_keys as $k ) {
			if ( isset( $present[ $k ] ) ) {
				$clean[ $k ] = ! empty( $input[ $k ] ) ? '1' : '';
			} elseif ( array_key_exists( $k, $prev ) ) {
				$clean[ $k ] = $prev[ $k ];
			}
		}

		// "New" window (days), clamped.
		if ( isset( $input['card_new_days'] ) ) {
			$clean['card_new_days'] = max( 1, min( 365, absint( $input['card_new_days'] ) ) );
		} elseif ( array_key_exists( 'card_new_days', $prev ) ) {
			$clean['card_new_days'] = $prev['card_new_days'];
		}

		// Whitelisted selects. A select only submits when its tab is on-screen;
		// for off-screen tabs we must preserve the saved value (or, if never
		// saved, fall back to the field's REGISTERED default — not allowed[0],
		// which silently reverted e.g. header_layout to "classic").
		foreach ( $this->selects as $k => $allowed ) {
			$default = $this->select_defaults[ $k ] ?? ( $allowed[0] ?? '' );
			if ( isset( $input[ $k ] ) && in_array( $input[ $k ], $allowed, true ) ) {
				$clean[ $k ] = $input[ $k ];
			} elseif ( array_key_exists( $k, $prev ) && in_array( $prev[ $k ], $allowed, true ) ) {
				$clean[ $k ] = $prev[ $k ];
			} else {
				$clean[ $k ] = $default;
			}
		}

		// Deal carousel counts (clamped).
		$clean['deals_per']        = isset( $input['deals_per'] ) ? max( 2, min( 20, absint( $input['deals_per'] ) ) ) : ( $prev['deals_per'] ?? 8 );
		$clean['deals_dept_limit'] = isset( $input['deals_dept_limit'] ) ? max( 1, min( 9, absint( $input['deals_dept_limit'] ) ) ) : ( $prev['deals_dept_limit'] ?? 6 );

		// Products per shop page (clamped).
		if ( isset( $input['shop_per_page'] ) ) {
			$clean['shop_per_page'] = max( 1, min( 96, absint( $input['shop_per_page'] ) ) );
		} elseif ( array_key_exists( 'shop_per_page', $prev ) ) {
			$clean['shop_per_page'] = $prev['shop_per_page'];
		}

		// Bunny pull-zone host (store as bare hostname).
		if ( isset( $input['bunny_host'] ) ) {
			$h = trim( wp_unslash( $input['bunny_host'] ) );
			if ( '' !== $h && false === strpos( $h, '//' ) ) {
				$h = 'https://' . $h;
			}
			$host                 = $h ? wp_parse_url( $h, PHP_URL_HOST ) : '';
			$clean['bunny_host']  = $host ? strtolower( $host ) : '';
		} elseif ( array_key_exists( 'bunny_host', $prev ) ) {
			$clean['bunny_host'] = $prev['bunny_host'];
		}

		// Bunny image quality (1–100).
		if ( isset( $input['bunny_quality'] ) ) {
			$clean['bunny_quality'] = max( 1, min( 100, absint( $input['bunny_quality'] ) ) );
		} elseif ( array_key_exists( 'bunny_quality', $prev ) ) {
			$clean['bunny_quality'] = $prev['bunny_quality'];
		}

		// Critical CSS (strip any HTML tags; keep CSS as-is).
		if ( isset( $input['perf_critical_css'] ) ) {
			$css = (string) wp_unslash( $input['perf_critical_css'] );
			$css = preg_replace( '#</?[a-zA-Z][^>]*>#', '', $css );
			$clean['perf_critical_css'] = trim( $css );
		} elseif ( array_key_exists( 'perf_critical_css', $prev ) ) {
			$clean['perf_critical_css'] = $prev['perf_critical_css'];
		}

		// Hero interval (seconds, min 2).
		$clean['hero_interval'] = isset( $input['hero_interval'] ) ? max( 2, absint( $input['hero_interval'] ) ) : ( $prev['hero_interval'] ?? 6 );

		// Related / recently-viewed counts (clamped 2–20).
		foreach ( array( 'related_count', 'recent_count', 'home_recent_count', 'home_featured_count', 'home_bestsellers_count' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = max( 2, min( 20, absint( $input[ $k ] ) ) );
			} elseif ( array_key_exists( $k, $prev ) ) {
				$clean[ $k ] = $prev[ $k ];
			}
		}

		// Kevro entity ID + batch size.
		if ( isset( $input['kevro_entity_id'] ) ) {
			$clean['kevro_entity_id'] = absint( $input['kevro_entity_id'] );
		} elseif ( array_key_exists( 'kevro_entity_id', $prev ) ) {
			$clean['kevro_entity_id'] = $prev['kevro_entity_id'];
		}
		if ( isset( $input['kevro_batch'] ) ) {
			$clean['kevro_batch'] = max( 1, min( 50, absint( $input['kevro_batch'] ) ) );
		} elseif ( array_key_exists( 'kevro_batch', $prev ) ) {
			$clean['kevro_batch'] = $prev['kevro_batch'];
		}
		if ( isset( $input['esquire_batch'] ) ) {
			$clean['esquire_batch'] = max( 1, min( 50, absint( $input['esquire_batch'] ) ) );
		} elseif ( array_key_exists( 'esquire_batch', $prev ) ) {
			$clean['esquire_batch'] = $prev['esquire_batch'];
		}
		foreach ( array( 'scoop_batch', 'parrot_batch', 'micropoint_batch' ) as $bk ) {
			if ( isset( $input[ $bk ] ) ) {
				$clean[ $bk ] = max( 1, min( 50, absint( $input[ $bk ] ) ) );
			} elseif ( array_key_exists( $bk, $prev ) ) {
				$clean[ $bk ] = $prev[ $bk ];
			}
		}
		// Secrets: only overwrite when a new non-empty value is submitted.
		foreach ( array( 'kevro_ws_pass', 'kevro_token', 'kevro_basic_pass' ) as $k ) {
			if ( isset( $input[ $k ] ) && '' !== trim( (string) $input[ $k ] ) ) {
				$clean[ $k ] = sanitize_text_field( wp_unslash( $input[ $k ] ) );
			} elseif ( array_key_exists( $k, $prev ) ) {
				$clean[ $k ] = $prev[ $k ];
			}
		}

		// WhatsApp number (digits only).
		if ( isset( $input['wa_number'] ) ) {
			$clean['wa_number'] = preg_replace( '/\D/', '', (string) wp_unslash( $input['wa_number'] ) );
		} elseif ( array_key_exists( 'wa_number', $prev ) ) {
			$clean['wa_number'] = $prev['wa_number'];
		}

		// WhatsApp float offsets (px, clamped).
		foreach ( array( 'wa_offset_side' => 600, 'wa_offset_bottom' => 600 ) as $k => $maxv ) {
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = max( 0, min( $maxv, absint( $input[ $k ] ) ) );
			} elseif ( array_key_exists( $k, $prev ) ) {
				$clean[ $k ] = $prev[ $k ];
			}
		}

		// Hero slides (custom schema).
		$clean['hero_slides'] = $this->sanitize_repeater(
			$input,
			$prev,
			'hero_slides',
			array(
				array( 'key' => 'image', 'type' => 'image' ),
				array( 'key' => 'image_mobile', 'type' => 'image' ),
				array( 'key' => 'eyebrow', 'type' => 'text' ),
				array( 'key' => 'title', 'type' => 'text' ),
				array( 'key' => 'subtitle', 'type' => 'textarea' ),
				array( 'key' => 'cta_text', 'type' => 'text' ),
				array( 'key' => 'cta_link', 'type' => 'url' ),
			)
		);

		// Generic repeaters (side_promos, promo_banners, …).
		foreach ( $this->repeaters as $okey => $fields ) {
			$clean[ $okey ] = $this->sanitize_repeater( $input, $prev, $okey, $fields );
		}

		// Homepage section order & visibility.
		if ( isset( $input['homepage_sections'] ) && is_array( $input['homepage_sections'] ) ) {
			$labels = $this->section_labels();
			$rows   = array();
			$seen   = array();
			foreach ( $input['homepage_sections'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$k = isset( $r['key'] ) ? sanitize_key( $r['key'] ) : '';
				if ( ! isset( $labels[ $k ] ) || isset( $seen[ $k ] ) ) {
					continue;
				}
				$rows[]     = array( 'key' => $k, 'on' => ! empty( $r['on'] ) ? '1' : '' );
				$seen[ $k ] = 1;
			}
			$clean['homepage_sections'] = array_values( $rows );
		} else {
			$clean['homepage_sections'] = ( isset( $prev['homepage_sections'] ) && is_array( $prev['homepage_sections'] ) ) ? $prev['homepage_sections'] : array();
		}

		return array_merge( $prev, $clean );
	}

	/** Sanitize one repeater array; preserves prev when the field is off-screen. */
	private function sanitize_repeater( $input, $prev, $okey, $fields ) {
		if ( ! isset( $input[ $okey ] ) || ! is_array( $input[ $okey ] ) ) {
			return ( isset( $prev[ $okey ] ) && is_array( $prev[ $okey ] ) ) ? $prev[ $okey ] : array();
		}
		$rows = array();
		foreach ( $input[ $okey ] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean_row = array();
			$has       = false;
			foreach ( $fields as $f ) {
				$raw = isset( $row[ $f['key'] ] ) ? $row[ $f['key'] ] : '';
				switch ( $f['type'] ) {
					case 'image':
						$cv = absint( $raw );
						break;
					case 'url':
						$cv = esc_url_raw( $raw );
						break;
					case 'textarea':
						$cv = sanitize_textarea_field( $raw );
						break;
					default:
						$cv = sanitize_text_field( $raw );
				}
				$clean_row[ $f['key'] ] = $cv;
				if ( '' !== $cv && 0 !== $cv ) {
					$has = true;
				}
			}
			if ( $has ) {
				$rows[] = $clean_row;
			}
		}
		return array_values( $rows );
	}

	/* Page. */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'branding'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $this->tabs[ $active ] ) ) {
			$active = 'branding';
		}
		?>
		<div class="wrap corpmerch-wrap">
			<h1>Corpmerch Settings <span style="font-size:12px;color:#888;">v<?php echo esc_html( CORPMERCH_VERSION ); ?></span></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_KEY . '_group' );
				do_settings_sections( self::SLUG . '_' . $active );

				submit_button();
				?>
			</form>
		</div>

		<style>
		.corpmerch-wrap form > h2{margin:28px 0 4px;padding:10px 0 0;border-top:1px solid #dcdcde;font-size:16px;}
		.corpmerch-wrap form > h2:first-of-type{border-top:0;margin-top:14px;}
		.corpmerch-wrap .form-table{margin-top:8px;}
		.sia-repeater .sia-rep-row{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:0 0 12px;}
		.sia-rep-head{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;border-radius:8px 8px 0 0;}
		.sia-rep-head .sia-rep-drag{cursor:move;color:#787c82;}
		.sia-rep-head .sia-rep-title{flex:1;}
		.sia-rep-remove{color:#b32d2e;text-decoration:none;}
		.sia-rep-body{padding:12px;}
		.sia-rep-images,.sia-rep-cta{display:flex;gap:24px;flex-wrap:wrap;}
		.sia-rep-images{margin-bottom:8px;}
		.sia-rep-row.ui-sortable-helper{box-shadow:0 8px 24px rgba(0,0,0,.18);}
		.sia-rep-placeholder{border:2px dashed #c3c4c7;border-radius:8px;margin:0 0 12px;height:60px;}
		.sia-sections-sort{margin:0;max-width:480px;}
		.sia-sections-sort li{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:0 0 8px;}
		.sia-sections-sort li label{flex:1;margin:0;}
		.sia-sections-sort .sia-rep-drag{cursor:move;color:#787c82;}
		</style>

		<script>
		jQuery(function($){
			$('.sia-color').wpColorPicker();
			if ($.fn.sortable){ $('.sia-sections-sort').sortable({ handle:'.sia-rep-drag', placeholder:'sia-rep-placeholder', forcePlaceholderSize:true }); }

			// Before submit, renumber section field indices to match the current
			// (possibly dragged) DOM order, so the saved order = the visible order.
			$('.sia-sections-sort').closest('form').on('submit', function(){
				$('.sia-sections-sort .sia-sec-item').each(function(i){
					$(this).find('input').each(function(){
						var n = $(this).attr('name');
						if (n){ $(this).attr('name', n.replace(/\[homepage_sections\]\[\d+\]/, '[homepage_sections]['+i+']')); }
					});
				});
			});

			// Delegated media uploader (works for dynamically added repeater rows).
			$('.corpmerch-wrap').on('click','.sia-image-upload',function(e){
				e.preventDefault();
				var $wrap = $(this).closest('.sia-image-field');
				var frame = wp.media({title:'Select image',multiple:false});
				frame.on('select',function(){
					var a = frame.state().get('selection').first().toJSON();
					$wrap.find('.sia-image-id').val(a.id);
					$wrap.find('.sia-preview').attr('src', a.url).show();
					$wrap.find('.sia-image-remove').show();
				});
				frame.open();
			});
			$('.corpmerch-wrap').on('click','.sia-image-remove',function(e){
				e.preventDefault();
				var $wrap = $(this).closest('.sia-image-field');
				$wrap.find('.sia-image-id').val('');
				$wrap.find('.sia-preview').hide();
				$(this).hide();
			});

			// Generic repeater behaviour for every .sia-repeater block.
			$('.sia-repeater').each(function(){				var $rep = $(this);
				var $rows = $rep.find('.sia-rep-rows').first();
				var tpl = $rep.find('.sia-rep-tpl').first().html();
				$rep.on('click','.sia-rep-add',function(e){
					e.preventDefault();
					var i = parseInt($rep.attr('data-next'),10) || 0;
					$rep.attr('data-next', i+1);
					$rows.append(tpl.replace(/__i__/g, i));
				});
				$rep.on('click','.sia-rep-remove',function(e){
					e.preventDefault();
					$(this).closest('.sia-rep-row').remove();
				});
				if ($.fn.sortable){
					$rows.sortable({ handle:'.sia-rep-drag', placeholder:'sia-rep-placeholder', forcePlaceholderSize:true });
				}
			});
		});
		</script>
		<?php
	}
}

new Corpmerch_Settings();
