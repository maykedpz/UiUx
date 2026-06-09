<?php
/**
 * Corpmerch Google Merchant product feed (Step 9).
 *
 * Serves a valid Google Shopping RSS 2.0 feed (with the g: namespace) at a
 * stable URL: /product-feed.xml  (query fallback: ?corpmerch_feed=1).
 *
 * Each entry includes: id, title, description, link, image_link (+ additional),
 * availability, price + sale_price, brand, gtin/mpn, condition, product_type,
 * item_group_id (variations) and a default shipping line. Image links inherit
 * the Bunny CDN rewrite automatically via wp_get_attachment_image_url().
 *
 * Gated by the "Product Feed" section on the SEO & Feed tab — a no-op until the
 * owner enables it, so the theme is safe out of the box.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Feed {

	const QV   = 'corpmerch_feed';
	const SLUG = 'product-feed.xml';

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_action( 'after_switch_theme', array( $this, 'flush' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/** Stable, shareable feed URL for the Merchant Center "scheduled fetch". */
	public static function url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::SLUG );
		}
		return home_url( '/?' . self::QV . '=1' );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^' . preg_quote( self::SLUG, '#' ) . '$', 'index.php?' . self::QV . '=1', 'top' );
	}

	public function query_var( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Register the rule then flush, so the pretty URL works on activation. */
	public function flush() {
		$this->add_rewrite();
		flush_rewrite_rules();
	}

	private function enabled() {
		return corpmerch_bool( 'feed_enable', false ) && function_exists( 'wc_get_products' );
	}

	public function maybe_render() {
		$hit = ( '1' === (string) get_query_var( self::QV ) )
			|| isset( $_GET[ self::QV ] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $hit ) {
			return;
		}
		if ( ! $this->enabled() ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		$this->render();
		exit;
	}

	/* --------------------------------------------------------------- render */

	private function render() {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ) );
			nocache_headers();
		}
		// Large catalogs: keep memory flat by walking IDs in pages.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 0 ); // phpcs:ignore

		$title = $this->x( get_bloginfo( 'name' ) );
		$link  = esc_url( home_url( '/' ) );
		$desc  = $this->x( get_bloginfo( 'description' ) );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		echo "<channel>\n";
		echo '<title>' . $title . "</title>\n";
		echo '<link>' . $link . "</link>\n";
		echo '<description>' . $desc . "</description>\n";

		$paged = 1;
		do {
			$q = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => array(
						array(
							'taxonomy' => 'product_visibility',
							'field'    => 'name',
							'terms'    => 'exclude-from-catalog',
							'operator' => 'NOT IN',
						),
					),
				)
			);
			if ( empty( $q->posts ) ) {
				break;
			}
			foreach ( $q->posts as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				$this->render_product( $product );
			}
			$count = count( $q->posts );
			wp_reset_postdata();
			$paged++;
		} while ( $count >= 100 );

		echo "</channel>\n</rss>\n";
	}

	/** Emit one product (simple/parent) or one item per purchasable variation. */
	private function render_product( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$gid = $product->get_id();
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				if ( $variation && $variation->is_purchasable() ) {
					$this->render_item( $variation, $product, $gid );
				}
			}
			return;
		}
		$this->render_item( $product, $product, 0 );
	}

	/**
	 * Flatten product HTML to clean feed text. Converts list items to ", " and
	 * block boundaries to ". " before stripping tags, so the description reads
	 * as sentences instead of running words together (e.g. "bookmark.Features").
	 */
	private function html_to_text( $html ) {
		$html = (string) $html;
		// Drop the "Features" heading entirely; the bullets that follow carry it.
		$html = preg_replace( '#<p>\s*<strong>\s*Features\s*</strong>\s*</p>#i', ' ', $html );
		// List items -> comma separated.
		$html = preg_replace( '#</li>\s*<li>#i', ', ', $html );
		$html = preg_replace( '#<li>#i', ', ', $html );
		// Block ends -> sentence break.
		$html = preg_replace( '#</(p|ul|ol|div|h[1-6])>#i', '. ', $html );
		$text = wp_strip_all_tags( $html );
		// Tidy whitespace and stray punctuation from the substitutions.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = preg_replace( '/\s*([.,])\s*([.,])+/', '$1 ', $text ); // ".," -> ". "
		$text = preg_replace( '/\.\s*,/', '. ', $text );
		$text = preg_replace( '/,\s*\./', '.', $text );
		$text = preg_replace( '/\s+([.,])/', '$1', $text );
		$text = preg_replace( '/([.,])([^\s])/', '$1 $2', $text );
		$text = preg_replace( '/\s{2,}/', ' ', $text );
		return trim( $text );
	}

	/**
	 * @param WC_Product $p      The sellable product (simple or variation).
	 * @param WC_Product $parent Parent for shared fields (== $p for simple).
	 * @param int        $gid    item_group_id (0 = none).
	 */
	private function render_item( $p, $parent, $gid ) {
		// Robust, unique, stable id. Variations of one product share the same
		// SKU (the parent stockcode), so using the SKU alone collides — Google
		// requires a unique id per variant. For variations, always suffix the
		// variation's own WC id, which is unique and stable. Simple products use
		// their SKU (or fall back to their id).
		if ( $gid ) {
			$base = $parent->get_sku() ? $parent->get_sku() : (string) $parent->get_id();
			$id   = $base . '-' . $p->get_id();
		} elseif ( $p->get_sku() ) {
			$id = $p->get_sku();
		} else {
			$id = (string) $p->get_id();
		}
		$link  = get_permalink( $parent->get_id() );
		$title = $p->get_name();

		// Prefer the manual SEO description, then short/long description, then title.
		$desc = trim( (string) get_post_meta( $parent->get_id(), '_cm_seo_desc', true ) );
		if ( '' === $desc ) {
			$desc = $parent->get_short_description() ? $parent->get_short_description() : $parent->get_description();
		}
		$desc = trim( $this->html_to_text( $desc ) );
		if ( '' === $desc ) {
			$desc = $title;
		}
		// Misrepresentation safeguard: the feed/landing price is for the
		// unbranded item; branding (logo application) is quoted separately and
		// affects lead time. Disclose this so the ad price matches expectations.
		if ( apply_filters( 'corpmerch_feed_branding_disclosure', true ) ) {
			$note = 'Price shown is for the unbranded item. Optional logo branding is quoted separately.';
			if ( false === stripos( $desc, 'unbranded' ) ) {
				$desc = rtrim( $desc, '. ' ) . '. ' . $note;
			}
		}

		$image_id = $p->get_image_id() ? $p->get_image_id() : $parent->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		echo "<item>\n";
		$this->tag( 'g:id', $id );
		$this->cdata( 'title', $title );
		$this->cdata( 'description', $desc );
		$this->tag_raw( 'link', esc_url( $link ) );
		if ( $image ) {
			$this->tag_raw( 'g:image_link', esc_url( $image ) );
		}
		// Additional gallery images (up to 10 per Google's limit).
		$extra = 0;
		foreach ( $parent->get_gallery_image_ids() as $gimg ) {
			if ( $gimg === $image_id || $extra >= 10 ) {
				continue;
			}
			$gurl = wp_get_attachment_image_url( $gimg, 'full' );
			if ( $gurl ) {
				$this->tag_raw( 'g:additional_image_link', esc_url( $gurl ) );
				$extra++;
			}
		}

		// Availability.
		$this->tag( 'g:availability', $this->availability( $p ) );

		// Price + sale price (tax-inclusive amounts, "<amount> <CUR>").
		$currency = get_woocommerce_currency();
		$regular  = $this->price_incl( $p, $p->get_regular_price() );
		$active   = $this->price_incl( $p, $p->get_price() );
		if ( $p->is_on_sale() && '' !== $regular && '' !== $active ) {
			$this->tag( 'g:price', $regular . ' ' . $currency );
			$this->tag( 'g:sale_price', $active . ' ' . $currency );
		} elseif ( '' !== $active ) {
			$this->tag( 'g:price', $active . ' ' . $currency );
		}

		// Condition.
		$cond = corpmerch_option( 'feed_condition', 'new' );
		$this->tag( 'g:condition', in_array( $cond, array( 'new', 'refurbished', 'used' ), true ) ? $cond : 'new' );

		// Identifiers: brand / gtin / mpn.
		$brand = $this->brand( $parent );
		if ( '' === $brand ) {
			$brand = corpmerch_option( 'feed_brand_fallback', '' );
		}
		$gtin = $this->gtin( $p );
		$mpn  = $p->get_meta( '_mpn' );
		if ( '' === $mpn ) {
			$mpn = $parent->get_meta( '_mpn' );
		}
		if ( $brand ) {
			$this->cdata( 'g:brand', $brand );
		}
		if ( $gtin ) {
			$this->tag( 'g:gtin', $gtin );
		}
		if ( $mpn ) {
			$this->tag( 'g:mpn', $mpn );
		}
		// Google requires identifier_exists=no when BOTH gtin and mpn are absent
		// (brand alone does not satisfy the unique-product-identifier rule).
		if ( ! $gtin && ! $mpn ) {
			$this->tag( 'g:identifier_exists', 'no' );
		}

		// item_group_id for variations.
		if ( $gid ) {
			$gsku = $parent->get_sku();
			$this->tag( 'g:item_group_id', $gsku ? $gsku : (string) $gid );
			// Variation attributes -> color / size when named accordingly.
			foreach ( $p->get_variation_attributes() as $attr => $value ) {
				$name = strtolower( str_replace( array( 'attribute_pa_', 'attribute_' ), '', $attr ) );
				$val  = $this->attr_label( $attr, $value );
				if ( '' === $val ) {
					continue;
				}
				if ( in_array( $name, array( 'color', 'colour' ), true ) ) {
					$this->cdata( 'g:color', $val );
				} elseif ( 'size' === $name ) {
					$this->cdata( 'g:size', $val );
				}
			}
		}

		// Product type (category path) + Google category passthrough.
		$ptype = $this->product_type( $parent );
		if ( $ptype ) {
			$this->cdata( 'g:product_type', $ptype );
		}
		$gcat = $parent->get_meta( '_google_product_category' );
		if ( '' === $gcat ) {
			$gcat = $this->default_google_category( $parent );
		}
		if ( $gcat ) {
			$this->tag( 'g:google_product_category', $gcat );
		}

		// Apparel: gender + age_group (recommended/required for clothing).
		$gender = $this->apparel_gender( $p, $parent );
		if ( '' === $gender && $this->is_apparel_dept( $parent ) ) {
			$gender = 'unisex'; // Sensible default; Google requires gender for apparel.
		}
		if ( $gender ) {
			$this->tag( 'g:gender', $gender );
			// Google requires age_group alongside gender for apparel; default adult.
			$age = $parent->get_meta( '_feed_age_group' );
			$this->tag( 'g:age_group', $age ? $age : 'adult' );
		}

		// Default shipping line.
		$ship_country = corpmerch_option( 'feed_ship_country', '' );
		$ship_price   = corpmerch_option( 'feed_ship_price', '' );
		if ( $ship_country && '' !== $ship_price ) {
			$amt = wc_format_decimal( $ship_price, wc_get_price_decimals() );
			echo "<g:shipping>\n";
			$this->tag( 'g:country', strtoupper( $ship_country ) );
			$svc = corpmerch_option( 'feed_ship_service', '' );
			if ( $svc ) {
				$this->tag( 'g:service', $svc );
			}
			$this->tag( 'g:price', $amt . ' ' . $currency );
			echo "</g:shipping>\n";
		}

		echo "</item>\n";
	}

	/* --------------------------------------------------------------- helpers */

	private function availability( $p ) {
		if ( ! $p->is_in_stock() ) {
			return 'out_of_stock';
		}
		if ( $p->is_on_backorder() ) {
			return 'backorder';
		}
		return 'in_stock';
	}

	/** Tax-inclusive price for a given raw amount; '' when not numeric. */
	private function price_incl( $p, $amount ) {
		if ( '' === $amount || null === $amount ) {
			return '';
		}
		$value = function_exists( 'wc_get_price_including_tax' )
			? wc_get_price_including_tax( $p, array( 'price' => $amount ) )
			: (float) $amount;
		return wc_format_decimal( $value, wc_get_price_decimals() );
	}

	/**
	 * Resolve apparel gender to Google's allowed values (male/female/unisex)
	 * from a stored meta or a gender attribute; '' when not apparel/unknown.
	 */
	private function apparel_gender( $p, $parent ) {
		$raw = $p->get_meta( '_kevro_gender' );
		if ( '' === $raw ) {
			$raw = $parent->get_meta( '_kevro_gender' );
		}
		if ( '' === $raw ) {
			foreach ( array( 'pa_gender', 'gender' ) as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_the_terms( $parent->get_id(), $tax );
					if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
						$raw = $terms[0]->name;
						break;
					}
				}
			}
		}
		$raw = strtolower( trim( (string) $raw ) );

		// No meta/taxonomy gender: fall back to title + short description text.
		if ( '' === $raw ) {
			$raw = strtolower(
				$parent->get_name() . ' ' . $parent->get_short_description()
			);
			$g = $this->gender_from_text( $raw );
			return $g; // '' when text is gender-neutral; emit block applies unisex default.
		}

		if ( false !== strpos( $raw, 'men' ) && false === strpos( $raw, 'women' ) ) {
			return 'male';
		}
		if ( false !== strpos( $raw, 'women' ) || false !== strpos( $raw, 'ladies' ) || false !== strpos( $raw, 'female' ) ) {
			return 'female';
		}
		if ( false !== strpos( $raw, 'unisex' ) ) {
			return 'unisex';
		}
		return '';
	}

	/**
	 * Infer gender from free text (title/description). Female checked first;
	 * the male pattern uses word boundaries so "women"/"woman" can't match "men".
	 * Returns 'female' | 'male' | 'unisex' | '' (neutral/unknown).
	 */
	private function gender_from_text( $text ) {
		if ( preg_match( '/\bunisex\b/', $text ) ) {
			return 'unisex';
		}
		if ( preg_match( '/ladies|women|woman|female|girls?|wmns?/', $text ) ) {
			return 'female';
		}
		if ( preg_match( '/\b(mens?|gents?|male|boys?)\b/', $text ) ) {
			return 'male';
		}
		return '';
	}

	/** Map a product's top-level department to a default Google product category id. */
	private function default_google_category( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		// Walk to the top-level ancestor name.
		$term = $terms[0];
		$anc  = get_ancestors( $term->term_id, 'product_cat' );
		$top  = $anc ? get_term( end( $anc ), 'product_cat' ) : $term;
		$name = ( $top && ! is_wp_error( $top ) ) ? strtolower( $top->name ) : '';
		// Owner-configurable map: option "feed_gcat_map" as "Department=ID" lines.
		$map_raw = (string) corpmerch_option( 'feed_gcat_map', '' );
		if ( $map_raw ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $map_raw ) as $line ) {
				$parts = explode( '=', $line, 2 );
				if ( count( $parts ) === 2 && strtolower( trim( $parts[0] ) ) === $name ) {
					return trim( $parts[1] );
				}
			}
		}
		// Built-in defaults for the standard departments (numeric IDs are stable
		// across Google taxonomy updates). Owner map above overrides these.
		$defaults = array(
			'apparel'     => '1604', // Apparel & Accessories > Clothing
			'work wear'   => '1604',
			'workwear'    => '1604',
			'chef wear'   => '1604',
			'chefwear'    => '1604',
			'sublimation' => '1604',
			'sport'       => '5322', // Clothing > Activewear
			'sports'      => '5322',
			'head wear'   => '173',  // Clothing Accessories > Hats
			'headwear'    => '173',
			'bags'        => '167',  // Handbags, Wallets & Cases
			'gifting'     => '53',   // Party & Celebration > Gift Giving
			'gifts'       => '53',
			'homeware'    => '536',  // Home & Garden
			'homewear'    => '536',
			'display'     => '4217', // Retail > Retail Displays
		);
		if ( isset( $defaults[ $name ] ) ) {
			return $defaults[ $name ];
		}
		return '';
	}

	/** True when the product's top-level department is apparel/clothing. */
	private function is_apparel_dept( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return false;
		}
		$term = $terms[0];
		$anc  = get_ancestors( $term->term_id, 'product_cat' );
		$top  = $anc ? get_term( end( $anc ), 'product_cat' ) : $term;
		$name = ( $top && ! is_wp_error( $top ) ) ? strtolower( $top->name ) : '';
		return in_array( $name, array( 'apparel', 'work wear', 'workwear', 'chef wear', 'chefwear', 'sport', 'sports', 'sublimation' ), true );
	}

	private function brand( $product ) {
		foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $product->get_id(), $tax );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return $terms[0]->name;
			}
		}
		return '';
	}

	private function gtin( $p ) {
		if ( method_exists( $p, 'get_global_unique_id' ) && $p->get_global_unique_id() ) {
			return $p->get_global_unique_id();
		}
		$meta = $p->get_meta( '_global_unique_id' );
		return $meta ? $meta : '';
	}

	/** Full category path of the first product_cat term, "A > B > C". */
	private function product_type( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$term  = $terms[0];
		$chain = array( $term->name );
		foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $aid ) {
			$a = get_term( $aid, 'product_cat' );
			if ( $a && ! is_wp_error( $a ) ) {
				array_unshift( $chain, $a->name );
			}
		}
		return implode( ' > ', $chain );
	}

	/** Resolve a variation attribute slug to its readable term/option label. */
	private function attr_label( $attr, $value ) {
		if ( '' === $value ) {
			return '';
		}
		$tax = str_replace( 'attribute_', '', $attr );
		if ( taxonomy_exists( $tax ) ) {
			$term = get_term_by( 'slug', $value, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return $value;
	}

	/* ----- XML emitters ----- */

	private function x( $s ) {
		return esc_html( wp_strip_all_tags( (string) $s ) );
	}
	private function tag( $name, $value ) {
		echo '<' . $name . '>' . $this->x( $value ) . '</' . $name . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	private function tag_raw( $name, $value ) {
		echo '<' . $name . '>' . $value . '</' . $name . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	private function cdata( $name, $value ) {
		$value = str_replace( ']]>', ']]&gt;', (string) $value );
		echo '<' . $name . '><![CDATA[' . $value . ']]></' . $name . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

new Corpmerch_Feed();
