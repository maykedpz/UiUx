<?php
/**
 * Corpmerch_Cat_Map — automatic category resolver for imported products.
 *
 * Implements the static interface the import engine (Kevro) and feed-XML
 * layer call: record(), resolve(), resolve_any(), unmapped_term_id().
 *
 * Precedence:
 *   1. Manual mappings saved via the CatMap2 admin screen (corpmerch_catmap2
 *      option, label => term_id) ALWAYS win.
 *   2. Otherwise, auto-match the label against existing product_cat terms
 *      using the same normalization as CatMap2 (norm + depluralize, leaf of
 *      a delimited path), exact match then plural-folded match.
 *   3. No match => unmapped_term_id() fallback term.
 *
 * Auto-matching is deliberately conservative: it only matches against
 * categories that already exist (created by the taxonomy importer), never
 * invents new ones. Misses fall to "Unmapped" and can be fixed by hand in
 * the CatMap2 screen, whose saved mapping is then honored here on re-import.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Cat_Map {

	/** Manual map option (shared with the CatMap2 admin screen). */
	const MANUAL_OPTION = 'corpmerch_catmap2';

	/** Discovered-labels option (feeds the admin screen's label list). */
	const SEEN_OPTION = 'corpmerch_catmap_seen';

	/** Slug of the fallback category for unmatched products. */
	const UNMAPPED_SLUG = 'unmapped';

	/** Runtime cache of the term index. */
	private static $idx = null;

	/**
	 * Record a feed label as "seen" so the admin mapping screen can surface
	 * it even before any product carries it as meta. Idempotent.
	 *
	 * @param string $label Raw feed category or type string.
	 */
	public static function record( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return;
		}
		$seen = get_option( self::SEEN_OPTION, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		$key = self::norm( $label );
		if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = $label; // store original casing for display.
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	/**
	 * Resolve a Kevro (category, type) pair to a product_cat term id.
	 * Tries the more specific "type" first, then the broader "category".
	 *
	 * @param string $cat  Kevro category string.
	 * @param string $type Kevro product type/sub string.
	 * @return int term_id, or 0 if unresolved.
	 */
	public static function resolve( $cat, $type ) {
		foreach ( array( $type, $cat ) as $label ) {
			$tid = self::resolve_one( $label );
			if ( $tid > 0 ) {
				return $tid;
			}
		}
		return 0;
	}

	/**
	 * Resolve from an ordered list of labels (feed-XML path). Most specific
	 * labels should be passed last is not assumed; we try each in order and
	 * return the first hit.
	 *
	 * @param array $labels List of raw label strings.
	 * @return int term_id, or 0 if unresolved.
	 */
	public static function resolve_any( $labels ) {
		if ( ! is_array( $labels ) ) {
			$labels = array( $labels );
		}
		foreach ( $labels as $label ) {
			$tid = self::resolve_one( $label );
			if ( $tid > 0 ) {
				return $tid;
			}
		}
		return 0;
	}

	/**
	 * Resolve a single label: manual map first, then auto-match.
	 *
	 * @param string $label Raw label.
	 * @return int term_id or 0.
	 */
	private static function resolve_one( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return 0;
		}

		// 1) Manual override (admin screen). Keyed by normalized label.
		$manual = get_option( self::MANUAL_OPTION, array() );
		if ( is_array( $manual ) ) {
			// The admin screen stores either raw-label or normalized keys;
			// check both forms for safety.
			if ( isset( $manual[ $label ] ) && (int) $manual[ $label ] > 0 ) {
				return (int) $manual[ $label ];
			}
			$nkey = self::norm( $label );
			if ( isset( $manual[ $nkey ] ) && (int) $manual[ $nkey ] > 0 ) {
				return (int) $manual[ $nkey ];
			}
		}

		// 2) Auto-match against existing terms.
		$idx = self::term_index();

		// Prefer the leaf of a delimited path (e.g. "Apparel > T-Shirts").
		$parts = array_filter( array_map( 'trim', preg_split( '/[>\/|]/', $label ) ) );
		$leaf  = ! empty( $parts ) ? (string) end( $parts ) : $label;

		foreach ( array( $leaf, $label ) as $candidate ) {
			$n = self::norm( $candidate );
			if ( '' === $n ) {
				continue;
			}
			if ( isset( $idx['by_norm'][ $n ] ) ) {
				return (int) $idx['by_norm'][ $n ];
			}
			$p = self::depluralize( $n );
			if ( '' !== $p && isset( $idx['by_plural'][ $p ] ) ) {
				return (int) $idx['by_plural'][ $p ];
			}
		}

		return 0;
	}

	/**
	 * term_id of the "Unmapped" fallback category, creating it if needed.
	 *
	 * @return int
	 */
	public static function unmapped_term_id() {
		$existing = get_term_by( 'slug', self::UNMAPPED_SLUG, 'product_cat' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}
		$res = wp_insert_term(
			__( 'Unmapped', 'corpmerch' ),
			'product_cat',
			array( 'slug' => self::UNMAPPED_SLUG )
		);
		if ( ! is_wp_error( $res ) && isset( $res['term_id'] ) ) {
			return (int) $res['term_id'];
		}
		// Race: another request created it between check and insert.
		$again = get_term_by( 'slug', self::UNMAPPED_SLUG, 'product_cat' );
		return ( $again && ! is_wp_error( $again ) ) ? (int) $again->term_id : 0;
	}

	/* ----------------------------------------------------------------------
	 * Normalization helpers — kept identical to the CatMap2 admin screen so
	 * auto-matching here agrees with what the screen previews.
	 * -------------------------------------------------------------------- */

	/** Lowercase, strip to alphanumerics. */
	private static function norm( $s ) {
		$s = strtolower( (string) $s );
		$s = preg_replace( '/[^a-z0-9]+/', '', $s );
		return (string) $s;
	}

	/** Crude singular/plural fold of a normalized token. */
	private static function depluralize( $n ) {
		if ( strlen( $n ) > 3 && 'ies' === substr( $n, -3 ) ) {
			return substr( $n, 0, -3 ) . 'y';
		}
		if ( strlen( $n ) > 3 && 'es' === substr( $n, -2 ) ) {
			return substr( $n, 0, -2 );
		}
		if ( strlen( $n ) > 2 && 's' === substr( $n, -1 ) ) {
			return substr( $n, 0, -1 );
		}
		return $n;
	}

	/**
	 * Build (and cache) the product_cat term index: by normalized name and
	 * by plural-folded name.
	 *
	 * @return array{terms:array<int,string>,by_norm:array<string,int>,by_plural:array<string,int>}
	 */
	private static function term_index() {
		if ( null !== self::$idx ) {
			return self::$idx;
		}
		$terms     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$names     = array();
		$by_norm   = array();
		$by_plural = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$names[ (int) $t->term_id ] = $t->name;
				$n                          = self::norm( $t->name );
				if ( '' !== $n && ! isset( $by_norm[ $n ] ) ) {
					$by_norm[ $n ] = (int) $t->term_id;
				}
				$p = self::depluralize( $n );
				if ( '' !== $p && ! isset( $by_plural[ $p ] ) ) {
					$by_plural[ $p ] = (int) $t->term_id;
				}
			}
		}
		self::$idx = array(
			'terms'     => $names,
			'by_norm'   => $by_norm,
			'by_plural' => $by_plural,
		);
		return self::$idx;
	}

	/** Clear the runtime term cache (call after creating categories). */
	public static function flush_cache() {
		self::$idx = null;
	}
}
