<?php
/**
 * Corpmerch — Social Posts (admin)
 *
 * Generates ready-to-paste Facebook posts from real catalogue data:
 *   • New products (newest by date published)
 *   • Best sellers (by WooCommerce total_sales)
 * Each post shows copy-paste caption text plus the product images to download
 * and attach. Also provides a short video-ad script.
 *
 * Pure admin tool — no storefront output, no posting to Facebook (paste manually).
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Social_Admin {

	const SLUG  = 'corpmerch-social';
	const COUNT = 4; // products featured per post.

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'corpmerch',
			__( 'Social Posts', 'corpmerch' ),
			__( 'Social Posts', 'corpmerch' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/* ----------------------------------------------------------- data */

	/** Newest published products. */
	private function newest( $limit ) {
		return wc_get_products( array(
			'status'     => 'publish',
			'limit'      => $limit,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'visibility' => 'catalog',
		) );
	}

	/** Best sellers by total_sales (falls back to nothing if no sales yet). */
	private function bestsellers( $limit ) {
		return wc_get_products( array(
			'status'       => 'publish',
			'limit'        => $limit,
			'orderby'      => 'meta_value_num',
			'meta_key'     => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery
			'order'        => 'DESC',
			'visibility'   => 'catalog',
			'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => 'total_sales', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
			),
		) );
	}

	/* ----------------------------------------------------------- render */

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'corpmerch' ) );
		}

		$site = get_bloginfo( 'name' );
		$url  = home_url( '/' );

		echo '<div class="wrap cm-social">';
		echo '<h1>' . esc_html__( 'Social Posts', 'corpmerch' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Ready-to-paste Facebook posts built from your live catalogue. Copy the caption, download the images shown, and attach them when you post.', 'corpmerch' ) . '</p>';

		// Post 1 — New products.
		$this->render_post(
			__( 'Post 1 — New products', 'corpmerch' ),
			$this->newest( self::COUNT ),
			$this->caption_new( $this->newest( self::COUNT ), $site, $url ),
			__( 'No published products found yet.', 'corpmerch' )
		);

		// Post 2 — Best sellers.
		$best = $this->bestsellers( self::COUNT );
		$this->render_post(
			__( 'Post 2 — Popular products', 'corpmerch' ),
			$best,
			$this->caption_best( $best, $site, $url ),
			__( 'No sales recorded yet — once orders come in, your best sellers appear here. Until then, use the New products post.', 'corpmerch' )
		);

		// Video ad script.
		$this->render_video_script( $site );

		echo '</div>';
		$this->inline_css();
	}

	/** Render one post block: caption textarea + product image grid. */
	private function render_post( $heading, $products, $caption, $empty_msg ) {
		echo '<div class="cm-social__card">';
		echo '<h2>' . esc_html( $heading ) . '</h2>';

		if ( empty( $products ) ) {
			echo '<p class="cm-social__empty">' . esc_html( $empty_msg ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<label class="cm-social__label">' . esc_html__( 'Caption (copy & paste)', 'corpmerch' ) . '</label>';
		echo '<textarea class="cm-social__text" rows="12" readonly onclick="this.select()">' . esc_textarea( $caption ) . '</textarea>';

		echo '<label class="cm-social__label">' . esc_html__( 'Images — right-click each to save, then attach to your post', 'corpmerch' ) . '</label>';
		echo '<div class="cm-social__imgs">';
		foreach ( $products as $product ) {
			$img_id = $product->get_image_id();
			$thumb  = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';
			echo '<figure class="cm-social__img">';
			if ( $thumb ) {
				echo '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $product->get_name() ) . '" loading="lazy">';
				echo '<a class="button button-small" href="' . esc_url( $thumb ) . '" download target="_blank" rel="noopener">' . esc_html__( 'Open image', 'corpmerch' ) . '</a>';
			} else {
				echo '<span class="cm-social__noimg">' . esc_html__( 'No image', 'corpmerch' ) . '</span>';
			}
			echo '<figcaption>' . esc_html( $product->get_name() ) . '</figcaption>';
			echo '</figure>';
		}
		echo '</div>';
		echo '</div>';
	}

	/* ----------------------------------------------------------- captions */

	/** Format a product list as "• Name — Rxx.xx" lines. */
	private function product_lines( $products ) {
		$lines = array();
		foreach ( $products as $p ) {
			$price = wc_price( $p->get_price() );
			$lines[] = '• ' . $p->get_name() . ' — ' . html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' );
		}
		return implode( "\n", $lines );
	}

	private function caption_new( $products, $site, $url ) {
		return sprintf(
			/* translators: 1: site, 2: product lines, 3: url */
			__( "New arrivals at %1\$s\n\nWe've just added new corporate apparel and gear to our range — all fully brandable with your company logo:\n\n%2\$s\n\nChoose your branding method, position and colours online, and see your pricing instantly. Ideal for staff uniforms, events and corporate gifting.\n\nBrowse the range: %3\$s\nFor bulk or custom orders, send us a message and we'll prepare a quote.\n\n#CorporateApparel #BrandedMerchandise #CompanyClothing #Workwear #SouthAfrica", 'corpmerch' ),
			$site,
			$this->product_lines( $products ),
			$url
		);
	}

	private function caption_best( $products, $site, $url ) {
		return sprintf(
			/* translators: 1: site, 2: product lines, 3: url */
			__( "Our most popular corporate gear\n\nThese are the items our clients order again and again — dependable quality, ready to carry your brand:\n\n%2\$s\n\nEach can be branded with your logo through embroidery, print and more. A proof is sent for your approval before production, so you know exactly what you're getting.\n\nView the range: %3\$s\nNeed pricing on a bulk branded order? Get in touch — %1\$s is here to help.\n\n#BestSellers #BrandedClothing #CorporateGifts #TeamUniforms #SouthAfrica", 'corpmerch' ),
			$site,
			$this->product_lines( $products ),
			$url
		);
	}

	/* ----------------------------------------------------------- video script */

	private function render_video_script( $site ) {
		$script = sprintf(
			/* translators: site name */
			__( "SHORT VIDEO AD SCRIPT (±30 seconds)\n\n— Suggested length: 25–35s, vertical 9:16 for Facebook/Instagram Reels —\n\n[0–3s] HOOK (text on screen + upbeat music)\n\"Need branded gear for your team?\"\n\n[3–8s] PROBLEM\nVoiceover: \"Ordering corporate clothing shouldn't be a headache.\"\n(Show: someone scrolling endlessly / frustrated)\n\n[8–18s] SOLUTION — what you do\nVoiceover: \"At %1\$s, we make it simple. Browse hundreds of quality corporate items — shirts, jackets, bags, headwear and more — and add YOUR logo right online. Pick your branding position, size and colours, and see the price instantly.\"\n(Show: product shots, logo being added, cart with branding options)\n\n[18–25s] TRUST\nVoiceover: \"We send you a proof to approve before we produce anything — so what you order is exactly what you get.\"\n(Show: a branded shirt, a happy team wearing matching gear)\n\n[25–30s] CALL TO ACTION (text on screen)\n\"Kit out your team today.\"\nVoiceover: \"Visit %2\$s — corporate branding made easy.\"\n(Show: logo + website URL + 'Free delivery over R1000')\n\n— TIPS —\n• Keep text on screen large; most people watch with sound off.\n• Use your real product photos for the middle section.\n• End on your logo for 2–3 seconds.\n• Caption to pair with the video: \"Branded corporate gear, made easy. 👔 Shop online, add your logo, approve your proof. Free delivery over R1000. 🚚\"", 'corpmerch' ),
			$site,
			home_url( '/' )
		);

		echo '<div class="cm-social__card">';
		echo '<h2>' . esc_html__( 'Video ad script', 'corpmerch' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A short script telling people what you do. Hand this to a video editor, or record it yourself on a phone.', 'corpmerch' ) . '</p>';
		echo '<textarea class="cm-social__text" rows="26" readonly onclick="this.select()">' . esc_textarea( $script ) . '</textarea>';
		echo '</div>';
	}

	/* ----------------------------------------------------------- css */

	private function inline_css() {
		echo '<style>
.cm-social__card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:900px;}
.cm-social__card h2{margin-top:0;}
.cm-social__label{display:block;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#646970;margin:14px 0 6px;}
.cm-social__text{width:100%;font-family:inherit;font-size:13px;line-height:1.5;padding:12px;border:1px solid #c3c4c7;border-radius:6px;background:#f6f7f7;resize:vertical;}
.cm-social__imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-top:6px;}
.cm-social__img{margin:0;text-align:center;border:1px solid #e0e0e0;border-radius:8px;padding:8px;background:#fff;}
.cm-social__img img{width:100%;height:160px;object-fit:cover;border-radius:4px;display:block;margin-bottom:8px;}
.cm-social__img figcaption{font-size:12px;color:#3c434a;margin-top:6px;line-height:1.3;}
.cm-social__noimg{display:flex;align-items:center;justify-content:center;height:160px;background:#fbeaea;color:#b32d2e;border-radius:4px;font-size:13px;margin-bottom:8px;}
.cm-social__empty{color:#646970;font-style:italic;}
</style>';
	}
}

new Corpmerch_Social_Admin();
