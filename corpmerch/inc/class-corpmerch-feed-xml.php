<?php
/**
 * Corpmerch — Generic XML product-feed importer (Scoop, Parrot).
 *
 * One engine that imports several flat XML feeds, each described by a small
 * config (URL + item element + category delimiter). Fields are read by an
 * ordered list of candidate tag names (case-insensitive, namespace-tolerant),
 * so the same parser handles different schemas. Products are simple, keyed by
 * SKU per source (_xmlfeed_code + _xmlfeed_source), upserted idempotently with
 * a fast price/stock-only path. Runs as a self-chaining WP-Cron background job.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Feed_XML {

	const PREP_HOOK   = 'corpmerch_xmlfeed_prep';
	const RUNNER_HOOK = 'corpmerch_xmlfeed_runner';
	const WATCH_HOOK  = 'corpmerch_xmlfeed_watchdog';
	const CRON_HOOK   = 'corpmerch_xmlfeed_cron';
	const QUEUE_KEY   = 'cm_xmlfeed_queue';
	const STATE_KEY   = 'cm_xmlfeed_state';
	const LOCK_KEY    = 'cm_xmlfeed_lock';
	const LOG_KEY     = 'cm_xmlfeed_last_run';

	/** Field candidates (ordered). First matching tag wins. */
	const MAP = array(
		'sku'      => array( 'sku', 'stockcode', 'stock_code', 'code', 'productcode', 'product_code', 'item_number' ),
		'name'     => array( 'friendlytitle', 'productname', 'product_name', 'name', 'title', 'short_description', 'description' ),
		'price'    => array( 'retail_price', 'retailpriceinctax', 'retailprice', 'price', 'customerpricewithtax', 'customerprice', 'dealer_price' ),
		'stock'    => array( 'total_stock', 'totalwarehousestock', 'total_warehouse_stock', 'qty', 'quantity', 'stock', 'totalstock', 'amount' ),
		'brand'    => array( 'brand', 'manufacturer', 'make' ),
		'image'    => array( 'image_url', 'imageurl', 'image_link', 'imagelink', 'link', 'image', 'img', 'picture' ),
		'category' => array( 'categoryname', 'category_name', 'category', 'publishingcategory', 'department', 'producttype', 'category_description' ),
		'desc'     => array( 'detaileddescription', 'detailed_description', 'longdescription', 'productinformationforweb', 'stock_notes', 'description' ),
		'length'   => array( 'length', 'len' ),
		'width'    => array( 'width', 'breadth' ),
		'height'   => array( 'height', 'depth' ),
		'weight'   => array( 'mass', 'weight' ),
		'dimunit'  => array( 'dimensionunit', 'dimunit', 'lengthunit' ),
		'wtunit'   => array( 'massunit', 'weightunit' ),
	);

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'cron_run' ) );
		add_action( self::PREP_HOOK, array( $this, 'prepare_run' ) );
		add_action( self::RUNNER_HOOK, array( $this, 'process_batch' ) );
		add_action( self::WATCH_HOOK, array( $this, 'watchdog' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_intervals' ) );
		add_action( 'init', array( $this, 'reconcile_schedule' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_cm_xmlfeed_test', array( $this, 'ajax_test' ) );
			add_action( 'wp_ajax_cm_xmlfeed_preview', array( $this, 'ajax_preview' ) );
			add_action( 'wp_ajax_cm_xmlfeed_start', array( $this, 'ajax_start' ) );
			add_action( 'wp_ajax_cm_xmlfeed_status', array( $this, 'ajax_status' ) );
			add_action( 'wp_ajax_cm_xmlfeed_stop', array( $this, 'ajax_stop' ) );
			add_action( 'wp_ajax_cm_xmlfeed_purge', array( $this, 'ajax_purge' ) );
		}
	}

	public function cron_intervals( $schedules ) {
		if ( ! isset( $schedules['cm_minute'] ) ) {
			$schedules['cm_minute'] = array( 'interval' => 60, 'display' => 'Every minute (Corpmerch)' );
		}
		return $schedules;
	}

	/** Source registry. */
	public static function sources() {
		return array(
			'scoop'  => array(
				'label'     => 'Scoop',
				'url'       => 'https://scoop.co.za/scoop_pricelist.xml',
				'item'      => 'product',
				'cat_split' => '',
			),
			'parrot' => array(
				'label'      => 'Parrot',
				'url'        => 'https://accounts.parrotproducts.biz//PublicWebServices/Customers.svc/GetCustomerProductFeed/1/pZ9dQDit/xml/Parrot_Products_Feed.xml',
				'item'       => 'ProductInformationForWeb',
				'cat_split'  => '>',
				'variations' => true, // group same-title rows differing only by colour/size.
			),
			'micropoint' => array(
				'label'     => 'Micropoint',
				'url'       => 'https://www.micropointsa.co.za/xml/xml.php?xmlKey=%20899e6ed98ba346b89385cfb067ff1e05ad32ffd3',
				'item'      => 'item',
				'cat_split' => '',
			),
		);
	}

	private function cfg( $source ) {
		$s = self::sources();
		if ( ! isset( $s[ $source ] ) ) {
			return null;
		}
		$c         = $s[ $source ];
		$c['_src'] = (string) $source;
		return $c;
	}

	/** Per-source option helpers: prefix is the source key. */
	private function opt( $source, $key, $default = '' ) {
		return corpmerch_option( $source . '_' . $key, $default );
	}
	private function boolopt( $source, $key, $default_on ) {
		return corpmerch_bool( $source . '_' . $key, $default_on );
	}

	/* ------------------------------------------------------------- feed I/O */

	private function get_feed( $source ) {
		$cfg = $this->cfg( $source );
		if ( ! $cfg ) {
			return new WP_Error( 'cm_xmlfeed', 'Unknown feed.' );
		}
		$res = wp_remote_get(
			$cfg['url'],
			array(
				'timeout'   => 120,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/xml, text/xml, */*' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'cm_xmlfeed', sprintf( 'HTTP %d from %s feed.', $code, $cfg['label'] ) );
		}
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'cm_xmlfeed', sprintf( 'Empty body from %s feed.', $cfg['label'] ) );
		}
		return $body;
	}

	/** DOM-parse the feed body into normalised rows. */
	private function parse_feed( $body, $source ) {
		$cfg  = $this->cfg( $source );
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $body, LIBXML_PARSEHUGE | LIBXML_NOENT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return array();
		}
		$items = $this->find_items( $dom, $cfg['item'] );
		$rows  = array();
		foreach ( $items as $item ) {
			$vals = array();
			foreach ( $item->getElementsByTagName( '*' ) as $el ) {
				$ln = strtolower( $el->localName );
				if ( ! isset( $vals[ $ln ] ) ) {
					$t = trim( $el->textContent );
					if ( '' !== $t ) {
						$vals[ $ln ] = $t;
					}
				}
			}
			$sku = $this->pick( $vals, 'sku' );
			if ( '' === $sku ) {
				continue;
			}
			$cat = $this->pick( $vals, 'category' );
			// Micropoint: category_name is a short code (e.g. "STO"). Prefer the
			// broader readable category_description (~84 conceptual categories)
			// over group_description (~178, mixes specs/brands).
			if ( 'micropoint' === $source ) {
				if ( ! empty( $vals['category_description'] ) ) {
					$cat = $vals['category_description'];
				} elseif ( ! empty( $vals['group_description'] ) ) {
					$cat = $vals['group_description'];
				}
			}
			$rows[] = array(
				'source' => $source,
				'sku'    => $sku,
				'name'   => $this->pick( $vals, 'name' ),
				'price'  => $this->pick( $vals, 'price' ),
				'stock'  => (int) round( (float) $this->pick( $vals, 'stock' ) ),
				'brand'  => $this->pick( $vals, 'brand' ),
				'image'  => $this->pick( $vals, 'image' ),
				'cat'    => $cat,
				'desc'   => $this->pick( $vals, 'desc' ),
				'length' => $this->pick( $vals, 'length' ),
				'width'  => $this->pick( $vals, 'width' ),
				'height' => $this->pick( $vals, 'height' ),
				'weight' => $this->pick( $vals, 'weight' ),
				'dimunit' => $this->pick( $vals, 'dimunit' ),
				'wtunit'  => $this->pick( $vals, 'wtunit' ),
			);
		}
		return $rows;
	}

	/** First non-empty value among a logical field's candidate tags. */
	private function pick( $vals, $field ) {
		foreach ( self::MAP[ $field ] as $cand ) {
			if ( isset( $vals[ $cand ] ) && '' !== $vals[ $cand ] ) {
				return $vals[ $cand ];
			}
		}
		return '';
	}

	/**
	 * Locate the repeating product element. Prefer the configured tag (matched
	 * by local name, namespace-tolerant); fall back to the most frequent
	 * element that has element children.
	 */
	private function find_items( $dom, $item_tag ) {
		$want    = strtolower( $item_tag );
		$matched = array();
		$bucket  = array();
		foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
			$has_child = false;
			foreach ( $el->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType ) {
					$has_child = true;
					break;
				}
			}
			if ( ! $has_child ) {
				continue;
			}
			$ln = strtolower( $el->localName );
			if ( $ln === $want ) {
				$matched[] = $el;
			}
			$bucket[ $ln ][] = $el;
		}
		if ( ! empty( $matched ) ) {
			return $matched;
		}
		$best = array();
		foreach ( $bucket as $els ) {
			if ( count( $els ) > count( $best ) ) {
				$best = $els;
			}
		}
		return $best;
	}

	/* --------------------------------------------------------------- helpers */

	private function price( $raw, $source ) {
		$markup = (float) $this->opt( $source, 'markup', 0 );
		$val    = (float) $raw;
		if ( $markup ) {
			$val = $val * ( 1 + $markup / 100 );
		}
		return $val > 0 ? wc_format_decimal( $val, wc_get_price_decimals() ) : '';
	}

	private function find_existing( $sku, $source ) {
		$q = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'  => array(
					'relation' => 'AND',
					array( 'key' => '_xmlfeed_source', 'value' => $source ),
					array( 'key' => '_xmlfeed_code', 'value' => (string) $sku ),
				),
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	private function unique_sku( $sku, $existing_id ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return '';
		}
		$found = wc_get_product_id_by_sku( $sku );
		return ( ! $found || (int) $found === (int) $existing_id ) ? $sku : '';
	}

	private function term_id_by_name( $name, $taxonomy, $parent = 0 ) {
		$name = trim( (string) $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'parent'     => (int) $parent,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return (int) $terms[0];
		}
		$new = wp_insert_term( $name, $taxonomy, array( 'parent' => (int) $parent ) );
		return ( ! is_wp_error( $new ) && isset( $new['term_id'] ) ) ? (int) $new['term_id'] : 0;
	}

	/** Route the feed category through the Book6 map — never create a term. */
	private function assign_category( $product_id, $row, $cfg ) {
		$raw = trim( (string) $row['cat'] );
		update_post_meta( $product_id, '_xmlfeed_category', $raw );
		$xparts = ( '' !== $cfg['cat_split'] ) ? array_filter( array_map( 'trim', explode( $cfg['cat_split'], $raw ) ) ) : array( $raw );
		$xleaf  = ! empty( $xparts ) ? end( $xparts ) : $raw;
		update_post_meta( $product_id, '_cm_feed_cat', $xleaf );
		update_post_meta( $product_id, '_cm_feed_src', ! empty( $cfg['_src'] ) ? (string) $cfg['_src'] : 'xmlfeed' );
		update_post_meta( $product_id, 'cm_feed_cat', $xleaf );
		update_post_meta( $product_id, 'cm_feed_src', ! empty( $cfg['_src'] ) ? (string) $cfg['_src'] : 'xmlfeed' );
		if ( ! class_exists( 'Corpmerch_Cat_Map' ) ) {
			return;
		}
		$parts  = ( '' !== $cfg['cat_split'] ) ? array_filter( array_map( 'trim', explode( $cfg['cat_split'], $raw ) ) ) : array( $raw );
		$labels = array_values( array_reverse( $parts ) ); // leaf first.
		if ( '' !== $raw && ! in_array( $raw, $labels, true ) ) {
			$labels[] = $raw;
		}
		foreach ( $labels as $l ) {
			Corpmerch_Cat_Map::record( $l );
		}
		$assign = Corpmerch_Cat_Map::resolve_any( $labels );
		if ( ! $assign ) {
			$assign = Corpmerch_Cat_Map::unmapped_term_id();
		}
		if ( $assign ) {
			wp_set_object_terms( $product_id, array( (int) $assign ), 'product_cat', false );
		}
	}

	private function assign_brand( $product_id, $brand ) {
		$brand = trim( (string) $brand );
		if ( '' === $brand ) {
			return;
		}
		update_post_meta( $product_id, '_xmlfeed_brand', $brand );
		foreach ( array( 'product_brand', 'pwb-brand', 'pa_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$tid = $this->term_id_by_name( $brand, $tax );
				if ( $tid ) {
					wp_set_object_terms( $product_id, array( $tid ), $tax );
				}
				break;
			}
		}
	}

	private function ext_from_mime( $mime ) {
		$mime = strtolower( (string) $mime );
		$map  = array( 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' );
		foreach ( $map as $m => $e ) {
			if ( false !== strpos( $mime, $m ) ) {
				return $e;
			}
		}
		return '';
	}

	/**
	 * Download an image and attach it. Handles extensionless URLs (e.g. Parrot
	 * .svc image endpoints) by detecting the type from the response.
	 * Deduped by source URL.
	 */
	private function sideload( $url, $source, $parent = 0 ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! $this->boolopt( $source, 'import_images', true ) ) {
			return 0;
		}
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_xmlfeed_src', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}
		$res = wp_remote_get( $url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return 0;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 || '' === $body ) {
			return 0;
		}
		$ext = $this->ext_from_mime( wp_remote_retrieve_header( $res, 'content-type' ) );
		if ( '' === $ext ) {
			$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $info && ! empty( $info['mime'] ) ) {
				$ext = $this->ext_from_mime( $info['mime'] );
			}
		}
		if ( '' === $ext ) {
			return 0; // not an image.
		}
		$base = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $base || ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $base ) ) {
			$base = ( '' !== $base ? $base : 'image' ) . '.' . $ext;
		}
		$up = wp_upload_bits( $base, null, $body );
		if ( ! empty( $up['error'] ) || empty( $up['file'] ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ft  = wp_check_filetype( $up['file'] );
		$id  = wp_insert_attachment(
			array(
				'post_mime_type' => $ft['type'] ? $ft['type'] : 'image/' . $ext,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $base ),
				'post_status'    => 'inherit',
			),
			$up['file'],
			$parent
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		update_post_meta( $id, '_xmlfeed_src', $url );
		return (int) $id;
	}

	private function signature( $row, $source ) {
		return md5(
			implode(
				"\n",
				array(
					$row['name'], $row['cat'], $row['brand'], $row['image'],
					md5( $row['desc'] ),
					$row['length'], $row['width'], $row['height'], $row['weight'], $row['dimunit'], $row['wtunit'],
					(string) $this->boolopt( $source, 'import_images', true ),
					(string) $this->opt( $source, 'status', 'draft' ),
				)
			)
		);
	}

	private function apply_stock( $obj, $row, $manage ) {
		if ( ! $obj || ! $manage ) {
			return;
		}
		$qty = (int) $row['stock'];
		$obj->set_manage_stock( true );
		$obj->set_stock_quantity( $qty );
		$obj->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
	}

	private function fast_update( $product_id, $row, $source, $manage ) {
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return;
		}
		$price = $this->price( $row['price'], $source );
		if ( '' !== $price ) {
			$p->set_regular_price( $price );
		}
		$this->apply_stock( $p, $row, $manage );
		$p->save();
	}

	/* ------------------------------------------------- variation grouping */

	/**
	 * Turn flat rows into queue items. For sources flagged 'variations', rows
	 * that share a title once a trailing colour/size token is removed are
	 * grouped into ONE variable product; everything else stays simple.
	 */
	private function build_items( $rows, $source ) {
		$cfg = $this->cfg( $source );
		if ( empty( $cfg['variations'] ) ) {
			return array_map(
				static function ( $r ) {
					return array( 'kind' => 'simple', 'row' => $r );
				},
				$rows
			);
		}

		$groups = array();
		foreach ( $rows as $r ) {
			list( $base, $colour, $size ) = $this->detect_variant( $r['name'] );
			$r['_colour'] = $colour;
			$r['_size']   = $size;
			$key          = strtolower( $base );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'base' => $base, 'members' => array() );
			}
			$groups[ $key ]['members'][] = $r;
		}

		$items = array();
		foreach ( $groups as $key => $g ) {
			$members  = $g['members'];
			$colours  = array();
			$sizes    = array();
			$alltoken = true;
			foreach ( $members as $m ) {
				if ( '' !== $m['_colour'] ) {
					$colours[ $m['_colour'] ] = 1;
				}
				if ( '' !== $m['_size'] ) {
					$sizes[ $m['_size'] ] = 1;
				}
				if ( '' === $m['_colour'] && '' === $m['_size'] ) {
					$alltoken = false;
				}
			}
			$axis_colour = count( $colours ) > 1;
			$axis_size   = count( $sizes ) > 1;

			if ( count( $members ) >= 2 && $alltoken && ( $axis_colour || $axis_size ) ) {
				$items[] = array(
					'kind'       => 'variable',
					'base'       => $g['base'],
					'group'      => md5( $source . '|' . $key ),
					'axisColour' => $axis_colour,
					'axisSize'   => $axis_size,
					'members'    => array_values( $members ),
				);
			} else {
				foreach ( $members as $m ) {
					$items[] = array( 'kind' => 'simple', 'row' => $m );
				}
			}
		}
		return $items;
	}

	/** Strip a trailing colour and/or size token; return [base, colour, size]. */
	private function detect_variant( $title ) {
		$t      = trim( (string) $title );
		$colour = '';
		$size   = '';
		$cols   = 'black|white|red|blue|green|yellow|orange|purple|pink|grey|gray|silver|gold|brown|clear|beige|navy|maroon|turquoise|lime|cream|charcoal|violet|teal|ivory|tan|bronze|copper';
		$mod    = 'lime|navy|sky|dark|light|hot|royal|baby|forest|electric';

		if ( preg_match( '/^(.*?)\s*[-–|(]\s*((?:(?:' . $mod . ')\s+)?(?:' . $cols . '))\s*\)?\s*$/i', $t, $m ) ) {
			$colour = ucwords( strtolower( trim( $m[2] ) ) );
			$t      = trim( $m[1] );
		}
		if ( preg_match( '/^(.*?)\s*[-–|(]\s*(xxl|xl|x-large|extra\s*large|large|medium|small|\d+(?:\.\d+)?\s?(?:mm|cm|ml|gsm|kg|inch|in|"|a[0-9]))\s*\)?\s*$/i', $t, $m2 ) ) {
			$size = trim( $m2[2] );
			$t    = trim( $m2[1] );
		}
		return array( $this->tidy_base( $t ), $colour, $size );
	}

	/** Drop trailing separators and balance any unclosed parenthesis. */
	private function tidy_base( $t ) {
		$t = preg_replace( '/\s*[-–|(]+\s*$/u', '', (string) $t );
		$t = trim( $t );
		$open  = substr_count( $t, '(' );
		$close = substr_count( $t, ')' );
		if ( $open > $close ) {
			$t .= str_repeat( ')', $open - $close );
		}
		return trim( $t );
	}

	/** Codes to keep at prune time: simple SKUs + variable group keys + member SKUs. */
	private function seen_codes( $items ) {
		$seen = array();
		foreach ( $items as $it ) {
			if ( isset( $it['kind'] ) && 'variable' === $it['kind'] ) {
				$seen[] = 'grp_' . $it['group'];
				foreach ( $it['members'] as $m ) {
					$seen[] = (string) $m['sku'];
				}
			} else {
				$seen[] = (string) $it['row']['sku'];
			}
		}
		return $seen;
	}

	private function import_item( $item, $source ) {
		if ( isset( $item['kind'] ) && 'variable' === $item['kind'] ) {
			return $this->import_variable( $item, $source );
		}
		return $this->import_row( $item['row'], $source );
	}

	private function find_existing_group( $group, $source ) {
		$q = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'  => array(
					'relation' => 'AND',
					array( 'key' => '_xmlfeed_source', 'value' => $source ),
					array( 'key' => '_xmlfeed_group', 'value' => $group ),
				),
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	/** Build/refresh one variable product + its variations. */
	private function import_variable( $item, $source ) {
		if ( ! class_exists( 'WC_Product_Variable' ) || empty( $item['members'] ) ) {
			return array( 'id' => 0, 'mode' => 'skipped', 'name' => '' );
		}
		$cfg      = $this->cfg( $source );
		$group    = $item['group'];
		$existing = $this->find_existing_group( $group, $source );
		$status   = $this->opt( $source, 'status', 'draft' );
		$status   = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
		$manage   = $this->boolopt( $source, 'manage_stock', true );

		$product = $existing ? wc_get_product( $existing ) : null;
		if ( ! $product instanceof WC_Product_Variable ) {
			$product = new WC_Product_Variable();
		}
		$first = $item['members'][0];
		$product->set_name( '' !== $item['base'] ? $item['base'] : $cfg['label'] . ' ' . $group );
		$product->set_status( $status );
		if ( '' !== $first['desc'] ) {
			$product->set_description( $first['desc'] );
		}

		$attrs = array();
		$pos   = 0;
		if ( ! empty( $item['axisColour'] ) ) {
			$vals = array();
			foreach ( $item['members'] as $m ) {
				if ( '' !== $m['_colour'] && ! in_array( $m['_colour'], $vals, true ) ) {
					$vals[] = $m['_colour'];
				}
			}
			if ( $vals ) {
				$a = new WC_Product_Attribute();
				$a->set_id( 0 );
				$a->set_name( 'Colour' );
				$a->set_options( $vals );
				$a->set_position( $pos++ );
				$a->set_visible( true );
				$a->set_variation( true );
				$attrs[] = $a;
			}
		}
		if ( ! empty( $item['axisSize'] ) ) {
			$vals = array();
			foreach ( $item['members'] as $m ) {
				if ( '' !== $m['_size'] && ! in_array( $m['_size'], $vals, true ) ) {
					$vals[] = $m['_size'];
				}
			}
			if ( $vals ) {
				$a = new WC_Product_Attribute();
				$a->set_id( 0 );
				$a->set_name( 'Size' );
				$a->set_options( $vals );
				$a->set_position( $pos++ );
				$a->set_visible( true );
				$a->set_variation( true );
				$attrs[] = $a;
			}
		}
		if ( $attrs ) {
			$product->set_attributes( $attrs );
		}

		$pimg = $this->sideload( $first['image'], $source );
		if ( $pimg ) {
			$product->set_image_id( $pimg );
		}
		corpmerch_apply_dimensions( $product, $first['length'], $first['width'], $first['height'], $first['dimunit'], $first['weight'], $first['wtunit'] );

		$pid = $product->save();
		if ( ! $pid ) {
			return array( 'id' => 0, 'mode' => 'skipped', 'name' => $item['base'] );
		}
		update_post_meta( $pid, '_xmlfeed_group', $group );
		update_post_meta( $pid, '_xmlfeed_source', $source );
		update_post_meta( $pid, '_xmlfeed_code', 'grp_' . $group );

		foreach ( $item['members'] as $m ) {
			$this->upsert_variation( $pid, $m, $source, $manage, $item );
		}

		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );
		$this->assign_category( $pid, $first, $cfg );
		$this->assign_brand( $pid, $first['brand'] );

		return array( 'id' => $pid, 'mode' => $existing ? 'refreshed' : 'created', 'name' => $product->get_name() );
	}

	/** Create/refresh one variation under $parent_id, keyed by member SKU. */
	private function upsert_variation( $parent_id, $m, $source, $manage, $item ) {
		$sku = trim( (string) $m['sku'] );
		$vid = 0;
		if ( '' !== $sku ) {
			$found = wc_get_product_id_by_sku( $sku );
			if ( $found ) {
				$fp = wc_get_product( $found );
				if ( $fp && $fp->is_type( 'variation' ) && (int) $fp->get_parent_id() === (int) $parent_id ) {
					$vid = (int) $found;
				} elseif ( $fp && (string) get_post_meta( $found, '_xmlfeed_source', true ) === $source ) {
					// An older simple product from this feed owns the SKU — free it.
					wp_delete_post( $found, true );
				}
			}
		}
		$var = $vid ? wc_get_product( $vid ) : null;
		if ( ! $var instanceof WC_Product_Variation ) {
			$var = new WC_Product_Variation();
		}
		$var->set_parent_id( $parent_id );

		$a = array();
		if ( ! empty( $item['axisColour'] ) && '' !== $m['_colour'] ) {
			$a['colour'] = $m['_colour'];
		}
		if ( ! empty( $item['axisSize'] ) && '' !== $m['_size'] ) {
			$a['size'] = $m['_size'];
		}
		$var->set_attributes( $a );

		$price = $this->price( $m['price'], $source );
		if ( '' !== $price ) {
			$var->set_regular_price( $price );
		}
		if ( $manage ) {
			$q = (int) $m['stock'];
			$var->set_manage_stock( true );
			$var->set_stock_quantity( $q );
			$var->set_stock_status( $q > 0 ? 'instock' : 'outofstock' );
		}
		$u = $this->unique_sku( $sku, $vid );
		if ( '' !== $u ) {
			$var->set_sku( $u );
		}
		$img = $this->sideload( $m['image'], $source );
		if ( $img ) {
			$var->set_image_id( $img );
		}
		corpmerch_apply_dimensions( $var, $m['length'], $m['width'], $m['height'], $m['dimunit'], $m['weight'], $m['wtunit'] );

		$vid = $var->save();
		if ( $vid ) {
			update_post_meta( $vid, '_xmlfeed_code', $sku );
			update_post_meta( $vid, '_xmlfeed_source', $source );
		}
		return $vid;
	}

	public function import_row( $row, $source ) {
		if ( ! class_exists( 'WC_Product_Simple' ) || empty( $row['sku'] ) ) {
			return array( 'id' => 0, 'mode' => 'skipped', 'name' => '' );
		}
		$cfg      = $this->cfg( $source );
		$sku      = $row['sku'];
		$existing = $this->find_existing( $sku, $source );
		$sig      = $this->signature( $row, $source );
		$manage   = $this->boolopt( $source, 'manage_stock', true );

		if ( $existing && (string) get_post_meta( $existing, '_xmlfeed_sig', true ) === $sig ) {
			$this->fast_update( $existing, $row, $source, $manage );
			return array( 'id' => $existing, 'mode' => 'updated', 'name' => get_the_title( $existing ) );
		}

		$status  = $this->opt( $source, 'status', 'draft' );
		$status  = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
		$product = $existing ? wc_get_product( $existing ) : null;
		if ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple();
		}
		$product->set_name( '' !== $row['name'] ? $row['name'] : $cfg['label'] . ' ' . $sku );
		$product->set_status( $status );
		if ( '' !== $row['desc'] ) {
			$product->set_description( $row['desc'] );
		}
		$uniq = $this->unique_sku( $sku, $existing );
		if ( '' !== $uniq ) {
			$product->set_sku( $uniq );
		}
		$price = $this->price( $row['price'], $source );
		if ( '' !== $price ) {
			$product->set_regular_price( $price );
		}
		$this->apply_stock( $product, $row, $manage );
		corpmerch_apply_dimensions( $product, $row['length'], $row['width'], $row['height'], $row['dimunit'], $row['weight'], $row['wtunit'] );
		$img = $this->sideload( $row['image'], $source );
		if ( $img ) {
			$product->set_image_id( $img );
		}
		$pid = $product->save();
		if ( ! $pid ) {
			return array( 'id' => 0, 'mode' => 'skipped', 'name' => $row['name'] );
		}
		update_post_meta( $pid, '_xmlfeed_code', (string) $sku );
		update_post_meta( $pid, '_xmlfeed_source', $source );
		update_post_meta( $pid, '_xmlfeed_sig', $sig );
		$this->assign_category( $pid, $row, $cfg );
		$this->assign_brand( $pid, $row['brand'] );
		return array( 'id' => $pid, 'mode' => $existing ? 'refreshed' : 'created', 'name' => $product->get_name() );
	}

	/* --------------------------------------------------------------- pruning */

	private function imported_ids( $source ) {
		return get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'  => array(
					array( 'key' => '_xmlfeed_source', 'value' => $source ),
				),
			)
		);
	}

	private function prune( $source, $seen_skus ) {
		if ( ! $this->boolopt( $source, 'prune', false ) ) {
			return 0;
		}
		$action = $this->opt( $source, 'prune_action', 'trash' );
		$seen   = array_fill_keys( array_map( 'strval', (array) $seen_skus ), true );
		$n      = 0;
		foreach ( $this->imported_ids( $source ) as $pid ) {
			$c = (string) get_post_meta( $pid, '_xmlfeed_code', true );
			if ( '' === $c || isset( $seen[ $c ] ) ) {
				continue;
			}
			if ( 'draft' === $action ) {
				wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
			} else {
				wp_trash_post( $pid );
			}
			$n++;
		}
		return $n;
	}

	/* --------------------------------------------------------------- runner */

	private function state() {
		$d = array(
			'status' => 'idle', 'source' => '', 'total' => 0, 'offset' => 0,
			'created' => 0, 'updated' => 0, 'refreshed' => 0, 'pruned' => 0, 'errors' => 0,
			'started' => 0, 'heartbeat' => 0, 'message' => '', 'recent' => array(),
		);
		$s = get_option( self::STATE_KEY, array() );
		return is_array( $s ) ? array_merge( $d, $s ) : $d;
	}

	private function save_state( $s ) {
		update_option( self::STATE_KEY, $s, false );
	}

	private function raise_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore
	}

	private function start_run( $source ) {
		if ( ! $this->cfg( $source ) ) {
			return new WP_Error( 'cm_xmlfeed', 'Unknown feed.' );
		}
		if ( corpmerch_sync_paused() ) {
			return new WP_Error( 'cm_xmlfeed', 'All feed syncs are paused. Resume them on the Product feeds page first.' );
		}
		// Defer the heavy fetch/parse/build to a background cron event so the
		// AJAX request returns instantly and never hits the gateway timeout.
		delete_option( self::QUEUE_KEY );
		delete_transient( self::LOCK_KEY );
		$this->save_state(
			array(
				'status'  => 'preparing', 'source' => $source, 'total' => 0, 'offset' => 0,
				'created' => 0, 'updated' => 0, 'refreshed' => 0, 'pruned' => 0, 'errors' => 0,
				'started' => time(), 'heartbeat' => time(),
				'message' => 'Fetching and parsing feed…', 'recent' => array(),
			)
		);
		if ( ! wp_next_scheduled( self::PREP_HOOK, array( $source ) ) ) {
			wp_schedule_single_event( time(), self::PREP_HOOK, array( $source ) );
		}
		spawn_cron();
		return 0;
	}

	public function prepare_run( $source ) {
		if ( ! $this->cfg( $source ) ) {
			return;
		}
		$s = $this->state();
		if ( 'preparing' !== $s['status'] || $source !== $s['source'] ) {
			return;
		}
		$this->raise_limits();
		$body = $this->get_feed( $source );
		if ( is_wp_error( $body ) ) {
			$s['status']  = 'error';
			$s['message'] = $body->get_error_message();
			$this->save_state( $s );
			return;
		}
		$rows = $this->parse_feed( $body, $source );
		unset( $body );
		if ( empty( $rows ) ) {
			$s['status']  = 'error';
			$s['message'] = 'No products found in the feed.';
			$this->save_state( $s );
			return;
		}
		$items = $this->build_items( $rows, $source );
		unset( $rows );
		update_option( self::QUEUE_KEY, $items, false );
		$s['status']    = 'running';
		$s['total']     = count( $items );
		$s['heartbeat'] = time();
		$s['message']   = 'Queued ' . count( $items ) . ' products.';
		$this->save_state( $s );
		delete_transient( self::LOCK_KEY );
		$this->kick();
	}

	private function kick() {
		if ( ! wp_next_scheduled( self::RUNNER_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUNNER_HOOK );
		}
		spawn_cron();
	}

	public function process_batch() {
		$s = $this->state();
		if ( 'running' !== $s['status'] ) {
			return;
		}
		if ( corpmerch_sync_paused() ) {
			delete_transient( self::LOCK_KEY );
			return;
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );
		$this->raise_limits();

		$source = $s['source'];
		$items  = get_option( self::QUEUE_KEY );
		if ( ! is_array( $items ) || ! $this->cfg( $source ) ) {
			$s['status']  = 'error';
			$s['message'] = 'Import queue missing — start again.';
			$this->save_state( $s );
			delete_transient( self::LOCK_KEY );
			return;
		}

		$total  = count( $items );
		$batch  = max( 1, min( 50, (int) $this->opt( $source, 'batch', 15 ) ) );
		$budget = time() + 25;

		do {
			$slice = array_slice( $items, $s['offset'], $batch );
			if ( empty( $slice ) ) {
				break;
			}
			foreach ( $slice as $item ) {
				try {
					$res  = $this->import_item( $item, $source );
					$mode = isset( $res['mode'] ) ? $res['mode'] : 'skipped';
					if ( isset( $s[ $mode ] ) ) {
						$s[ $mode ]++;
					}
					if ( ! empty( $res['name'] ) ) {
						array_unshift( $s['recent'], array( 'name' => $res['name'], 'mode' => $mode ) );
						$s['recent'] = array_slice( $s['recent'], 0, 12 );
					}
				} catch ( Throwable $e ) {
					$s['errors']++;
				}
			}
			$s['offset']   += count( $slice );
			$s['heartbeat'] = time();
			$s['message']   = 'Synced ' . $s['offset'] . ' / ' . $total . '.';
			$this->save_state( $s );
		} while ( $s['offset'] < $total && time() < $budget );

		if ( $s['offset'] >= $total ) {
			$s['pruned']  = $this->prune( $source, $this->seen_codes( $items ) );
			$s['status']  = 'done';
			$s['message'] = 'Import complete — ' . $total . ' products.';
			delete_option( self::QUEUE_KEY );
			update_option( self::LOG_KEY, array( 'time' => time(), 'ok' => true, 'msg' => 'Background import', 'count' => $total, 'source' => $source ) );
		}
		$this->save_state( $s );
		delete_transient( self::LOCK_KEY );

		if ( 'running' === $s['status'] ) {
			$this->kick();
		}
	}

	public function watchdog() {
		$s = $this->state();
		if ( 'running' === $s['status'] && ( time() - (int) $s['heartbeat'] ) >= 90 ) {
			delete_transient( self::LOCK_KEY );
			$this->kick();
		}
	}

	public function cron_run() {
		if ( corpmerch_sync_paused() ) {
			return;
		}
		$s = $this->state();
		if ( 'running' === $s['status'] ) {
			return;
		}
		foreach ( array_keys( self::sources() ) as $source ) {
			if ( in_array( $this->opt( $source, 'schedule', 'off' ), array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
				$this->start_run( $source );
				return; // one source per cron tick; the next tick picks up the other.
			}
		}
	}

	/** Hard-stop this engine. */
	public static function halt() {
		wp_clear_scheduled_hook( self::RUNNER_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::QUEUE_KEY );
		delete_transient( self::LOCK_KEY );
		$s = get_option( self::STATE_KEY, array() );
		if ( is_array( $s ) ) {
			$s['status']  = 'idle';
			$s['message'] = 'Stopped by “Stop all syncs”.';
			update_option( self::STATE_KEY, $s, false );
		}
	}

	public function reconcile_schedule() {
		if ( ! wp_next_scheduled( self::WATCH_HOOK ) ) {
			wp_schedule_event( time() + 60, 'cm_minute', self::WATCH_HOOK );
		}
		// Schedule the auto-sync poller if any source requests a schedule.
		$any = false;
		foreach ( array_keys( self::sources() ) as $source ) {
			if ( in_array( $this->opt( $source, 'schedule', 'off' ), array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
				$any = true;
				break;
			}
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $any ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	/* ----------------------------------------------------------------- AJAX */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cm_xmlfeed', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Permission denied.' ) );
		}
	}

	private function req_source() {
		$s   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$all = array_keys( self::sources() );
		return in_array( $s, $all, true ) ? $s : $all[0];
	}

	public function ajax_test() {
		$this->guard();
		$this->raise_limits();
		$source = $this->req_source();
		$body   = $this->get_feed( $source );
		if ( is_wp_error( $body ) ) {
			wp_send_json_error( array( 'msg' => $body->get_error_message() ) );
		}
		$n = count( $this->parse_feed( $body, $source ) );
		wp_send_json_success( array( 'msg' => $this->cfg( $source )['label'] . ' feed OK — ' . $n . ' products.' ) );
	}

	public function ajax_preview() {
		$this->guard();
		$this->raise_limits();
		$source = $this->req_source();
		$body   = $this->get_feed( $source );
		if ( is_wp_error( $body ) ) {
			wp_send_json_error( array( 'msg' => $body->get_error_message() ) );
		}
		$rows   = $this->parse_feed( $body, $source );
		$sample = array_slice( $rows, 0, 3 );
		wp_send_json_success(
			array(
				'source'   => $source,
				'products' => count( $rows ),
				'stock_ok' => ! empty( $rows ),
				'sample'   => $sample,
			)
		);
	}

	public function ajax_start() {
		$this->guard();
		$total = $this->start_run( $this->req_source() );
		if ( is_wp_error( $total ) ) {
			wp_send_json_error( array( 'msg' => $total->get_error_message() ) );
		}
		wp_send_json_success( array( 'total' => $total ) );
	}

	public function ajax_status() {
		$this->guard();
		$s = $this->state();
		if ( 'preparing' === $s['status'] ) {
			if ( ! wp_next_scheduled( self::PREP_HOOK, array( $s['source'] ) ) ) {
				wp_schedule_single_event( time(), self::PREP_HOOK, array( $s['source'] ) );
			}
			spawn_cron();
		}
		if ( 'running' === $s['status'] ) {
			if ( ( time() - (int) $s['heartbeat'] ) >= 90 ) {
				delete_transient( self::LOCK_KEY );
			}
			$this->kick();
		}
		wp_send_json_success( $s );
	}

	public function ajax_stop() {
		$this->guard();
		wp_clear_scheduled_hook( self::RUNNER_HOOK );
		$s0 = $this->state();
		if ( ! empty( $s0['source'] ) ) {
			wp_clear_scheduled_hook( self::PREP_HOOK, array( $s0['source'] ) );
		}
		delete_option( self::QUEUE_KEY );
		delete_transient( self::LOCK_KEY );
		$s            = $this->state();
		$s['status']  = 'idle';
		$s['message'] = 'Import stopped.';
		$this->save_state( $s );
		wp_send_json_success( array( 'msg' => 'Import stopped.' ) );
	}

	public function ajax_purge() {
		$this->guard();
		$source = $this->req_source();
		$ids    = $this->imported_ids( $source );
		foreach ( $ids as $pid ) {
			wp_trash_post( $pid );
		}
		wp_send_json_success( array( 'count' => count( $ids ) ) );
	}
}

new Corpmerch_Feed_XML();
