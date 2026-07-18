<?php
/**
 * Honestly Market theme setup.
 *
 * Phase 1 scope: block-theme plumbing, asset loading, and the
 * AI-readiness groundwork (structured data, llms.txt, AI-crawler
 * access) that doesn't depend on WooCommerce or the vendor layer.
 * Product/Offer schema, live category taxonomy, and AI search /
 * recommendations / chat land in Phase 2 once WooCommerce and the
 * custom multivendor plugin are in place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HONESTLY_MARKET_VERSION = '0.1.0';

/**
 * Theme setup.
 */
function honestly_market_setup() {
	load_theme_textdomain( 'honestly-market', get_template_directory() . '/languages' );

	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'script', 'style' ) );

	// No-op until the WooCommerce plugin is installed in Phase 2; declaring
	// it now avoids having to remember to add it once the plugin lands.
	add_theme_support( 'woocommerce' );

	register_block_pattern_category(
		'honestly-market',
		array( 'label' => __( 'Honestly Market', 'honestly-market' ) )
	);
}
add_action( 'after_setup_theme', 'honestly_market_setup' );

/**
 * Enqueue theme assets.
 */
function honestly_market_assets() {
	wp_enqueue_style(
		'honestly-market-theme',
		get_theme_file_uri( 'assets/css/theme.css' ),
		array(),
		HONESTLY_MARKET_VERSION
	);

	wp_enqueue_script(
		'honestly-market-header-scroll',
		get_theme_file_uri( 'assets/js/header-scroll.js' ),
		array(),
		HONESTLY_MARKET_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'honestly_market_assets' );

/**
 * ---------------------------------------------------------------
 * AI readiness: structured data
 * ---------------------------------------------------------------
 * Sitewide Organization + WebSite JSON-LD so AI shopping assistants
 * and answer engines (and regular search) can identify the site and
 * its search endpoint. Product/Offer schema is added per-product in
 * Phase 2, once WooCommerce provides real price/availability data —
 * emitting it now would mean publishing structured data for products
 * that don't exist yet.
 */
function honestly_market_structured_data() {
	if ( ! is_front_page() ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
			array(
				'@type'           => 'WebSite',
				'name'            => get_bloginfo( 'name' ),
				'url'             => home_url( '/' ),
				'potentialAction' => array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/?s={search_term_string}' ),
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		),
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}
add_action( 'wp_head', 'honestly_market_structured_data' );

/**
 * ---------------------------------------------------------------
 * AI readiness: don't accidentally block AI crawlers
 * ---------------------------------------------------------------
 * WordPress's virtual robots.txt only disallows /wp-admin/ by
 * default, so this is intentionally an explicit allow list rather
 * than a fix for something broken — it documents, in one place, that
 * the AI shopping/answer-engine crawlers this site wants discovering
 * products are allowed, so a future blanket "Disallow" doesn't silently
 * take them out with it.
 */
function honestly_market_robots_txt( $output, $public ) {
	if ( '1' !== (string) $public ) {
		return $output;
	}

	$ai_crawlers = array( 'GPTBot', 'ChatGPT-User', 'PerplexityBot', 'ClaudeBot', 'Claude-User', 'Google-Extended' );

	$output .= "\n";
	foreach ( $ai_crawlers as $agent ) {
		$output .= "User-agent: {$agent}\nAllow: /\n\n";
	}

	$output .= 'Sitemap: ' . home_url( '/wp-sitemap.xml' ) . "\n";

	return $output;
}
add_filter( 'robots_txt', 'honestly_market_robots_txt', 10, 2 );

/**
 * ---------------------------------------------------------------
 * AI readiness: /llms.txt
 * ---------------------------------------------------------------
 * Served dynamically (rather than as a static file) so it always
 * reflects the live site name/tagline instead of going stale.
 */
function honestly_market_llms_rewrite() {
	add_rewrite_rule( '^llms\.txt$', 'index.php?honestly_llms=1', 'top' );
}
add_action( 'init', 'honestly_market_llms_rewrite' );

function honestly_market_llms_query_vars( $vars ) {
	$vars[] = 'honestly_llms';
	return $vars;
}
add_filter( 'query_vars', 'honestly_market_llms_query_vars' );

function honestly_market_llms_render() {
	if ( ! get_query_var( 'honestly_llms' ) ) {
		return;
	}

	header( 'Content-Type: text/plain; charset=utf-8' );

	$lines = array(
		'# ' . get_bloginfo( 'name' ),
		'',
		'> ' . ( get_bloginfo( 'description' ) ?: 'A South African multivendor marketplace.' ),
		'',
		'One rule: the price shown is the price paid. No inflated "was" prices, no countdown-timer discounts, no auctions.',
		'',
		'## Site',
		'- Homepage: ' . home_url( '/' ),
		'- Search: ' . home_url( '/?s={query}' ),
	);

	echo implode( "\n", $lines ) . "\n";
	exit;
}
add_action( 'template_redirect', 'honestly_market_llms_render' );

/**
 * Flush rewrite rules once on theme activation so /llms.txt resolves
 * without requiring a manual visit to Settings > Permalinks.
 */
function honestly_market_flush_rewrites() {
	honestly_market_llms_rewrite();
	flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'honestly_market_flush_rewrites' );
