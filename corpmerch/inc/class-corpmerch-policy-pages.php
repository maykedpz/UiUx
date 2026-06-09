<?php
/**
 * Policy Pages — auto-create the storefront's required policy/support pages and
 * render their content LIVE from the Business Information settings.
 *
 * Design:
 *  - Each page's content is a single shortcode, e.g. [corpmerch_policy type="returns"].
 *  - The shortcode renders from the current biz_* options on every page load, so
 *    editing the Business Info tab updates every page instantly (no regeneration).
 *  - Missing pages are created and PUBLISHED automatically on load (idempotent;
 *    tracked by stored IDs and slug match, never duplicated).
 *  - Footer URL options (footer_url_*) are kept in sync for the footer links.
 *  - VAT wording only appears when biz_vat_enabled is on and a number is set.
 *
 * @package Corpmerch
 */

defined( 'ABSPATH' ) || exit;

class Corpmerch_Policy_Pages {

	/** Option mapping policy key => created page ID. */
	const STATE_KEY = '_cm_policy_page_ids';

	/** Bumped when page definitions change, to retrigger auto-create. */
	const VERSION_KEY = '_cm_policy_pages_version';
	const VERSION     = 3;

	private static $ajax_bound = false;

	public function __construct() {
		add_shortcode( 'corpmerch_policy', array( $this, 'shortcode' ) );
		// Auto-create missing pages once per load (cheap: guarded by a version flag).
		add_action( 'init', array( $this, 'maybe_autocreate' ), 20 );
		if ( ! self::$ajax_bound ) {
			add_action( 'wp_ajax_cm_policy_create', array( $this, 'ajax_create' ) );
			self::$ajax_bound = true;
		}
	}

	/** Page definitions: key => [title, slug, footer option to sync]. */
	private function definitions() {
		return array(
			'returns'  => array( 'title' => __( 'Returns & Refund Policy', 'corpmerch' ), 'slug' => 'returns-refund-policy', 'url_opt' => 'footer_url_returns' ),
			'shipping' => array( 'title' => __( 'Shipping Policy', 'corpmerch' ),         'slug' => 'shipping-policy',        'url_opt' => 'footer_url_shipping' ),
			'terms'    => array( 'title' => __( 'Terms & Conditions', 'corpmerch' ),      'slug' => 'terms-conditions',       'url_opt' => 'footer_url_terms' ),
			'privacy'  => array( 'title' => __( 'Privacy Policy', 'corpmerch' ),          'slug' => 'privacy-policy',         'url_opt' => 'footer_url_privacy' ),
			'popia'    => array( 'title' => __( 'POPIA Notice', 'corpmerch' ),            'slug' => 'popia',                  'url_opt' => 'footer_url_popia' ),
			'contact'  => array( 'title' => __( 'Contact Us', 'corpmerch' ),              'slug' => 'contact',                'url_opt' => 'footer_url_contact' ),
			'help'     => array( 'title' => __( 'Help & Support', 'corpmerch' ),          'slug' => 'help',                   'url_opt' => 'footer_url_help' ),
			'track'    => array( 'title' => __( 'Track My Order', 'corpmerch' ),          'slug' => 'track-order',            'url_opt' => 'footer_url_track' ),
		);
	}

	/* ----------------------------------------------------------- value source */

