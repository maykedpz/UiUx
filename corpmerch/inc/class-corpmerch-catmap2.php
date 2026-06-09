<?php
/**
 * Corpmerch — Auto Category Map (v2).
 *
 * Reads feed category labels already stored on products and lets the owner map
 * each distinct label to an existing Book6 product_cat term. Assignment is
 * explicit and batched (host has a strict few-second timeout).
 *
 * Labels are read from the unified key written by the importers plus the three
 * legacy per-feed keys, so products imported before the unified key still map.
 *
 * @package Corpmerch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auto Category Map screen.
 */
class Corpmerch_CatMap2 {

	const SLUG       = 'corpmerch-catmap';
	const OPTION     = 'corpmerch_catmap2';        // label => term_id.
	const OPT_HIDE   = 'corpmerch_catmap2_hide';   // hide-completed toggle (1/0).
	const BATCH      = 100;                         // products per assignment click.
	const META_KEYS  = array( '_cm_feed_cat', '_kevro_type', '_kevro_category', '_esquire_category', '_xmlfeed_category' );

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
	}

	/**
	 * Register the submenu page.
	 */
	public function menu() {
		add_submenu_page(
			'corpmerch',
			__( 'Auto Category Map', 'corpmerch' ),
			__( 'Auto Category Map', 'corpmerch' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Saved map (label => term_id).
	 *
	 * @return array
	 */
	private function map() {
		$m = get_option( self::OPTION, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Hide-completed toggle (default ON).
	 *
	 * @return bool
	 */
	private function hide_completed() {
		$v = get_option( self::OPT_HIDE, '1' );
		return '1' === (string) $v;
	}

	/**
	 * Distinct feed labels across all products, with a product count each.
	 *
	 * One label can come from several meta keys; we union them so each label
	 * appears once. Counts are approximate (a product counted once per label).
	 *
	 * @return array label => count
	 */
	private function scan_labels() {
		global $wpdb;
		$keys_in = "'" . implode( "','", array_map( 'esc_sql', self::META_KEYS ) ) . "'";
		// Only labels attached to live products.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			"SELECT pm.meta_value AS label, COUNT(DISTINCT pm.post_id) AS n
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key IN ({$keys_in})
			    AND pm.meta_value <> ''
			    AND p.post_type = 'product'
			    AND p.post_status IN ('publish','draft','pending','private')
			  GROUP BY pm.meta_value
			  ORDER BY pm.meta_value ASC"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$label = trim( (string) $r->label );
			if ( '' === $label ) {
				continue;
			}
			$out[ $label ] = isset( $out[ $label ] ) ? $out[ $label ] + (int) $r->n : (int) $r->n;
		}
		ksort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Product IDs carrying a given label that are NOT yet in the target term.
	 *
	 * @param string $label Feed label.
	 * @param int    $term  Target product_cat term id.
	 * @param int    $limit Max ids to return.
	 * @return int[]
	 */
	private function ids_for_label( $label, $term, $limit ) {
		global $wpdb;
		$keys_in = "'" . implode( "','", array_map( 'esc_sql', self::META_KEYS ) ) . "'";
		$ids     = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key IN ({$keys_in})
				    AND pm.meta_value = %s
				    AND p.post_type = 'product'
				    AND p.post_status IN ('publish','draft','pending','private')
				    AND pm.post_id NOT IN (
				        SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				        WHERE tt.taxonomy = 'product_cat' AND tt.term_id = %d
				    )
				  LIMIT %d",
				$label,
				(int) $term,
				(int) $limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Count of products carrying a label that still need the term applied.
	 * Uses a direct COUNT (no row fetch) for speed on large catalogs.
	 *
	 * @param string $label Feed label.
	 * @param int    $term  Target term id.
	 * @return int
	 */
	private function remaining( $label, $term ) {
		global $wpdb;
		$keys_in = "'" . implode( "','", array_map( 'esc_sql', self::META_KEYS ) ) . "'";
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id)
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key IN ({$keys_in})
				    AND pm.meta_value = %s
				    AND p.post_type = 'product'
				    AND p.post_status IN ('publish','draft','pending','private')
				    AND pm.post_id NOT IN (
				        SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				        WHERE tt.taxonomy = 'product_cat' AND tt.term_id = %d
				    )",
				$label,
				(int) $term
			)
		);
	}

	/**
	 * Fast boolean: does this label still have any product needing the term?
	 * Short-circuits with LIMIT 1 — used by the hide-completed check, which
	 * only needs 0-or-not, not an exact count.
	 *
	 * @param string $label Feed label.
	 * @param int    $term  Target term id.
	 * @return bool
	 */
	private function has_remaining( $label, $term ) {
		return ! empty( $this->ids_for_label( $label, $term, 1 ) );
	}

	/**
	 * Normalize a string for fuzzy matching: lowercase, strip non-alphanumerics.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	private function norm( $s ) {
		$s = strtolower( (string) $s );
		$s = preg_replace( '/[^a-z0-9]+/', '', $s );
		return (string) $s;
	}

	/**
	 * Crude singular/plural fold of a normalized token.
	 *
	 * @param string $n Normalized string.
	 * @return string
	 */
	private function depluralize( $n ) {
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
	 * All product_cat terms as id => name (and a normalized index).
	 *
	 * @return array { terms: array<int,string>, by_norm: array<string,int>, by_plural: array<string,int> }
	 */
	private function term_index() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$names     = array();
		$by_norm   = array();
		$by_plural = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$names[ (int) $t->term_id ] = $t->name;
				$n                          = $this->norm( $t->name );
				if ( '' !== $n && ! isset( $by_norm[ $n ] ) ) {
					$by_norm[ $n ] = (int) $t->term_id;
				}
				$p = $this->depluralize( $n );
				if ( '' !== $p && ! isset( $by_plural[ $p ] ) ) {
					$by_plural[ $p ] = (int) $t->term_id;
				}
			}
		}
		return array(
			'terms'     => $names,
			'by_norm'   => $by_norm,
			'by_plural' => $by_plural,
		);
	}

	/**
	 * Match one label to a term id. Returns [ term_id, confidence ].
	 * Confidence: 'exact' | 'fuzzy' | 'none'.
	 *
	 * @param string $label Feed label (leaf preferred — take last >-segment).
	 * @param array  $idx   term_index().
	 * @return array
	 */
	private function match_label( $label, $idx ) {
		// Prefer the leaf of a delimited path.
		$parts = array_filter( array_map( 'trim', preg_split( '/[>\/|]/', (string) $label ) ) );
		$leaf  = ! empty( $parts ) ? (string) end( $parts ) : (string) $label;

		$n = $this->norm( $leaf );
		if ( '' === $n ) {
			return array( 0, 'none' );
		}
		// Exact (normalized name equality).
		if ( isset( $idx['by_norm'][ $n ] ) ) {
			return array( $idx['by_norm'][ $n ], 'exact' );
		}
		// Singular/plural fold.
		$p = $this->depluralize( $n );
		if ( isset( $idx['by_plural'][ $p ] ) ) {
			return array( $idx['by_plural'][ $p ], 'fuzzy' );
		}
		if ( isset( $idx['by_norm'][ $p ] ) ) {
			return array( $idx['by_norm'][ $p ], 'fuzzy' );
		}
		// Contains (either direction), longest term name wins for specificity.
		$best     = 0;
		$best_len = 0;
		foreach ( $idx['by_norm'] as $tn => $tid ) {
			if ( strlen( $tn ) < 3 ) {
				continue;
			}
			if ( false !== strpos( $n, $tn ) || false !== strpos( $tn, $n ) ) {
				if ( strlen( $tn ) > $best_len ) {
					$best     = $tid;
					$best_len = strlen( $tn );
				}
			}
		}
		if ( $best ) {
			return array( $best, 'fuzzy' );
		}
		return array( 0, 'none' );
	}

	/**
	 * Auto-match: fill the saved map for every unmapped label. Writes the map
	 * only; assigns nothing. Returns counts by confidence.
	 *
	 * @return array { exact:int, fuzzy:int, none:int }
	 */
	private function auto_match() {
		$labels = $this->scan_labels();
		$map    = $this->map();
		$idx    = $this->term_index();
		$stats  = array(
			'exact' => 0,
			'fuzzy' => 0,
			'none'  => 0,
		);
		foreach ( $labels as $label => $count ) {
			// Don't clobber an existing manual/auto mapping.
			if ( ! empty( $map[ $label ] ) ) {
				continue;
			}
			list( $tid, $conf ) = $this->match_label( $label, $idx );
			if ( $tid ) {
				$map[ $label ] = (int) $tid;
			}
			++$stats[ $conf ];
		}
		update_option( self::OPTION, $map );
		return $stats;
	}

	/**
	 * Backfill the visible cm_feed_cat / cm_feed_src mirror keys onto products
	 * that have the hidden/legacy keys but not the visible ones. Batched.
	 *
	 * @param int $limit Max products to process this click.
	 * @return array { processed:int, remaining:int }
	 */
	private function backfill( $limit ) {
		global $wpdb;
		// Products with any hidden/legacy feed-cat key but missing visible mirror.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				    AND m.meta_key IN ('_cm_feed_cat','_kevro_type','_kevro_category','_esquire_category','_xmlfeed_category')
				    AND m.meta_value <> ''
				  WHERE p.post_type = 'product'
				    AND p.post_status IN ('publish','draft','pending','private')
				    AND NOT EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} v
				        WHERE v.post_id = p.ID AND v.meta_key = 'cm_feed_cat' AND v.meta_value <> ''
				    )
				  LIMIT %d",
				(int) $limit
			)
		);
		$ids       = array_map( 'intval', (array) $ids );
		$processed = 0;
		foreach ( $ids as $id ) {
			$cat = get_post_meta( $id, '_cm_feed_cat', true );
			$src = get_post_meta( $id, '_cm_feed_src', true );
			if ( '' === $cat ) {
				// Derive from legacy keys (leaf preferred).
				$kt = get_post_meta( $id, '_kevro_type', true );
				$kc = get_post_meta( $id, '_kevro_category', true );
				$ec = get_post_meta( $id, '_esquire_category', true );
				$xc = get_post_meta( $id, '_xmlfeed_category', true );
				if ( '' !== $kt ) {
					$cat = $kt;
					$src = $src ? $src : 'kevro';
				} elseif ( '' !== $kc ) {
					$cat = $kc;
					$src = $src ? $src : 'kevro';
				} elseif ( '' !== $ec ) {
					// Esquire stored "head > leaf"; take leaf.
					$parts = array_filter( array_map( 'trim', explode( '>', $ec ) ) );
					$cat   = ! empty( $parts ) ? end( $parts ) : $ec;
					$src   = $src ? $src : 'esquire';
				} elseif ( '' !== $xc ) {
					$cat = $xc;
					$src = $src ? $src : 'xmlfeed';
				}
			}
			if ( '' === $cat ) {
				continue;
			}
			update_post_meta( $id, 'cm_feed_cat', $cat );
			update_post_meta( $id, 'cm_feed_src', $src ? $src : 'feed' );
			++$processed;
		}
		// Remaining count (capped).
		$left = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			    AND m.meta_key IN ('_cm_feed_cat','_kevro_type','_kevro_category','_esquire_category','_xmlfeed_category')
			    AND m.meta_value <> ''
			  WHERE p.post_type = 'product'
			    AND p.post_status IN ('publish','draft','pending','private')
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->postmeta} v
			        WHERE v.post_id = p.ID AND v.meta_key = 'cm_feed_cat' AND v.meta_value <> ''
			    )"
		);
		return array(
			'processed' => $processed,
			'remaining' => $left,
		);
	}

	/**
	 * Apply across all mapped labels, up to BATCH assignments total this click.
	 *
	 * @return array { assigned:int, remaining:int }
	 */
	private function apply_all() {
		$map      = $this->map();
		$assigned = 0;
		$budget   = self::BATCH;
		foreach ( $map as $label => $term ) {
			if ( $budget <= 0 ) {
				break;
			}
			$term = (int) $term;
			if ( ! $term ) {
				continue;
			}
			$ids = $this->ids_for_label( (string) $label, $term, $budget );
			foreach ( $ids as $id ) {
				wp_set_object_terms( $id, array( $term ), 'product_cat', true ); // append, keep existing.
				++$assigned;
				--$budget;
			}
		}
		// Total remaining across all mapped labels (capped per label).
		$left = 0;
		foreach ( $map as $label => $term ) {
			$term = (int) $term;
			if ( ! $term ) {
				continue;
			}
			$left += $this->remaining( (string) $label, $term );
		}
		return array(
			'assigned'  => $assigned,
			'remaining' => $left,
		);
	}

	/**
	 * Handle POST: save map and/or apply.
	 *
	 * @return string Notice HTML.
	 */
	private function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( empty( $_POST['cm_catmap'] ) ) {
			return '';
		}
		check_admin_referer( 'cm_catmap' );

		// Toggle hide-completed.
		if ( isset( $_POST['cm_toggle_hide'] ) ) {
			update_option( self::OPT_HIDE, $this->hide_completed() ? '0' : '1' );
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Display preference saved.', 'corpmerch' )
			);
		}

		// Backfill action: copy hidden/legacy keys to visible mirror keys.
		if ( ! empty( $_POST['cm_backfill'] ) ) {
			$res = $this->backfill( self::BATCH );
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: processed count, 2: remaining count */
						__( 'Backfilled %1$d product(s). %2$d still to do — click again to continue.', 'corpmerch' ),
						(int) $res['processed'],
						(int) $res['remaining']
					)
				)
			);
		}

		// Auto-match action.
		if ( ! empty( $_POST['cm_automatch'] ) ) {
			$s = $this->auto_match();
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: exact, 2: fuzzy, 3: unmatched */
						__( 'Auto-match done: %1$d exact, %2$d fuzzy, %3$d unmatched. Review the dropdowns, then Save map and Apply.', 'corpmerch' ),
						(int) $s['exact'],
						(int) $s['fuzzy'],
						(int) $s['none']
					)
				)
			);
		}

		// Save the full map first.
		$map = array();
		if ( ! empty( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( wp_unslash( $_POST['map'] ) as $label => $term ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$label = trim( (string) $label );
				$term  = absint( $term );
				if ( '' === $label ) {
					continue;
				}
				if ( $term && term_exists( $term, 'product_cat' ) ) {
					$map[ $label ] = $term;
				}
			}
		}
		update_option( self::OPTION, $map );

		// Apply all mapped?
		if ( ! empty( $_POST['cm_apply_all'] ) ) {
			$res = $this->apply_all();
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: assigned count, 2: remaining count */
						__( 'Map saved. Assigned %1$d product(s) this batch. %2$d still to do across all mapped labels — click Apply all mapped again to continue.', 'corpmerch' ),
						(int) $res['assigned'],
						(int) $res['remaining']
					)
				)
			);
		}

		// Apply a single label batch?
		$apply = isset( $_POST['apply_label'] ) ? trim( (string) wp_unslash( $_POST['apply_label'] ) ) : '';
		if ( '' === $apply ) {
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Map saved.', 'corpmerch' )
			);
		}
		if ( ! isset( $map[ $apply ] ) ) {
			return sprintf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Pick a category for that label before applying.', 'corpmerch' )
			);
		}
		$term = (int) $map[ $apply ];
		$ids  = $this->ids_for_label( $apply, $term, self::BATCH );
		$done = 0;
		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, array( $term ), 'product_cat', true ); // append, keep existing.
			++$done;
		}
		$left = $this->remaining( $apply, $term );
		return sprintf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: assigned count, 2: label, 3: remaining count */
					__( 'Assigned %1$d product(s) for "%2$s". %3$d still to do — click Apply again to continue.', 'corpmerch' ),
					$done,
					$apply,
					$left
				)
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render() {
		$notice = $this->handle();
		$labels = $this->scan_labels();
		$map    = $this->map();
		$hide   = $this->hide_completed();
		$idx    = $this->term_index();

		echo '<div class="wrap"><h1>' . esc_html__( 'Auto Category Map', 'corpmerch' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Map each feed category label to one of your Book6 shop categories, then Apply to assign all products carrying that label (in batches, to respect the host timeout). Saving the map alone assigns nothing until you Apply.', 'corpmerch' ) . '</p>';

		// Tools row: backfill + auto-match + hide toggle.
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 16px;">';

		echo '<form method="post" style="margin:0;">';
		wp_nonce_field( 'cm_catmap' );
		echo '<input type="hidden" name="cm_catmap" value="1" />';
		echo '<input type="hidden" name="cm_backfill" value="1" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Backfill visible feed labels', 'corpmerch' ) . '</button>';
		echo '</form>';

		echo '<form method="post" style="margin:0;">';
		wp_nonce_field( 'cm_catmap' );
		echo '<input type="hidden" name="cm_catmap" value="1" />';
		echo '<input type="hidden" name="cm_automatch" value="1" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Auto-match', 'corpmerch' ) . '</button>';
		echo '</form>';

		echo '<form method="post" style="margin:0;">';
		wp_nonce_field( 'cm_catmap' );
		echo '<input type="hidden" name="cm_catmap" value="1" />';
		echo '<input type="hidden" name="cm_toggle_hide" value="1" />';
		echo '<button type="submit" class="button">' . ( $hide ? esc_html__( 'Show completed labels', 'corpmerch' ) : esc_html__( 'Hide completed labels', 'corpmerch' ) ) . '</button>';
		echo '</form>';

		echo '<span class="description">' . esc_html__( 'Auto-match pre-selects dropdowns by name (writes map only). Backfill is batched 100/click.', 'corpmerch' ) . '</span>';
		echo '</div>';

		echo wp_kses_post( $notice );

		if ( empty( $labels ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No feed category labels found on any products yet. Run a feed import (Esquire / Kevro / Parrot / etc.) first — the importer stores the feed category on each product, then they appear here.', 'corpmerch' ) . '</p></div></div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'cm_catmap' );
		echo '<input type="hidden" name="cm_catmap" value="1" />';
		echo '<input type="hidden" name="apply_label" id="sia-apply-label" value="" />';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Feed label', 'corpmerch' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'corpmerch' ) . '</th>';
		echo '<th>' . esc_html__( 'Match', 'corpmerch' ) . '</th>';
		echo '<th>' . esc_html__( 'Map to shop category', 'corpmerch' ) . '</th>';
		echo '<th>' . esc_html__( 'Apply', 'corpmerch' ) . '</th>';
		echo '</tr></thead><tbody>';

		$hidden_count = 0;
		foreach ( $labels as $label => $count ) {
			$sel = isset( $map[ $label ] ) ? (int) $map[ $label ] : 0;

			// Hide-completed: skip rows whose mapped term has 0 remaining.
			if ( $hide && $sel && ! $this->has_remaining( $label, $sel ) ) {
				++$hidden_count;
				continue;
			}

			// Confidence hint relative to the current selection / best match.
			list( $best_tid, $conf ) = $this->match_label( $label, $idx );
			if ( $sel ) {
				$hint = ( $sel === (int) $best_tid && 'exact' === $conf ) ? 'exact' : 'mapped';
			} else {
				$hint = $conf; // exact / fuzzy / none.
			}
			$colors = array(
				'exact'  => '#1a7f37',
				'fuzzy'  => '#9a6700',
				'mapped' => '#0969da',
				'none'   => '#888',
			);
			$hc = isset( $colors[ $hint ] ) ? $colors[ $hint ] : '#888';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td>' . (int) $count . '</td>';
			echo '<td><span style="color:' . esc_attr( $hc ) . ';font-weight:600;">' . esc_html( $hint ) . '</span></td>';
			echo '<td>';
			$dd = wp_dropdown_categories(
				array(
					'taxonomy'          => 'product_cat',
					'hierarchical'      => 1,
					'hide_empty'        => 0,
					'show_option_none'  => __( '— Choose category —', 'corpmerch' ),
					'option_none_value' => 0,
					'name'              => 'map[' . esc_attr( $label ) . ']',
					'value_field'       => 'term_id',
					'selected'          => $sel,
					'echo'              => 0,
				)
			);
			echo $dd; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_dropdown_categories returns safe markup.
			echo '</td>';
			echo '<td>';
			if ( $sel ) {
				echo '<button type="submit" class="button sia-apply" data-label="' . esc_attr( $label ) . '">' . esc_html__( 'Apply', 'corpmerch' ) . '</button>';
			} else {
				echo '<span class="description">' . esc_html__( 'Choose + Save first', 'corpmerch' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $hide && $hidden_count ) {
			echo '<p class="description" style="margin-top:8px;">' . esc_html(
				sprintf(
					/* translators: %d: hidden row count */
					__( '%d completed label(s) hidden. Use “Show completed labels” to reveal them.', 'corpmerch' ),
					(int) $hidden_count
				)
			) . '</p>';
		}

		echo '<p style="margin-top:16px;"><button type="submit" class="button button-primary">' . esc_html__( 'Save map', 'corpmerch' ) . '</button> ';
		echo '<button type="submit" name="cm_apply_all" value="1" class="button">' . esc_html__( 'Apply all mapped', 'corpmerch' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Save first, then use each row\'s Apply, or Apply all mapped (batches of 100).', 'corpmerch' ) . '</span></p>';
		echo '</form>';

		// Apply buttons set the hidden field then submit (no inline PHP-in-JS).
		echo '<script>(function(){';
		echo 'var f=document.getElementById("sia-apply-label");';
		echo 'document.querySelectorAll(".sia-apply").forEach(function(b){';
		echo 'b.addEventListener("click",function(){if(f){f.value=b.getAttribute("data-label");}});';
		echo '});})();</script>';

		echo '</div>';
	}
}

new Corpmerch_CatMap2();
