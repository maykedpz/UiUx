<?php
/**
 * Corpmerch — Kevro branding & product features (display layer, phase 2).
 *
 * Lazily fetches Product Features and Branding Positions from the Kevro API
 * for the current product (cached to meta + a 24h transient so we never call
 * the API on every page view), then renders:
 *   - Product Features list
 *   - Branding matrix (Position × Branding Type) with select inputs
 *   - "indicative only" disclaimer
 *
 * Pricing / cart wiring (flat R199 once per order) lands in phase 3.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Branding {

	const REFRESH_TTL = DAY_IN_SECONDS;
	const FEE_AMOUNT  = 199.0;
	// Per-position, per-item branding cost applied when a product has no Kevro
	// pricing map. Flat, no markup (markup only applies to real Kevro prices).
	const FALLBACK_BRAND_COST = 199.0;

	public function __construct() {
		// Sticky-gallery containment: wrap the gallery + summary in a single
		// element so it — not div.product — is the gallery's sticky containing
		// block. Without this the sticky gallery travels the full height of
		// div.product (through tabs + related) and overlaps them on scroll.
		// Open before the gallery (which shows at prio 20); close after the
		// summary, before the tabs (prio 10 on after_single_product_summary).
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_pdp_top_open' ), 5 );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_pdp_top_close' ), 5 );

		// Desktop layout: features (left) + trust (right) sit side-by-side in a
		// 2-col row. They are separate summary hooks, so we wrap them in a shared
		// container — open before features, close after trust. The Size guide
		// (priority 27) falls inside and stacks in the left column under features.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_fx_row_open' ), 25 );
		// Features under the description tab content area.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_features' ), 26 );
		// Trust badges + payment options — fills the gap above the cart form
		// (the cart form, and the Add-branding block inside it, render at 30).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_trust' ), 29 );
		// Close the features/trust row after trust, before the cart form (30).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_fx_row_close' ), 29.5 );
		// Branding matrix INSIDE the add-to-cart form so selections POST on submit.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_branding' ), 20 );

		// Pre-fill the quantity input with the product's MOQ (and enforce it as min).
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'apply_moq_qty' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_min', array( $this, 'apply_moq_min' ), 10, 2 );

		// Phase 3 — cart/order wiring.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'capture_cart_item' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'render_artwork_meta_link' ), 10, 3 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_proof_notice' ), 15, 4 );

		// Admin order screen: print-friendly branding worksheet meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_worksheet_box' ), 40, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_branding_fee' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_branding_cost' ), 20 );

		// Phase 3b — artwork upload.
		add_action( 'wp_ajax_cm_branding_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_nopriv_cm_branding_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_upload' ), 20 );
		add_filter( 'upload_dir', array( $this, 'maybe_protect_dir' ) );
	}

	/** Is branding globally enabled (admin kill-switch)? Default on. */
	private function enabled() {
		return function_exists( 'corpmerch_bool' ) ? corpmerch_bool( 'branding_enable', true ) : true;
	}

	/** Current product's Kevro StockHeaderID, or 0. */
	private function header_id( $product_id ) {
		return (int) get_post_meta( $product_id, '_kevro_header_id', true );
	}

	/**
	 * Ensure features + positions meta is present and reasonably fresh.
	 * Uses a per-product transient to avoid re-calling the API within TTL.
	 */
	private function ensure_data( $product_id, $header_id ) {
		if ( $header_id <= 0 || ! class_exists( 'Corpmerch_Kevro' ) ) {
			return;
		}
		$stamp = (int) get_post_meta( $product_id, '_kevro_branding_checked', true );
		if ( $stamp && ( time() - $stamp ) < self::REFRESH_TTL ) {
			return; // Fresh enough.
		}
		// Guard against thundering herd: short lock transient.
		$lock = 'cm_brand_lock_' . $product_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$kevro = new Corpmerch_Kevro();
		if ( method_exists( $kevro, 'fetch_and_store_branding' ) ) {
			$kevro->fetch_and_store_branding( $product_id, $header_id );
			update_post_meta( $product_id, '_kevro_branding_checked', time() );
		}
		delete_transient( $lock );
	}

	/** Product Features list (rendered as its own card on the PDP). */
	public function render_features() {
		if ( ! $this->enabled() ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$pid = $product->get_id();
		$this->ensure_data( $pid, $this->header_id( $pid ) );

		$features = get_post_meta( $pid, '_kevro_features', true );
		if ( empty( $features ) || ! is_array( $features ) ) {
			return;
		}
		echo '<div class="sia-pf">';
		echo '<h3 class="sia-pf-title">' . esc_html__( 'Product Features', 'corpmerch' ) . '</h3>';
		echo '<ul class="sia-pf-list">';
		foreach ( $features as $f ) {
			echo '<li>' . esc_html( $f ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/** Read a product's MOQ (minimum order quantity), 0 if none. */
	private function moq_for( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}
		return max( 0, (int) get_post_meta( $product->get_id(), '_kevro_moq', true ) );
	}

	/**
	 * Set the quantity input's default value AND minimum to the MOQ, on the
	 * single product page only (don't force MOQ in cart/mini-cart where the
	 * shopper may legitimately adjust). Only applies when no manual qty is set.
	 */
	public function apply_moq_qty( $args, $product ) {
		$moq = $this->moq_for( $product );
		if ( $moq > 1 && is_product() ) {
			$args['min_value'] = $moq;
			// Only pre-fill if the current input value is below the MOQ.
			if ( empty( $args['input_value'] ) || (int) $args['input_value'] < $moq ) {
				$args['input_value'] = $moq;
			}
		}
		return $args;
	}

	/** Enforce MOQ as the minimum orderable quantity. */
	public function apply_moq_min( $min, $product ) {
		$moq = $this->moq_for( $product );
		return ( $moq > 1 ) ? $moq : $min;
	}

	/**
	 * Trust badges + accepted-payment row. Rendered in the summary gap above
	 * the add-to-cart form. Payment marks reference SVGs in assets/img/pay/;
	 * if a file is absent the styled text label shows instead, so this works
	 * before official brand assets are supplied.
	 */
	/** Open the gallery+summary wrapper (sticky-gallery containing block). */
	public function render_pdp_top_open() {
		echo '<div class="sia-pdp-top">';
	}

	/** Close the gallery+summary wrapper, before the product tabs. */
	public function render_pdp_top_close() {
		echo '</div>';
	}

	/** Open the features/trust 2-col row wrapper (desktop layout). */
	public function render_fx_row_open() {
		echo '<div class="sia-fx-row">';
	}

	/** Close the features/trust 2-col row wrapper. */
	public function render_fx_row_close() {
		echo '</div>';
	}

	public function render_trust() {
		if ( ! $this->enabled() ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$badges = array(
			array( 'lock', __( 'Secure checkout', 'corpmerch' ), __( 'Encrypted payment & data', 'corpmerch' ) ),
			array( 'proof', __( 'Proof before production', 'corpmerch' ), __( 'Approve placement first', 'corpmerch' ) ),
			array( 'za', __( 'Proudly SA-based', 'corpmerch' ), __( 'Local stock & support', 'corpmerch' ) ),
		);

		// slug => visible label. Drop a matching {slug}.svg into assets/img/pay/.
		$icons = array(
			'lock'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
			'proof' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
			'za'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/></svg>',
		);

		echo '<section class="sia-trust" aria-label="' . esc_attr__( 'Trust & payment', 'corpmerch' ) . '">';

		echo '<ul class="sia-trust-badges">';
		foreach ( $badges as $b ) {
			list( $key, $title, $sub ) = $b;
			echo '<li class="sia-trust-badge">';
			echo '<span class="sia-trust-ico" aria-hidden="true">' . $icons[ $key ] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			echo '<span class="sia-trust-text"><span class="sia-trust-title">' . esc_html( $title ) . '</span><span class="sia-trust-sub">' . esc_html( $sub ) . '</span></span>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="sia-pay">';
		echo '<span class="sia-pay-label">' . esc_html__( 'Accepted payments', 'corpmerch' ) . '</span>';
		echo self::payment_marks_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
		echo '</div>';

		echo '</section>';
	}

	/**
	 * Reusable accepted-payment marks (<ul>). Shared by the PDP trust section
	 * and the cart. SVGs live in assets/img/pay/{slug}.svg; falls back to text.
	 */
	public static function payment_marks_html() {
		$pay = array(
			'visa'       => 'Visa',
			'mastercard' => 'Mastercard',
			'payfast'    => 'PayFast',
			'snapscan'   => 'SnapScan',
			'mobicred'   => 'Mobicred',
			'eft'        => 'EFT',
		);
		$pay_dir = CORPMERCH_DIR . '/assets/img/pay/';
		$pay_uri = CORPMERCH_URI . '/assets/img/pay/';

		$out  = '<ul class="sia-pay-marks">';
		foreach ( $pay as $slug => $label ) {
			$file = $pay_dir . $slug . '.svg';
			$out .= '<li class="sia-pay-mark" data-pay="' . esc_attr( $slug ) . '">';
			if ( file_exists( $file ) ) {
				$out .= '<img src="' . esc_url( $pay_uri . $slug . '.svg' ) . '" alt="' . esc_attr( $label ) . '" width="48" height="30" loading="lazy">';
			} else {
				$out .= '<span class="sia-pay-text">' . esc_html( $label ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/** Branding matrix: Position × Branding Type selectors + disclaimer. */
	public function render_branding() {
		if ( ! $this->enabled() ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$pid       = $product->get_id();
		$positions = get_post_meta( $pid, '_kevro_branding_positions', true );
		if ( empty( $positions ) || ! is_array( $positions ) ) {
			return; // Auto-disabled when the product has no branding positions.
		}

		echo '<section class="sia-brand" data-sia-branding>';
		echo '<h3 class="sia-brand-title">' . esc_html__( 'Add branding', 'corpmerch' ) . '</h3>';
		echo '<p class="sia-brand-note">' . esc_html__( 'This is a visual guide only and not an exact representation of the final branding size or position. After you order, we email you a proof/mockup to approve before we produce anything.', 'corpmerch' ) . '</p>';

		$guide = trim( (string) get_post_meta( $pid, '_kevro_branding_guide', true ) );
		if ( '' !== $guide ) {
			echo '<p class="sia-brand-guide"><a href="' . esc_url( $guide ) . '" target="_blank" rel="noopener noreferrer">';
			echo '<span aria-hidden="true" class="sia-brand-guide-ico">&#128196;</span> ';
			echo esc_html__( 'View branding guide (positions &amp; sizes)', 'corpmerch' );
			echo '</a></p>';
		}

		$pricing = get_post_meta( $pid, '_kevro_branding_pricing', true );
		$pricing = is_array( $pricing ) ? $pricing : array();
		$setup   = (float) get_post_meta( $pid, '_kevro_branding_setup', true );

		// Each position is a self-contained card: heading + stacked selectors +
		// per-placement upload. Cleaner on mobile than a cramped horizontal grid.
		echo '<div class="sia-brand-cards" role="group" aria-label="' . esc_attr__( 'Branding positions', 'corpmerch' ) . '">';
		foreach ( $positions as $pos => $types ) {
			$slug = sanitize_title( $pos );
			echo '<div class="sia-brand-card" data-sia-brand-row>';
			echo '<h4 class="sia-brand-card-pos">' . esc_html( $pos ) . '</h4>';

			echo '<select class="sia-brand-sel" id="sia-brand-' . esc_attr( $slug ) . '" name="cm_branding[' . esc_attr( $pos ) . '][type]" data-sia-brand-sel aria-label="' . esc_attr( sprintf( /* translators: position */ __( '%s branding type', 'corpmerch' ), $pos ) ) . '">';
			echo '<option value="">' . esc_html__( 'No branding', 'corpmerch' ) . '</option>';
			foreach ( (array) $types as $type ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>';
			}
			echo '</select>';

			// Size + colour (populated by JS from the pricing map; hidden until a type is chosen).
			echo '<select class="sia-brand-size" name="cm_branding[' . esc_attr( $pos ) . '][size]" data-sia-brand-size hidden disabled aria-label="' . esc_attr__( 'Branding size', 'corpmerch' ) . '"></select>';
			echo '<select class="sia-brand-colours" name="cm_branding[' . esc_attr( $pos ) . '][colours]" data-sia-brand-colours hidden disabled aria-label="' . esc_attr__( 'Number of colours', 'corpmerch' ) . '"></select>';

			// Per-placement artwork upload (revealed once a type is chosen).
			echo '<div class="sia-brand-place-upload" data-sia-place-upload hidden>';
			echo '<input type="file" class="sia-brand-file" id="sia-brand-art-' . esc_attr( $slug ) . '" accept=".png,.jpg,.jpeg,.pdf,.svg,.ai,.eps,image/png,image/jpeg,application/pdf,image/svg+xml" data-sia-brand-file>';
			echo '<input type="hidden" name="cm_branding[' . esc_attr( $pos ) . '][art]" value="" data-sia-brand-art-token>';
			echo '<label class="sia-brand-place-upload-label" for="sia-brand-art-' . esc_attr( $slug ) . '">' . esc_html__( 'Upload artwork for this placement', 'corpmerch' ) . '</label>';
			echo '<span class="sia-brand-upload-status" data-sia-brand-upload-status></span>';
			echo '</div>';

			echo '<span class="sia-brand-line-price" data-sia-brand-line-price></span>';
			echo '</div>';
		}
		echo '</div>';

		// Running total. Map is markup-adjusted server-side. Setup shown once.
		echo '<div class="sia-brand-summary" data-sia-brand-summary hidden>';
		echo '<div class="sia-brand-summary-row"><span>' . esc_html__( 'Branding (per item)', 'corpmerch' ) . '</span><span data-sia-brand-peritem>' . wp_kses_post( wc_price( 0 ) ) . '</span></div>';
		if ( $setup > 0 ) {
			echo '<div class="sia-brand-summary-row sia-brand-summary-setup"><span>' . esc_html__( 'One-off setup', 'corpmerch' ) . '</span><span>' . wp_kses_post( wc_price( $setup ) ) . '</span></div>';
		}
		echo '<p class="sia-brand-summary-note">' . esc_html__( 'Branding is charged per item; setup is once per product.', 'corpmerch' ) . '</p>';
		echo '</div>';

		// Explicit "add to cart without branding" — clearer than leaving selects empty.
		echo '<button type="submit" class="button alt sia-brand-skip" name="cm_branding_skip" value="1" data-sia-brand-skip>' . esc_html__( 'Add to cart without branding', 'corpmerch' ) . '</button>';

		$json = wp_json_encode(
			array(
				'pricing'  => $pricing,
				'setup'    => (float) $setup,
				'fallback' => (float) self::FALLBACK_BRAND_COST,
				// Decode the currency entity (e.g. &#82; → R) so JS textContent shows it correctly.
				'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			)
		);
		echo '<script type="application/json" data-sia-brand-data>' . $json . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$this->branding_calc_js();

		echo '<p class="sia-brand-help">' . esc_html__( 'Need help? ', 'corpmerch' ) . '<a href="' . esc_url( home_url( '/contact/' ) ) . '">' . esc_html__( 'Contact us', 'corpmerch' ) . '</a></p>';
		echo '</section>';
	}

	/* ------------------------------------------------ phase 3: cart / order */

	/**
	 * Inline JS for the branding price calculator. Reads the embedded pricing
	 * map, cascades type → size → colours, prices each populated position, and
	 * shows a per-item branding subtotal. Values POST as cm_branding[pos][type|size|colours].
	 */
	private function branding_calc_js() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
<script>
(function(){
	var root = document.querySelector('[data-sia-branding]');
	if (!root) return;
	var dataEl = root.querySelector('[data-sia-brand-data]');
	if (!dataEl) return;
	var DATA; try { DATA = JSON.parse(dataEl.textContent); } catch(e){ return; }
	var P = DATA.pricing || {}, CUR = DATA.currency || '', FB = Number(DATA.fallback || 0);
	function money(n){ return CUR + Number(n||0).toFixed(2); }
	function fill(sel, opts, keep){
		var cur = keep ? sel.value : '';
		sel.innerHTML = '';
		opts.forEach(function(o){
			var op = document.createElement('option');
			op.value = o.value; op.textContent = o.label;
			sel.appendChild(op);
		});
		if (cur) sel.value = cur;
	}
	function rowPrice(row){
		var t = row.querySelector('[data-sia-brand-sel]');
		var s = row.querySelector('[data-sia-brand-size]');
		var c = row.querySelector('[data-sia-brand-colours]');
		var out = row.querySelector('[data-sia-brand-line-price]');
		var up = row.querySelector('[data-sia-place-upload]');
		if (!t) return 0;
		var type = t.value;
		// Reveal the per-placement uploader only when a branding type is chosen.
		if (up) up.hidden = !type;
		if (!type){
			if (s){ s.hidden = true; s.disabled = true; }
			if (c){ c.hidden = true; c.disabled = true; }
			if (out) out.textContent = '';
			return 0;
		}
		// No pricing map for this type → flat fallback fee, no size/colour.
		if (!P[type]){
			if (s){ s.hidden = true; s.disabled = true; }
			if (c){ c.hidden = true; c.disabled = true; }
			if (out) out.textContent = FB > 0 ? '+ ' + money(FB) : '';
			return FB;
		}
		// Populate sizes for this type.
		var sizes = Object.keys(P[type]).map(function(k){ return {value:k,label:k}; });
		fill(s, sizes, true); s.hidden = false; s.disabled = false;
		if (!s.value) s.value = sizes[0].value;
		var size = s.value;
		// Populate colour counts for this type+size.
		var cols = Object.keys(P[type][size].colours)
			.map(Number).sort(function(a,b){return a-b;})
			.map(function(n){ return {value:String(n), label: n===1 ? '1 colour' : n+' colours'}; });
		fill(c, cols, true); c.hidden = (cols.length<=1); c.disabled = false;
		if (!c.value) c.value = cols[0].value;
		var price = Number(P[type][size].colours[c.value] || 0);
		if (out) out.textContent = '+ ' + money(price);
		return price;
	}
	function recalc(){
		var total = 0;
		root.querySelectorAll('[data-sia-brand-row]').forEach(function(r){ total += rowPrice(r); });
		var sum = root.querySelector('[data-sia-brand-summary]');
		var per = root.querySelector('[data-sia-brand-peritem]');
		if (per) per.textContent = money(total);
		if (sum) sum.hidden = (total<=0);
	}
	root.addEventListener('change', function(e){
		if (e.target.matches('[data-sia-brand-sel],[data-sia-brand-size],[data-sia-brand-colours]')) recalc();
	});

	// Per-placement artwork upload. Each file input posts to the same AJAX
	// endpoint and writes the returned token into its sibling hidden field.
	var CFG = window.siaBranding || {};
	root.addEventListener('change', function(e){
		var input = e.target;
		if (!input.matches('[data-sia-brand-file]')) return;
		var wrap = input.closest('[data-sia-place-upload]');
		if (!wrap) return;
		var token = wrap.querySelector('[data-sia-brand-art-token]');
		var status = wrap.querySelector('[data-sia-brand-upload-status]');
		var file = input.files && input.files[0];
		if (!file){ if (token) token.value=''; if (status) status.textContent=''; return; }
		if (file.size > 10*1024*1024){
			if (status){ status.style.color='#b32d2e'; status.textContent='File too large (max 10MB).'; }
			input.value=''; return;
		}
		if (status){ status.style.color=''; status.textContent='Uploading…'; }
		var fd = new FormData();
		fd.append('action','cm_branding_upload');
		fd.append('nonce', CFG.nonce || '');
		fd.append('file', file);
		fetch(CFG.ajax, { method:'POST', body:fd, credentials:'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(res){
				if (res && res.success && res.data && res.data.token){
					if (token) token.value = res.data.token;
					if (status){ status.style.color='#1a7f37'; status.textContent = res.data.msg || 'Uploaded.'; }
				} else {
					if (token) token.value='';
					if (status){ status.style.color='#b32d2e'; status.textContent = (res && res.data && res.data.msg) || 'Upload failed.'; }
				}
			})
			.catch(function(){
				if (token) token.value='';
				if (status){ status.style.color='#b32d2e'; status.textContent='Upload error.'; }
			});
	});

	recalc();
})();
</script>
		<?php
	}

	/**
	 * Capture branding selections from the add-to-cart POST into cart item data.
	 * Only non-empty selections are stored. Each branded product line is flagged
	 * so the per-product R199 setup fee can be counted.
	 */
	public function capture_cart_item( $cart_item_data, $product_id, $variation_id ) {
		// Explicit "add to cart without branding" — skip all branding capture.
		if ( ! empty( $_POST['cm_branding_skip'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $cart_item_data;
		}
		if ( empty( $_POST['cm_branding'] ) || ! is_array( $_POST['cm_branding'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $cart_item_data;
		}
		$pricing = get_post_meta( $product_id, '_kevro_branding_pricing', true );
		$pricing = is_array( $pricing ) ? $pricing : array();

		$selected = array();
		$brand_cost = 0.0;
		foreach ( wp_unslash( $_POST['cm_branding'] ) as $position => $row ) { // phpcs:ignore WordPress.Security.NonceVerification
			$position = sanitize_text_field( $position );
			// Backward-compat: a bare string is just a type with no pricing.
			if ( ! is_array( $row ) ) {
				$type = sanitize_text_field( $row );
				if ( '' !== $type ) {
					$selected[ $position ] = array( 'type' => $type, 'price' => self::FALLBACK_BRAND_COST );
					$brand_cost           += self::FALLBACK_BRAND_COST;
				}
				continue;
			}
			$type = sanitize_text_field( isset( $row['type'] ) ? $row['type'] : '' );
			$size = sanitize_text_field( isset( $row['size'] ) ? $row['size'] : '' );
			$cols = (int) ( isset( $row['colours'] ) ? $row['colours'] : 0 );
			if ( '' === $type ) {
				continue;
			}
			$entry = array( 'type' => $type );
			// Validate against the server-side pricing map (never trust posted price).
			if ( $size && isset( $pricing[ $type ][ $size ]['colours'][ $cols ] ) ) {
				$price = (float) $pricing[ $type ][ $size ]['colours'][ $cols ];
				$entry['size']    = $size;
				$entry['colours'] = $cols;
				$entry['price']   = $price;
				$brand_cost      += $price;
			} else {
				// No pricing data for this product/selection — flat R199 per position.
				$entry['price'] = self::FALLBACK_BRAND_COST;
				$brand_cost    += self::FALLBACK_BRAND_COST;
			}
			// Per-placement artwork token (optional) — only kept if it resolves.
			if ( ! empty( $row['art'] ) ) {
				$token = sanitize_file_name( $row['art'] );
				if ( $this->token_to_file( $token ) ) {
					$entry['art'] = $token;
				}
			}
			$selected[ $position ] = $entry;
		}
		if ( $selected ) {
			$cart_item_data['cm_branding']      = $selected;
			$cart_item_data['cm_branding_cost'] = round( $brand_cost, 2 ); // per-unit add-on.
			$cart_item_data['cm_branding_key']  = md5( wp_json_encode( $selected ) . '|' . $product_id . '|' . $variation_id );
		}
		return $cart_item_data;
	}

	/** Format a single position's branding selection for display. */
	private function format_branding_line( $position, $entry ) {
		if ( ! is_array( $entry ) ) {
			return $position . ' — ' . $entry; // legacy string.
		}
		$txt = $position . ' — ' . ( isset( $entry['type'] ) ? $entry['type'] : '' );
		if ( ! empty( $entry['size'] ) ) {
			$txt .= ', ' . $entry['size'];
		}
		if ( ! empty( $entry['colours'] ) ) {
			$n    = (int) $entry['colours'];
			$txt .= ', ' . sprintf( _n( '%d colour', '%d colours', $n, 'corpmerch' ), $n );
		}
		if ( ! empty( $entry['art'] ) ) {
			$txt .= ' (' . __( 'artwork ✓', 'corpmerch' ) . ')';
		}
		return $txt;
	}

	/** Show the branding selections under the item in cart & checkout. */
	public function display_cart_item( $item_data, $cart_item ) {
		if ( empty( $cart_item['cm_branding'] ) || ! is_array( $cart_item['cm_branding'] ) ) {
			return $item_data;
		}
		$parts = array();
		foreach ( $cart_item['cm_branding'] as $position => $entry ) {
			$parts[] = $this->format_branding_line( $position, $entry );
		}
		$item_data[] = array(
			'key'   => __( 'Branding', 'corpmerch' ),
			'value' => implode( '; ', $parts ),
		);
		if ( ! empty( $cart_item['cm_branding_cost'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Branding (per item)', 'corpmerch' ),
				'value' => wp_strip_all_tags( wc_price( (float) $cart_item['cm_branding_cost'] ) ),
			);
		}
		return $item_data;
	}

	/** Persist branding selections onto the order line item. */
	public function save_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['cm_branding'] ) || ! is_array( $values['cm_branding'] ) ) {
			return;
		}
		$parts = array();
		foreach ( $values['cm_branding'] as $position => $entry ) {
			$parts[] = $this->format_branding_line( $position, $entry );
		}
		// Visible on the order (admin + emails + customer account).
		$item->add_meta_data( __( 'Branding', 'corpmerch' ), implode( '; ', $parts ), true );
		if ( ! empty( $values['cm_branding_cost'] ) ) {
			$item->add_meta_data( __( 'Branding cost (per item)', 'corpmerch' ), wc_price( (float) $values['cm_branding_cost'] ), true );
		}

		// Supplier-portal lookup keys: the Kevro StockHeaderID and SKU let you
		// find the exact product on the Kevro/supplier portal when placing the
		// branding order manually.
		$pid = ! empty( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		if ( $pid ) {
			$header = (int) get_post_meta( $pid, '_kevro_header_id', true );
			if ( $header > 0 ) {
				$item->add_meta_data( __( 'Kevro StockHeaderID', 'corpmerch' ), $header, true );
			}
			$product = wc_get_product( ! empty( $values['variation_id'] ) ? (int) $values['variation_id'] : $pid );
			$sku     = $product ? $product->get_sku() : '';
			if ( $sku ) {
				$item->add_meta_data( __( 'Supplier SKU', 'corpmerch' ), $sku, true );
			}
		}

		// Attach each placement's artwork file URL (if uploaded).
		foreach ( $values['cm_branding'] as $position => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['art'] ) ) {
				$file = $this->token_to_file( $entry['art'] );
				if ( $file ) {
					$item->add_meta_data(
						sprintf( /* translators: position */ __( 'Artwork — %s', 'corpmerch' ), $position ),
						$file['url'],
						true
					);
				}
			}
		}
	}

	/* ------------------------------------------------ admin: branding worksheet */

	/**
	 * Register the branding worksheet meta box on the order edit screen, for
	 * both HPOS (custom order tables) and the legacy shop_order post type.
	 */
	public function add_worksheet_box( $post_type, $post_or_order = null ) {
		$hpos_screen = function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'woocommerce_page_wc-orders';
		$screens = array( 'shop_order', $hpos_screen );

		add_meta_box(
			'cm-branding-worksheet',
			__( 'Branding worksheet (supplier order)', 'corpmerch' ),
			array( $this, 'render_worksheet_box' ),
			$screens,
			'normal',
			'high'
		);
	}

	/**
	 * Print-friendly worksheet: every branded line with position, type, size,
	 * colours, per-placement artwork download links, and supplier lookup keys.
	 *
	 * @param mixed $post_or_order WP_Post (legacy) or WC_Order (HPOS).
	 */
	public function render_worksheet_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'corpmerch' ) . '</p>';
			return;
		}

		$rows = array();
		foreach ( $order->get_items() as $item ) {
			$branding = $item->get_meta( __( 'Branding', 'corpmerch' ) );
			if ( ! $branding ) {
				continue;
			}
			$rows[] = array(
				'name'     => $item->get_name(),
				'qty'      => $item->get_quantity(),
				'sku'      => $item->get_meta( __( 'Supplier SKU', 'corpmerch' ) ),
				'header'   => $item->get_meta( __( 'Kevro StockHeaderID', 'corpmerch' ) ),
				'branding' => $branding,
				'cost'     => $item->get_meta( __( 'Branding cost (per item)', 'corpmerch' ) ),
				'art'      => $this->collect_artwork_meta( $item ),
			);
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No branding on this order.', 'corpmerch' ) . '</p>';
			return;
		}

		echo '<div class="cm-worksheet">';
		echo '<p class="cm-worksheet__hint">' . esc_html__( 'Use this to place the branding order on the supplier portal. Each placement lists its method, size and colours; download the artwork per placement.', 'corpmerch' ) . '</p>';

		foreach ( $rows as $r ) {
			echo '<div class="cm-worksheet__item">';
			echo '<h4 class="cm-worksheet__name">' . esc_html( $r['name'] );
			echo ' <span class="cm-worksheet__qty">&times;' . esc_html( $r['qty'] ) . '</span></h4>';

			echo '<ul class="cm-worksheet__keys">';
			if ( $r['sku'] ) {
				echo '<li><strong>' . esc_html__( 'SKU:', 'corpmerch' ) . '</strong> ' . esc_html( $r['sku'] ) . '</li>';
			}
			if ( $r['header'] ) {
				echo '<li><strong>' . esc_html__( 'StockHeaderID:', 'corpmerch' ) . '</strong> ' . esc_html( $r['header'] ) . '</li>';
			}
			if ( $r['cost'] ) {
				echo '<li><strong>' . esc_html__( 'Branding/item:', 'corpmerch' ) . '</strong> ' . wp_kses_post( $r['cost'] ) . '</li>';
			}
			echo '</ul>';

			echo '<ol class="cm-worksheet__placements">';
			foreach ( array_map( 'trim', explode( ';', (string) $r['branding'] ) ) as $line ) {
				if ( '' !== $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
			}
			echo '</ol>';

			if ( ! empty( $r['art'] ) ) {
				echo '<div class="cm-worksheet__art"><strong>' . esc_html__( 'Artwork:', 'corpmerch' ) . '</strong> ';
				$links = array();
				foreach ( $r['art'] as $label => $url ) {
					$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" download>' . esc_html( $label ) . '</a>';
				}
				echo wp_kses_post( implode( ' &middot; ', $links ) );
				echo '</div>';
			} else {
				echo '<p class="cm-worksheet__noart">' . esc_html__( 'No artwork uploaded — request from customer.', 'corpmerch' ) . '</p>';
			}

			echo '</div>';
		}

		echo '<p class="cm-worksheet__print"><button type="button" class="button" onclick="window.print()">' . esc_html__( 'Print worksheet', 'corpmerch' ) . '</button></p>';
		echo '</div>';

		echo '<style>
.cm-worksheet__item{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin:0 0 12px;background:#fff;}
.cm-worksheet__name{margin:0 0 8px;font-size:14px;}
.cm-worksheet__qty{color:#646970;font-weight:400;}
.cm-worksheet__keys{list-style:none;margin:0 0 8px;padding:0;display:flex;flex-wrap:wrap;gap:6px 18px;font-size:12px;color:#3c434a;}
.cm-worksheet__placements{margin:0 0 8px;padding-left:20px;font-size:13px;}
.cm-worksheet__placements li{margin:2px 0;}
.cm-worksheet__art{font-size:13px;}
.cm-worksheet__noart{font-size:12px;color:#b32d2e;margin:4px 0 0;}
.cm-worksheet__hint{font-size:12px;color:#646970;}
@media print{#adminmenumain,#wpadminbar,#wpfooter,.postbox:not(#cm-branding-worksheet),.cm-worksheet__print{display:none!important;}}
</style>';
	}

	/** Collect "Artwork — {position}" meta as label => url for an order item. */
	private function collect_artwork_meta( $item ) {
		$prefix = __( 'Artwork — ', 'corpmerch' );
		$out    = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$data = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : array();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			$val  = isset( $data['value'] ) ? (string) $data['value'] : '';
			if ( 0 === strpos( $key, $prefix ) && filter_var( $val, FILTER_VALIDATE_URL ) ) {
				$label         = trim( substr( $key, strlen( $prefix ) ) );
				$out[ $label ] = $val;
			}
		}
		return $out;
	}

	/**
	 * Show a proof-process notice in order emails when the order contains any
	 * branded line item. Customer-facing emails only; keeps expectations clear
	 * that production waits on proof approval.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param WC_Email $email
	 */
	public function email_proof_notice( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		// Only show when at least one line item carries branding.
		$has_branding = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( __( 'Branding', 'corpmerch' ) ) ) {
				$has_branding = true;
				break;
			}
		}
		if ( ! $has_branding ) {
			return;
		}
		$msg = __( 'Your order includes branding. We’ll email you a proof/mockup to approve before we produce anything — no branded items are made until you confirm placement and artwork.', 'corpmerch' );
		if ( $plain_text ) {
			echo "\n" . wp_strip_all_tags( $msg ) . "\n\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:14px 16px;border-left:4px solid #d97706;background:#fff7ed;color:#1f2937;font-size:14px;line-height:1.5;">'
			. esc_html( $msg ) . '</div>';
	}

	/**
	 * Render any "Artwork — {position}" order-item meta value as a clickable
	 * Download link (admin order screen + customer emails/account). Other meta
	 * values pass through unchanged.
	 *
	 * @param string $display_value The meta value as it will be shown.
	 * @param object $meta          The WC_Meta_Data object (->key, ->value).
	 * @param object $item          The order line item.
	 */
	public function render_artwork_meta_link( $display_value, $meta, $item ) {
		if ( ! is_object( $meta ) || empty( $meta->key ) ) {
			return $display_value;
		}
		// Match the localized "Artwork — " label prefix.
		$prefix = __( 'Artwork — ', 'corpmerch' );
		if ( 0 !== strpos( (string) $meta->key, $prefix ) ) {
			return $display_value;
		}
		$url = is_string( $meta->value ) ? trim( $meta->value ) : '';
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $display_value;
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" download>'
			. esc_html__( 'Download artwork', 'corpmerch' ) . '</a>';
	}

	/**
	 * Add the per-unit branding cost to each cart line's price. Runs on
	 * before_calculate_totals so the add-on is baked into the line price
	 * (×qty automatically). Setup is handled separately as a per-product fee.
	 */
	public function apply_branding_cost( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['cm_branding_cost'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$product = $cart_item['data'];
			$base    = (float) $product->get_price();
			$product->set_price( $base + (float) $cart_item['cm_branding_cost'] );
		}
	}

	/**
	 * Add a flat R199 setup fee per DISTINCT branded product line in the cart.
	 * Quantity within a line does not multiply the fee (it's a per-product
	 * setup, matching Kevro's setup-fee model).
	 */
	public function add_branding_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $this->enabled() ) {
			return;
		}
		$branded_products = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['cm_branding'] ) && is_array( $cart_item['cm_branding'] ) ) {
				// Count distinct products (parent id), not lines or units.
				$pid = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				if ( $pid ) {
					$branded_products[ $pid ] = true;
				}
			}
		}
		$count = count( $branded_products );
		if ( $count > 0 ) {
			// Sum each product's stored (markup-adjusted) setup; fall back to the
			// flat amount when a product has no per-product setup recorded.
			$total = 0.0;
			foreach ( array_keys( $branded_products ) as $pid ) {
				$setup  = (float) get_post_meta( $pid, '_kevro_branding_setup', true );
				$total += $setup > 0 ? $setup : self::FEE_AMOUNT;
			}
			$cart->add_fee(
				sprintf(
					/* translators: %d: number of branded products */
					_n( 'Branding setup fee', 'Branding setup fee (%d products)', $count, 'corpmerch' ),
					$count
				),
				$total,
				false // not taxable; adjust if branding should be taxed.
			);
		}
	}

	/* ------------------------------------------------ phase 3b: artwork upload */

	const UPLOAD_SUBDIR = 'sia-branding';
	const MAX_BYTES     = 10485760; // 10MB.

	private function allowed_types() {
		return array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'pdf'  => 'application/pdf',
			'svg'  => 'image/svg+xml',
			'ai'   => 'application/postscript',
			'eps'  => 'application/postscript',
		);
	}

	/** Provide the upload AJAX url + nonce to the front-end. */
	public function localize_upload() {
		if ( ! is_product() ) {
			return;
		}
		wp_register_script( 'sia-branding-inline', '', array(), CORPMERCH_VERSION, true );
		wp_enqueue_script( 'sia-branding-inline' );
		wp_localize_script(
			'sia-branding-inline',
			'siaBranding',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cm_branding_upload' ),
			)
		);
	}

	/** Handle an AJAX artwork upload; returns a token referencing the stored file. */
	public function ajax_upload() {
		if ( ! check_ajax_referer( 'cm_branding_upload', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'Security check failed.', 'corpmerch' ) ), 403 );
		}
		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'No file received.', 'corpmerch' ) ) );
		}
		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			wp_send_json_error( array( 'msg' => __( 'File is too large (max 10MB).', 'corpmerch' ) ) );
		}
		$allowed = $this->allowed_types();
		$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		$ext     = $check['ext'] ? $check['ext'] : strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! isset( $allowed[ $ext ] ) ) {
			wp_send_json_error( array( 'msg' => __( 'Unsupported file type. Use PNG, JPG, PDF, SVG, AI or EPS.', 'corpmerch' ) ) );
		}

		add_filter( 'upload_dir', array( $this, 'force_branding_dir' ) );
		$overrides = array(
			'test_form' => false,
			'mimes'     => $allowed,
			'unique_filename_callback' => array( $this, 'random_filename' ),
		);
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$moved = wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', array( $this, 'force_branding_dir' ) );

		if ( isset( $moved['error'] ) ) {
			wp_send_json_error( array( 'msg' => $moved['error'] ) );
		}
		// Token = stored filename only (resolved back to a path on order save).
		$token = basename( $moved['file'] );
		wp_send_json_success(
			array(
				'token' => $token,
				'name'  => sanitize_file_name( $file['name'] ),
				'msg'   => __( 'Artwork uploaded.', 'corpmerch' ),
			)
		);
	}

	/** Randomised, unguessable filename preserving the extension. */
	public function random_filename( $dir, $name, $ext ) {
		return wp_generate_uuid4() . strtolower( $ext );
	}

	/** Point uploads at the protected branding subdir during artwork upload. */
	public function force_branding_dir( $dirs ) {
		$dirs['subdir'] = '/' . self::UPLOAD_SUBDIR;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/** Ensure the branding upload dir has a deny rule (defence in depth). */
	public function maybe_protect_dir( $dirs ) {
		$base = ! empty( $dirs['basedir'] ) ? $dirs['basedir'] . '/' . self::UPLOAD_SUBDIR : '';
		if ( $base && ! file_exists( $base . '/.htaccess' ) ) {
			if ( ! is_dir( $base ) ) {
				wp_mkdir_p( $base );
			}
			// Block direct browsing/listing; files served via order admin only.
			@file_put_contents( $base . '/.htaccess', "Options -Indexes\n" ); // phpcs:ignore
			@file_put_contents( $base . '/index.html', '' ); // phpcs:ignore
		}
		return $dirs;
	}

	/** Resolve a stored token back to its absolute path + url, if it exists. */
	private function token_to_file( $token ) {
		$token = basename( (string) $token );
		if ( '' === $token ) {
			return null;
		}
		$up   = wp_get_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . self::UPLOAD_SUBDIR . '/' . $token;
		$url  = trailingslashit( $up['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . $token;
		return file_exists( $path ) ? array( 'path' => $path, 'url' => $url ) : null;
	}
}

new Corpmerch_Branding();
