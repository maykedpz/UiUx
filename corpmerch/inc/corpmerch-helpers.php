<?php
/**
 * Corpmerch engine helper functions.
 *
 * These are the option/state helpers the Kevro import engine depends on.
 * Kept minimal: only what the engine cluster actually calls.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scalar option reader. Falls back to $default when unset or empty-string.
 */
function corpmerch_option( $key, $default = '' ) {
	$opts = get_option( Corpmerch_Settings::OPTION_KEY, array() );
	return isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? $opts[ $key ] : $default;
}

/**
 * Boolean option reader that distinguishes "never saved" (use default) from
 * "saved as empty" (off), so unchecking a default-on box actually sticks.
 */
function corpmerch_bool( $key, $default_on ) {
	$opts = get_option( Corpmerch_Settings::OPTION_KEY, array() );
	if ( is_array( $opts ) && array_key_exists( $key, $opts ) ) {
		return '' !== $opts[ $key ] && '0' !== $opts[ $key ];
	}
	return (bool) $default_on;
}

/**
 * Global feed kill-switch. When true, the Kevro importer refuses to start
 * or continue. Toggle via the Corpmerch settings panel.
 */
function corpmerch_sync_paused() {
	return (bool) get_option( 'corpmerch_sync_paused', false );
}

/**
 * Slugs of product_cat terms that should never appear in front-end category
 * listings (nav, homepage sidebar, category grid).
 *
 * - "unmapped"      : auto-filer fallback bucket for unresolved Kevro labels.
 * - "uncategorized" : WooCommerce default term; carries no products here
 *                     because the importer always files into a real category.
 *
 * @return string[]
 */
function corpmerch_hidden_cat_slugs() {
	return apply_filters( 'corpmerch_hidden_cat_slugs', array( 'unmapped', 'uncategorized' ) );
}

/**
 * Resolve corpmerch_hidden_cat_slugs() to an array of term IDs suitable for the
 * 'exclude' arg of get_terms(). Returns array() if none exist yet.
 *
 * @return int[]
 */
function corpmerch_hidden_cat_ids() {
	$ids = array();
	foreach ( corpmerch_hidden_cat_slugs() as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return $ids;
}

/**
 * Return the site logo as an <img> tag from the theme 'logo' setting, or ''.
 *
 * Centralises the logo so the header, drawer and footer all render the same
 * uploaded image. Falls back to '' so callers can show their text mark.
 *
 * @param string $class CSS class for the <img>.
 * @param string $size  Registered image size (default 'full').
 * @return string
 */
function corpmerch_logo_img( $class = 'cm-logo__img', $size = 'full' ) {
	$id = function_exists( 'corpmerch_option' ) ? (int) corpmerch_option( 'logo', 0 ) : 0;
	if ( $id <= 0 ) {
		return '';
	}
	return wp_get_attachment_image(
		$id,
		$size,
		false,
		array(
			'class' => $class,
			'alt'   => get_bloginfo( 'name' ),
		)
	);
}