	/**
	 * Read a business value from the new biz_* option, falling back to the older
	 * footer_biz_* option, then to a visible placeholder.
	 */
	private function biz( $key, $fallback_key, $placeholder ) {
		$v = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( $key, '' ) ) : '';
		if ( '' === $v && $fallback_key ) {
			$v = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( $fallback_key, '' ) ) : '';
		}
		return '' !== $v ? $v : $placeholder;
	}

	private function vat_active() {
		$on  = function_exists( 'corpmerch_bool' ) ? corpmerch_bool( 'biz_vat_enabled', false ) : false;
		$num = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( 'biz_vat_number', '' ) ) : '';
		return $on && '' !== $num;
	}

	/** Collected business values used across pages. */
	private function vars() {
		return array(
			'name'      => $this->biz( 'biz_name', 'footer_biz_name', '[BUSINESS NAME]' ),
			'reg'       => $this->biz( 'biz_reg', 'footer_biz_reg', '[REG NO]' ),
			'addr'      => $this->biz( 'biz_address', 'footer_biz_address', '[ADDRESS]' ),
			'email'     => $this->biz( 'biz_email', 'footer_biz_email', '[SUPPORT EMAIL]' ),
			'phone'     => $this->biz( 'biz_phone', 'footer_biz_phone', '[SUPPORT PHONE]' ),
			'hours'     => $this->biz( 'biz_hours', '', 'Mon–Fri, 08:00–17:00 SAST' ),
			'response'  => $this->biz( 'biz_response_time', '', 'within 1 business day' ),
			'officer'   => $this->biz( 'biz_info_officer', '', '[INFORMATION OFFICER NAME]' ),
			'areas'     => $this->biz( 'biz_delivery_areas', '', 'addresses across South Africa' ),
			'partner'   => $this->biz( 'biz_delivery_partner', '', 'our courier partner' ),
			'proc'      => $this->biz( 'biz_processing_time', '', '1–2 business days' ),
			'lead'      => $this->biz( 'biz_lead_time', '', '2–4 business days' ),
			'freeship'  => $this->biz( 'biz_freeship_threshold', '', 'R1000' ),
			'returnwin' => $this->biz( 'biz_return_window', '', '14 days' ),
			'refund'    => $this->biz( 'biz_refund_time', '', '5–10 business days' ),
			'vatnum'    => function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( 'biz_vat_number', '' ) ) : '',
		);
	}

	/* -------------------------------------------------------------- shortcode */

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'type' => '' ), $atts, 'corpmerch_policy' );
		$type = sanitize_key( $atts['type'] );
		$defs = $this->definitions();
		if ( ! isset( $defs[ $type ] ) ) {
			return '';
		}
		$paras = $this->paragraphs( $type, $this->vars() );
		$out   = '';
		foreach ( $paras as $p ) {
			$out .= '<p>' . wp_kses_post( $p ) . '</p>' . "\n";
		}
		return '<div class="cm-policy cm-policy--' . esc_attr( $type ) . '">' . $out . '</div>';
	}

	/** Build the paragraph list for a policy from live business values. */
	private function paragraphs( $which, $v ) {
		$vat_line = $this->vat_active()
			? sprintf( __( 'All prices include VAT at the standard rate. Our VAT registration number is %s.', 'corpmerch' ), $v['vatnum'] )
			: '';

		switch ( $which ) {
			case 'returns':
				$p = array(
					sprintf( __( 'At %s, the price you see is the price you pay — and that honesty extends to how we handle returns. No hidden restocking traps, no fine-print loopholes.', 'corpmerch' ), $v['name'] ),
					sprintf( __( '<strong>Your return window.</strong> You may request a return within %s of receiving your order, provided the item is unused, in its original condition, and in its original packaging with all tags and accessories.', 'corpmerch' ), $v['returnwin'] ),
					sprintf( __( '<strong>Branded items.</strong> Products customised with your logo or artwork are made to order and cannot be returned for change of mind, as they cannot be resold. This does not affect your rights where a branded item is faulty or not as agreed.', 'corpmerch' ) ),
					sprintf( __( '<strong>How to start a return.</strong> Email %1$s with your order number and the reason for the return. We will reply with clear instructions and, where applicable, a return address. We aim to respond %2$s.', 'corpmerch' ), $v['email'], $v['response'] ),
					sprintf( __( '<strong>Refunds.</strong> Once we receive and inspect the returned item, we will notify you of approval. Approved refunds are issued to your original payment method within %s. We refund the price you actually paid, with no surprise deductions beyond any return shipping cost stated below.', 'corpmerch' ), $v['refund'] ),
					__( '<strong>Faulty, damaged, or incorrect items.</strong> If an item arrives faulty, damaged, or different from what you ordered, contact us as soon as possible. We cover the cost of return and will repair, replace, or refund in full where the law allows.', 'corpmerch' ),
					__( '<strong>Return shipping costs.</strong> For change-of-mind returns, return shipping is the customer\'s responsibility. For faulty or incorrect items, we cover it.', 'corpmerch' ),
					__( '<strong>Your statutory rights.</strong> Nothing in this policy limits your rights under the South African Consumer Protection Act.', 'corpmerch' ),
					sprintf( __( 'Questions? Email %1$s or call %2$s.', 'corpmerch' ), $v['email'], $v['phone'] ),
				);
				break;

			case 'shipping':
				$p = array(
					__( 'We keep shipping as transparent as our pricing. What you are quoted at checkout is what you pay — no add-ons appear later.', 'corpmerch' ),
					sprintf( __( '<strong>Where we deliver.</strong> We currently ship to %s.', 'corpmerch' ), $v['areas'] ),
					sprintf( __( '<strong>Processing time.</strong> Orders are processed within %s of payment confirmation. You will receive a confirmation email when your order is placed and another when it ships. Branded orders include time for proofing and production, which we confirm with you.', 'corpmerch' ), $v['proc'] ),
					sprintf( __( '<strong>Delivery time.</strong> Once dispatched, delivery via %1$s typically takes %2$s, depending on your location. Remote or outlying areas may take longer.', 'corpmerch' ), $v['partner'], $v['lead'] ),
					sprintf( __( '<strong>Shipping costs.</strong> Shipping is calculated at checkout based on your address and order. The full cost is shown before you pay — always. Orders over %s qualify for free standard delivery.', 'corpmerch' ), $v['freeship'] ),
					__( '<strong>Tracking.</strong> Where your delivery method supports it, you will receive a tracking link by email so you can follow your parcel.', 'corpmerch' ),
					sprintf( __( '<strong>Delays.</strong> We dispatch on time wherever possible, but couriers, weather, and peak periods can occasionally cause delays outside our control. If your order is running late, contact %s and we will chase it for you.', 'corpmerch' ), $v['email'] ),
					__( '<strong>Incorrect addresses.</strong> Please double-check your delivery address at checkout. We cannot be responsible for parcels sent to an address entered incorrectly, though we will always try to help recover them.', 'corpmerch' ),
					sprintf( __( 'Questions? Email %1$s or call %2$s.', 'corpmerch' ), $v['email'], $v['phone'] ),
				);
				break;

			case 'privacy':
				$p = array(
					sprintf( __( '%s ("we", "us") respects your privacy. This policy explains what we collect, why, and how we protect it.', 'corpmerch' ), $v['name'] ),
					sprintf( __( '<strong>Who we are.</strong> %1$s, registration number %2$s, %3$s. Contact: %4$s.', 'corpmerch' ), $v['name'], $v['reg'], $v['addr'], $v['email'] ),
					__( '<strong>Information we collect.</strong> Order information (name, delivery address, email, phone, order details); payment information (processed securely by our payment provider — we do not store full card details on our servers); technical information (IP address, browser type, pages visited) via cookies and analytics.', 'corpmerch' ),
					__( '<strong>How we use your information.</strong> To process and deliver your orders, provide customer support, send order-related communications, comply with legal obligations, and — only with your consent — send marketing such as our newsletter. You can unsubscribe from marketing at any time.', 'corpmerch' ),
					__( '<strong>Cookies.</strong> We use cookies for essential site functions (like your cart) and, with your consent where required, for analytics. You can control cookies through your browser settings.', 'corpmerch' ),
					sprintf( __( '<strong>Sharing your information.</strong> We share data only with parties needed to fulfil your order — for example %s and our payment processor — and where required by law. We do not sell your personal information.', 'corpmerch' ), $v['partner'] ),
					__( '<strong>Data retention.</strong> We keep your information only as long as necessary for the purposes above or as required by law.', 'corpmerch' ),
					sprintf( __( '<strong>Your rights.</strong> You may request access to, correction of, or deletion of your personal information, subject to legal limits. To exercise these rights, email %s.', 'corpmerch' ), $v['email'] ),
					__( '<strong>Security.</strong> We use reasonable technical and organisational measures, including HTTPS encryption, to protect your data. No method of transmission is completely secure, but we work to safeguard your information.', 'corpmerch' ),
					__( '<strong>Compliance.</strong> This policy is intended to align with South Africa\'s Protection of Personal Information Act (POPIA).', 'corpmerch' ),
					sprintf( __( 'Questions? Email %s.', 'corpmerch' ), $v['email'] ),
				);
				break;

			case 'terms':
				$p = array(
					sprintf( __( 'Welcome to %s. By using this website and placing an order, you agree to these terms.', 'corpmerch' ), $v['name'] ),
					sprintf( __( '<strong>1. Who we are.</strong> %1$s, registration number %2$s, %3$s. Contact: %4$s / %5$s.', 'corpmerch' ), $v['name'], $v['reg'], $v['addr'], $v['email'], $v['phone'] ),
					( '' !== $vat_line ? '<strong>2. Pricing and VAT.</strong> The price you see is the price you pay. We do not advertise fake discounts, inflated "was" prices, or fabricated countdown timers. ' . $vat_line . ' Any delivery cost is shown clearly at checkout before you pay.'
						: __( '<strong>2. Our honest-pricing promise.</strong> The price you see is the price you pay. We do not advertise fake discounts, inflated "was" prices, or fabricated countdown timers. Every price shown is the genuine selling price. Any delivery cost is shown clearly at checkout before you pay.', 'corpmerch' ) ),
					__( '<strong>3. Products and branding.</strong> We describe and picture products as accurately as we can. Minor variations in colour or detail can occur. Branded items are produced to your approved proof; please check proofs carefully, as approved artwork is produced as supplied.', 'corpmerch' ),
					__( '<strong>4. Orders and acceptance.</strong> Placing an order is an offer to buy. A contract is formed once we confirm and dispatch your order. We may decline or cancel an order — for example if an item is out of stock or a pricing error occurs — and will refund any payment in full in that case.', 'corpmerch' ),
					__( '<strong>5. Pricing errors.</strong> While we take great care, genuine errors can happen. If we discover a clear pricing error before dispatch, we will contact you to confirm whether you still wish to proceed at the correct price, or cancel and refund you.', 'corpmerch' ),
					__( '<strong>6. Payment.</strong> Payment is taken securely through our payment provider at checkout. Orders ship once payment is confirmed.', 'corpmerch' ),
					__( '<strong>7. Delivery and returns.</strong> Delivery is governed by our Shipping Policy and returns by our Returns & Refund Policy, both of which form part of these terms.', 'corpmerch' ),
					__( '<strong>8. Your account.</strong> If you create an account, keep your login details secure. You are responsible for activity under your account.', 'corpmerch' ),
					sprintf( __( '<strong>9. Intellectual property.</strong> All content on this site — logos, text, and images — belongs to %s or its suppliers and may not be reused without permission.', 'corpmerch' ), $v['name'] ),
					__( '<strong>10. Limitation of liability.</strong> To the extent permitted by law, our liability is limited to the value of the order in question. Nothing here limits your rights under the Consumer Protection Act.', 'corpmerch' ),
					__( '<strong>11. Governing law.</strong> These terms are governed by the laws of South Africa.', 'corpmerch' ),
					__( '<strong>12. Changes.</strong> We may update these terms; the current version will always appear on this page.', 'corpmerch' ),
					sprintf( __( 'Questions? Email %1$s or call %2$s.', 'corpmerch' ), $v['email'], $v['phone'] ),
				);
				break;

			case 'contact':
				$p = array(
					sprintf( __( 'Get in touch with %s — we are happy to help with product questions, branding, quotes, and orders.', 'corpmerch' ), $v['name'] ),
					sprintf( __( '<strong>Email.</strong> %1$s — we reply %2$s.', 'corpmerch' ), $v['email'], $v['response'] ),
					sprintf( __( '<strong>Phone.</strong> %1$s, %2$s.', 'corpmerch' ), $v['phone'], $v['hours'] ),
					sprintf( __( '<strong>Address.</strong> %s', 'corpmerch' ), nl2br( $v['addr'] ) ),
					sprintf( __( '<strong>Business details.</strong> %1$s, registration number %2$s.%3$s', 'corpmerch' ), $v['name'], $v['reg'], ( $this->vat_active() ? ' ' . sprintf( __( 'VAT number %s.', 'corpmerch' ), $v['vatnum'] ) : '' ) ),
				);
				break;

			case 'help':
				$p = array(
					sprintf( __( 'Need a hand? Most questions are answered below. If not, email %1$s and we will reply %2$s.', 'corpmerch' ), $v['email'], $v['response'] ),
					__( '<strong>How do I order?</strong> Browse the catalogue, choose your product and options, and check out. For branded orders, you can request your logo be added — we send a proof before production.', 'corpmerch' ),
					sprintf( __( '<strong>How long does delivery take?</strong> Orders are processed within %1$s and delivered within %2$s after dispatch via %3$s. See our Shipping Policy for detail.', 'corpmerch' ), $v['proc'], $v['lead'], $v['partner'] ),
					sprintf( __( '<strong>How much is delivery?</strong> It is calculated at checkout and shown before you pay. Orders over %s ship free.', 'corpmerch' ), $v['freeship'] ),
					sprintf( __( '<strong>Can I return something?</strong> Yes — unbranded items within %1$s, subject to our Returns & Refund Policy. Refunds are processed within %2$s.', 'corpmerch' ), $v['returnwin'], $v['refund'] ),
					__( '<strong>How does branding work?</strong> Pick a product, choose a branding position, and upload your artwork. We send a proof to approve before we produce. Branding is quoted separately from the product price.', 'corpmerch' ),
					sprintf( __( '<strong>How do I track my order?</strong> Use our Track My Order page, or the tracking link we email you. Still stuck? Email %s.', 'corpmerch' ), $v['email'] ),
					sprintf( __( 'Still need help? Email %1$s or call %2$s, %3$s.', 'corpmerch' ), $v['email'], $v['phone'], $v['hours'] ),
				);
				break;

			case 'track':
				$p = array(
					__( '<strong>Track your order.</strong> When your order ships, we email you a tracking link. Click that link to see your parcel\'s latest status.', 'corpmerch' ),
					sprintf( __( 'If you have an account, you can also view your orders and their status by logging in. Orders typically dispatch within %1$s and arrive within %2$s after dispatch.', 'corpmerch' ), $v['proc'], $v['lead'] ),
					sprintf( __( 'Can\'t find your tracking link or think your order is delayed? Email %1$s with your order number and we will track it down for you — we reply %2$s.', 'corpmerch' ), $v['email'], $v['response'] ),
				);
				break;

			case 'popia':
				$p = array(
					sprintf( __( 'This notice explains how %1$s processes personal information in terms of the Protection of Personal Information Act, 2013 (POPIA). It should be read together with our Privacy Policy.', 'corpmerch' ), $v['name'] ),
					sprintf( __( '<strong>Responsible party.</strong> %1$s, registration number %2$s, %3$s.', 'corpmerch' ), $v['name'], $v['reg'], $v['addr'] ),
					sprintf( __( '<strong>Information Officer.</strong> Our designated Information Officer is %1$s, who is responsible for our compliance with POPIA and for handling requests and complaints relating to your personal information. You can reach the Information Officer at %2$s.', 'corpmerch' ), $v['officer'], $v['email'] ),
					__( '<strong>How we process your information.</strong> We collect and process personal information lawfully and only for the purposes set out in our Privacy Policy — chiefly to process and deliver your orders, provide support, meet legal obligations, and (with your consent) send marketing. We take reasonable steps to keep it accurate, secure, and no longer than necessary.', 'corpmerch' ),
					__( '<strong>Your rights under POPIA.</strong> You have the right to be told what personal information we hold about you, to request access to it, to ask us to correct or delete information that is inaccurate or no longer needed, to object to processing in certain circumstances, and to withdraw consent to marketing at any time.', 'corpmerch' ),
					sprintf( __( '<strong>Making a request.</strong> To exercise any of these rights, email our Information Officer at %s. We will respond within a reasonable time and in line with POPIA.', 'corpmerch' ), $v['email'] ),
					__( '<strong>Lodging a complaint with the Regulator.</strong> If you believe we have not handled your personal information lawfully, you may lodge a complaint with the Information Regulator. A complaint is submitted on the prescribed POPIA/PAIA Form 5.', 'corpmerch' ),
					__( '<strong>Information Regulator (South Africa).</strong> Woodmead North Office Park, 54 Maxwell Drive, Woodmead, Johannesburg, 2191. General enquiries: enquiries@inforegulator.org.za, 010 023 5200 (toll-free 0800 017 160). POPIA complaints: POPIAComplaints@inforegulator.org.za. Website: https://inforegulator.org.za.', 'corpmerch' ),
					sprintf( __( 'Questions about this notice? Email %1$s or call %2$s.', 'corpmerch' ), $v['email'], $v['phone'] ),
				);
				break;

			default:
				$p = array();
		}
		return $p;
	}

	/* ------------------------------------------------------------- autocreate */

	/** Create any missing pages (published) and sync footer URL options. */
	public function maybe_autocreate() {
		if ( ! is_admin() && (int) get_option( self::VERSION_KEY ) === self::VERSION ) {
			return; // front-end fast path once provisioned.
		}
		// Only run the write path in admin or via the version gate, to avoid
		// doing inserts on every front-end request.
		if ( (int) get_option( self::VERSION_KEY ) === self::VERSION ) {
			return;
		}
		$this->create_all( true );
		update_option( self::VERSION_KEY, self::VERSION );
	}

	/**
	 * Ensure every defined page exists. Each page's content is just its
	 * shortcode, so content stays live. Returns per-page result rows.
	 *
	 * @param bool $publish Publish new pages (true) or leave as draft (false).
	 */
	public function create_all( $publish = true ) {
		$state = get_option( self::STATE_KEY, array() );
		$state = is_array( $state ) ? $state : array();
		$opts  = get_option( Corpmerch_Settings::OPTION_KEY, array() );
		$opts  = is_array( $opts ) ? $opts : array();
		$rows  = array();

		foreach ( $this->definitions() as $key => $def ) {
			$shortcode   = '<!-- wp:shortcode -->[corpmerch_policy type="' . $key . '"]<!-- /wp:shortcode -->';
			$existing_id = isset( $state[ $key ] ) ? (int) $state[ $key ] : 0;
			$page        = $existing_id ? get_post( $existing_id ) : null;

			if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
				$id     = $existing_id;
				$action = __( 'exists', 'corpmerch' );
				// Keep the shortcode present so content stays live even if edited away.
				if ( false === strpos( (string) $page->post_content, 'corpmerch_policy' ) ) {
					wp_update_post( array( 'ID' => $id, 'post_content' => $shortcode ) );
					$action = __( 'restored shortcode', 'corpmerch' );
				}
			} else {
				$by_path = get_page_by_path( $def['slug'] );
				if ( $by_path instanceof WP_Post ) {
					$id     = (int) $by_path->ID;
					$action = __( 'linked existing slug', 'corpmerch' );
				} else {
					$id = wp_insert_post(
						array(
							'post_title'   => $def['title'],
							'post_name'    => $def['slug'],
							'post_content' => $shortcode,
							'post_status'  => $publish ? 'publish' : 'draft',
							'post_type'    => 'page',
						),
						true
					);
					if ( is_wp_error( $id ) ) {
						$rows[] = array( 'policy' => $def['title'], 'status' => 'error', 'detail' => $id->get_error_message() );
						continue;
					}
					$id     = (int) $id;
					$action = $publish ? __( 'created & published', 'corpmerch' ) : __( 'created as draft', 'corpmerch' );
				}
				$state[ $key ] = $id;
			}

			$url                     = get_permalink( $id );
			$opts[ $def['url_opt'] ] = esc_url_raw( $url );
			$rows[]                  = array(
				'policy' => $def['title'],
				'status' => 'ok',
				'detail' => $action,
				'id'     => $id,
				'edit'   => get_edit_post_link( $id, 'raw' ),
				'url'    => $url,
			);
		}

		update_option( self::STATE_KEY, $state );
		update_option( Corpmerch_Settings::OPTION_KEY, $opts );
		return $rows;
	}

	public function ajax_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'corpmerch' ) ), 403 );
		}
		check_ajax_referer( 'cm_policy', 'nonce' );
		$rows = $this->create_all( true );
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	/** Admin status panel + re-create button (rendered on the Business Info tab). */
	public function render_button() {
		$nonce = wp_create_nonce( 'cm_policy' );
		$state = get_option( self::STATE_KEY, array() );
		$state = is_array( $state ) ? $state : array();
		echo '<div id="sia-policy-tools" data-nonce="' . esc_attr( $nonce ) . '">';
		echo '<p class="description" style="margin:0 0 8px;max-width:680px;">' . esc_html__( 'These pages are created and published automatically and render live from the fields above — edit a field and every page updates. Use this button only to re-create a page you deleted.', 'corpmerch' ) . '</p>';
		echo '<ul style="margin:0 0 10px;list-style:disc;padding-left:20px;font-size:13px;max-width:680px;">';
		foreach ( $this->definitions() as $key => $def ) {
			$id   = isset( $state[ $key ] ) ? (int) $state[ $key ] : 0;
			$post = $id ? get_post( $id ) : null;
			if ( $post && 'trash' !== $post->post_status ) {
				$status = ( 'publish' === $post->post_status ) ? __( 'published', 'corpmerch' ) : $post->post_status;
				echo '<li>' . esc_html( $def['title'] ) . ' — <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( $status ) . '</a> · <a href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">' . esc_html__( 'edit', 'corpmerch' ) . '</a></li>';
			} else {
				echo '<li style="color:#b32d2e;">' . esc_html( $def['title'] ) . ' — ' . esc_html__( 'missing', 'corpmerch' ) . '</li>';
			}
		}
		echo '</ul>';
		echo '<p><button type="button" class="button button-primary" id="sia-policy-create">' . esc_html__( 'Create / refresh missing pages', 'corpmerch' ) . '</button></p>';
		echo '<div id="sia-policy-status" style="margin:6px 0;font-weight:600;"></div>';
		?>
		<script>
		jQuery(function($){
			var box=$('#sia-policy-tools'), nonce=box.data('nonce');
			$('#sia-policy-create').on('click',function(){
				var btn=$(this).prop('disabled',true);
				$('#sia-policy-status').css('color','#1d2327').text('<?php echo esc_js( __( 'Working…', 'corpmerch' ) ); ?>');
				$.post(ajaxurl,{action:'cm_policy_create',nonce:nonce})
				.done(function(r){
					if(!r||!r.success){ $('#sia-policy-status').css('color','#b32d2e').text('<?php echo esc_js( __( 'Failed.', 'corpmerch' ) ); ?> '+((r&&r.data&&r.data.message)||'')); return; }
					$('#sia-policy-status').css('color','#207520').text('<?php echo esc_js( __( 'Done — reload to refresh the list.', 'corpmerch' ) ); ?>');
				})
				.fail(function(x){ $('#sia-policy-status').css('color','#b32d2e').text('HTTP '+x.status); })
				.always(function(){ btn.prop('disabled',false); });
			});
		});
		</script>
		<?php
		echo '</div>';
	}
}

new Corpmerch_Policy_Pages();
