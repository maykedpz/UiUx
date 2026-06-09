<?php
/**
 * Corpmerch — Kevro StockFeed importer.
 *
 * Hand-rolled SOAP 1.1 client over the WP HTTP API (no PHP SoapClient needed),
 * with HTTP Basic auth + method-level credentials. Pulls GetFeedByEntityID,
 * parses the ResponseData feed, groups rows by StockHeaderID into variable
 * WooCommerce products (Colour/Size variations), maps price/stock/brand/
 * category/images, and upserts idempotently. Products import as Draft for
 * review. Quantities (deprecated but present in the feed) become WC stock.
 *
 * Endpoint: https://wslive.kevro.co.za/StockFeed.asmx
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Kevro {

	const ENDPOINT  = 'https://wslive.kevro.co.za/StockFeed.asmx';
	const NS        = 'https://wslive.kevro.co.za/StockFeed.asmx';
	const CRON_HOOK   = 'corpmerch_kevro_cron';
	const RUNNER_HOOK = 'corpmerch_kevro_runner';
	const WATCH_HOOK  = 'corpmerch_kevro_watchdog';
	const BRAND_HOOK  = 'corpmerch_kevro_branding_runner';
	const BRAND_QUEUE = 'cm_kevro_brand_queue';
	const BRAND_STATE = 'cm_kevro_brand_state';
	const QUEUE_KEY   = 'cm_kevro_queue';
	const STATE_KEY   = 'cm_kevro_state';
	const LOCK_KEY    = 'cm_kevro_lock';
	const LOG_KEY     = 'cm_kevro_last_run';
	const FAILED_IMG_KEY = 'cm_kevro_failed_images';
	const QTY_CANDIDATES = array( 'quantity', 'qty', 'qtyavailable', 'availableqty', 'soh', 'stockonhand', 'onhand', 'qtyonhand' );

	/** Diagnostics from the most recent SOAP call (for Fetch & preview). */
	private $debug = array( 'body' => '', 'data' => '' );

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'cron_run' ) );
		add_action( self::RUNNER_HOOK, array( $this, 'process_batch' ) );
		add_action( self::WATCH_HOOK, array( $this, 'watchdog' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_intervals' ) );
		add_action( 'init', array( $this, 'reconcile_schedule' ) );
		add_action( 'after_switch_theme', array( $this, 'seed_credentials' ) );
		add_action( 'admin_init', array( $this, 'seed_credentials' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_cm_kevro_test', array( $this, 'ajax_test' ) );
			add_action( 'wp_ajax_cm_kevro_preview', array( $this, 'ajax_preview' ) );
			add_action( 'wp_ajax_cm_kevro_start', array( $this, 'ajax_start' ) );
			add_action( 'wp_ajax_cm_kevro_status', array( $this, 'ajax_status' ) );
			add_action( 'wp_ajax_cm_kevro_stop', array( $this, 'ajax_stop' ) );
			add_action( 'wp_ajax_cm_kevro_purge', array( $this, 'ajax_purge' ) );
			add_action( 'wp_ajax_cm_kevro_retry_images', array( $this, 'ajax_retry_images' ) );
			add_action( 'wp_ajax_cm_kevro_branding_inspect', array( $this, 'ajax_branding_inspect' ) );
			add_action( 'wp_ajax_cm_kevro_branding_sync', array( $this, 'ajax_branding_sync' ) );
			add_action( 'wp_ajax_cm_kevro_branding_sync_status', array( $this, 'ajax_branding_sync_status' ) );
		}
		add_action( self::BRAND_HOOK, array( $this, 'branding_batch' ) );
	}

	/** Add a one-minute interval for the import watchdog. */
	public function cron_intervals( $schedules ) {
		if ( ! isset( $schedules['cm_minute'] ) ) {
			$schedules['cm_minute'] = array( 'interval' => 60, 'display' => 'Every minute (Corpmerch)' );
		}
		return $schedules;
	}

	/** One-time prefill of the supplied Kevro credentials (never overwrites edits). */
	public function seed_credentials() {
		if ( get_option( 'cm_kevro_seeded' ) ) {
			return;
		}
		$defaults = array(
			'kevro_entity_id'   => '45780',
			'kevro_entity_name' => 'Customer-AITAM-45780',
			'kevro_ws_user'     => 'AITAM',
			'kevro_ws_pass'     => '8lbwMtUHI90=',
			'kevro_token'       => 'T4QzhLB5UP8hygrUeEchBLdz9LtK2nSz',
			'kevro_basic_user'  => 'stkusr',
			'kevro_basic_pass'  => 'B@rron0n',
			'kevro_return_type' => 'JSON',
		);
		$opts = get_option( Corpmerch_Settings::OPTION_KEY, array() );
		$opts = is_array( $opts ) ? $opts : array();
		foreach ( $defaults as $k => $v ) {
			if ( ! array_key_exists( $k, $opts ) || '' === $opts[ $k ] ) {
				$opts[ $k ] = $v;
			}
		}
		update_option( Corpmerch_Settings::OPTION_KEY, $opts );
		update_option( 'cm_kevro_seeded', 1 );
	}

	/* --------------------------------------------------------- credentials */

	private function creds() {
		return array(
			'entity_id'   => (int) corpmerch_option( 'kevro_entity_id', 0 ),
			'entity_name' => corpmerch_option( 'kevro_entity_name', '' ),
			'ws_user'     => corpmerch_option( 'kevro_ws_user', '' ),
			'ws_pass'     => corpmerch_option( 'kevro_ws_pass', '' ),
			'token'       => corpmerch_option( 'kevro_token', '' ),
			'basic_user'  => corpmerch_option( 'kevro_basic_user', '' ),
			'basic_pass'  => corpmerch_option( 'kevro_basic_pass', '' ),
			'return_type' => corpmerch_option( 'kevro_return_type', 'JSON' ),
		);
	}

	private function ready() {
		$c = $this->creds();
		return $c['entity_id'] > 0 && '' !== $c['ws_user'] && '' !== $c['ws_pass'];
	}

	/* ----------------------------------------------------------- SOAP core */

	private function soap_call( $method, $params, $cookies = array() ) {
		$c   = $this->creds();
		$inner = '';
		foreach ( $params as $k => $v ) {
			$inner .= '<' . $k . '>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</' . $k . '>';
		}
		$env = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><' . $method . ' xmlns="' . self::NS . '">' . $inner . '</' . $method . '></soap:Body></soap:Envelope>';

		$args = array(
			'timeout' => 180,
			'headers' => array(
				'Content-Type'  => 'text/xml; charset=utf-8',
				'SOAPAction'    => '"' . self::NS . '/' . $method . '"',
				'Accept'        => 'text/xml',
				'User-Agent'    => 'Corpmerch/Kevro-Sync',
			),
			'body'    => $env,
		);
		if ( '' !== $c['basic_user'] ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $c['basic_user'] . ':' . $c['basic_pass'] );
		}
		if ( ! empty( $cookies ) ) {
			$args['cookies'] = $cookies; // Carry the ASP.NET session cookie from login().
		}

		$res = wp_remote_post( self::ENDPOINT, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$this->debug['body'] = mb_substr( (string) $body, 0, 1500 ); // head only — full body can be many MB.
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'kevro_auth', 'Kevro rejected Basic Auth (HTTP ' . $code . ').' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$fault = '';
			if ( preg_match( '#<faultstring[^>]*>(.*?)</faultstring>#is', $body, $m ) ) {
				$fault = trim( html_entity_decode( $m[1] ) );
			}
			return new WP_Error( 'kevro_http', 'Kevro returned HTTP ' . $code . ( $fault ? ': ' . $fault : '.' ) );
		}
		if ( '' === $body ) {
			return new WP_Error( 'kevro_http', 'Empty response (HTTP ' . $code . ').' );
		}
		$parsed = $this->parse_envelope( $body );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$parsed['cookies'] = wp_remote_retrieve_cookies( $res ); // Pass session forward.
		return $parsed;
	}

	/** Extract Callresult / ResponseData / ErrorMsg (binding-agnostic). */
	private function parse_envelope( $xml ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		// PARSEHUGE: the full feed lives in ResponseData as one escaped text
		// node that routinely exceeds libxml's default 10MB-per-node limit.
		$loaded = $doc->loadXML( $xml, LIBXML_PARSEHUGE );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return new WP_Error( 'kevro_parse', 'Could not parse SOAP response.' );
		}
		$xp = new DOMXPath( $doc );

		$fault = $xp->query( "//*[local-name()='Fault']/*[local-name()='faultstring']" );
		if ( $fault->length ) {
			return new WP_Error( 'kevro_fault', trim( $fault->item( 0 )->textContent ) );
		}

		$call = $xp->query( "//*[local-name()='Callresult']" );
		$data = $xp->query( "//*[local-name()='ResponseData']" );
		$err  = $xp->query( "//*[local-name()='ErrorMsg']" );

		$ok = $call->length ? in_array( strtolower( trim( $call->item( 0 )->textContent ) ), array( 'true', '1' ), true ) : false;

		// ResponseData carries the whole feed as a string (escaped XML or JSON).
		$payload = $data->length ? $data->item( 0 )->textContent : '';

		return array(
			'ok'    => $ok,
			'data'  => $payload,
			'error' => $err->length ? trim( $err->item( 0 )->textContent ) : '',
		);
	}

	/* ------------------------------------------------------------- methods */

	public function test_login() {
		$c = $this->creds();
		return $this->soap_call(
			'login',
			array(
				'TokenKey'   => $c['token'],
				'username'   => $c['ws_user'],
				'psw'        => $c['ws_pass'],
				'EntityName' => $c['entity_name'],
				'entityID'   => $c['entity_id'],
			)
		);
	}

	public function get_feed() {
		$c = $this->creds();

		// Kevro's .asmx is session-based: open a session with login(), then
		// reuse its cookie on the feed call or the server reports the session expired.
		$login = $this->test_login();
		if ( is_wp_error( $login ) ) {
			return $login;
		}
		if ( empty( $login['ok'] ) ) {
			return new WP_Error( 'kevro_login', $login['error'] ? $login['error'] : 'Login failed.' );
		}
		$cookies = ! empty( $login['cookies'] ) ? $login['cookies'] : array();

		$r = $this->soap_call(
			'GetFeedByEntityID',
			array(
				'entityID'   => $c['entity_id'],
				'username'   => $c['ws_user'],
				'psw'        => $c['ws_pass'],
				'ReturnType' => $c['return_type'],
			),
			$cookies
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['ok'] ) ) {
			return new WP_Error( 'kevro_feed', $r['error'] ? $r['error'] : 'Feed call returned no data.' );
		}
		$this->debug['data'] = mb_substr( (string) $r['data'], 0, 4000 ); // head only.
		return (string) $r['data'];
	}

	/* ------------------------------------------------ branding / features API */

	/**
	 * Open a Kevro session and return its cookies (login is session-based).
	 *
	 * @return array|WP_Error Cookie array on success.
	 */
	private function open_session() {
		$login = $this->test_login();
		if ( is_wp_error( $login ) ) {
			return $login;
		}
		if ( empty( $login['ok'] ) ) {
			return new WP_Error( 'kevro_login', $login['error'] ? $login['error'] : 'Login failed.' );
		}
		return ! empty( $login['cookies'] ) ? $login['cookies'] : array();
	}

	/**
	 * Generic session-scoped call that returns the decoded ResponseData rows.
	 *
	 * @param string $method  SOAP method name.
	 * @param array  $params  Method params (credentials auto-merged).
	 * @param bool   $raw     When true, return the raw envelope (ok/error/data) instead of rows.
	 * @return array|WP_Error  Array of row arrays, or raw envelope when $raw.
	 */
	private function branding_call( $method, $params, $raw = false ) {
		if ( ! $this->ready() ) {
			return new WP_Error( 'kevro_creds', 'Kevro credentials are not configured.' );
		}
		$cookies = $this->open_session();
		if ( is_wp_error( $cookies ) ) {
			return $cookies;
		}
		$c    = $this->creds();
		$base = array(
			'username' => $c['ws_user'],
			'psw'      => $c['ws_pass'],
			'entityID' => $c['entity_id'],
		);
		$r = $this->soap_call( $method, array_merge( $base, $params, array( 'ReturnType' => $c['return_type'] ) ), $cookies );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $raw ) {
			return array(
				'ok'    => ! empty( $r['ok'] ),
				'error' => isset( $r['error'] ) ? $r['error'] : '',
				'head'  => mb_substr( (string) ( isset( $r['data'] ) ? $r['data'] : '' ), 0, 12000 ),
			);
		}
		if ( empty( $r['ok'] ) ) {
			return new WP_Error( 'kevro_' . strtolower( $method ), $r['error'] ? $r['error'] : ( $method . ' returned no data.' ) );
		}
		return $this->decode_rows( (string) $r['data'] );
	}

	/**
	 * Decode a ResponseData payload (JSON or escaped XML) into an array of rows,
	 * reusing the same flattening the feed parser uses.
	 *
	 * @param string $data
	 * @return array
	 */
	private function decode_rows( $data ) {
		$data = trim( $data );
		if ( '' === $data ) {
			return array();
		}
		// Try JSON first (default ReturnType).
		$decoded = json_decode( $data, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$rows = $this->flatten_json_rows( $decoded );
			// Normalise keys to lowercase so field() lookups match.
			return array_map(
				function ( $r ) {
					return is_array( $r ) ? array_change_key_case( $r, CASE_LOWER ) : $r;
				},
				$rows
			);
		}
		// Fallback: escaped XML DataSet — pull <Table> rows generically.
		$rows = array();
		$doc  = new DOMDocument();
		libxml_use_internal_errors( true );
		if ( $doc->loadXML( $data, LIBXML_PARSEHUGE ) ) {
			$xp    = new DOMXPath( $doc );
			// Branding/features endpoints return <DocumentElement><Item>…; the
			// feed DataSet uses <Table>. Match either.
			$nodes = $xp->query( "//*[local-name()='Item']" );
			if ( ! $nodes->length ) {
				$nodes = $xp->query( "//*[local-name()='Table']" );
			}
			foreach ( $nodes as $node ) {
				$row = array();
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType ) {
						// Lowercase keys to match field()'s lowercased lookups
						// (the JSON feed path is already lowercase).
						$row[ strtolower( $child->localName ) ] = $child->textContent;
					}
				}
				if ( $row ) {
					$rows[] = $row;
				}
			}
		}
		libxml_clear_errors();
		return $rows;
	}

	/** Product Features for a StockHeaderID (Kevro's method name has a typo). */
	public function get_product_features( $header_id ) {
		return $this->branding_call( 'GetProductFetauresByStockHeaderID', array( 'StockHeaderID' => (int) $header_id ) );
	}

	/** Branding positions (Back, Left Chest, …) + their BrandingType for a header. */
	public function list_branding_positions( $header_id ) {
		return $this->branding_call( 'ListBrandingPositions', array( 'StockHeaderID' => (int) $header_id ) );
	}

	/** Branding pricing rows (type/size/colours/unit/setup) for a header. */
	public function get_branding_pricing( $header_id, $qty = 1 ) {
		return $this->branding_call( 'GetBrandingPricingByStockHeaderID', array( 'StockHeaderID' => (int) $header_id, 'Qty' => (int) $qty ) );
	}

	/** Product images for a header (categorised: Branding, Detail, …). */
	public function get_product_images( $header_id ) {
		$c   = $this->creds();
		$eid = isset( $c['entity_id'] ) ? $c['entity_id'] : '';
		return $this->branding_call( 'GetImagesByEntityIDAndStockHeaderID', array( 'EntityID' => $eid, 'StockHeaderID' => (int) $header_id ) );
	}

	/** First Branding-category guide URL for a header, or '' if none. */
	public function get_branding_guide_url( $header_id ) {
		$rows = $this->get_product_images( $header_id );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return '';
		}
		foreach ( $rows as $row ) {
			$cat = strtolower( trim( $this->field( $row, 'imagecategory', '' ) ) );
			if ( 'branding' === $cat ) {
				$url = trim( $this->field( $row, 'url', '' ) );
				if ( '' !== $url ) {
					return esc_url_raw( $url );
				}
			}
		}
		return '';
	}

	/**
	 * "Kick" a single product to (re)download its main image. Used by the
	 * admin Product Manager page. Strategy ("both / whichever recovers it"):
	 *   1. Re-resolve the main image URL from the Kevro header via SOAP and
	 *      sideload it (handles products that never had an image attempted, or
	 *      whose source image was missing at first import).
	 *   2. If that yields nothing, fall back to retrying any logged failed
	 *      images for this product id.
	 * Returns array( 'ok' => bool, 'msg' => string, 'image_id' => int ).
	 *
	 * @param int $product_id A simple product or variable parent id.
	 */
	public function kick_product_image( $product_id ) {
		$product_id = (int) $product_id;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array( 'ok' => false, 'msg' => 'Product not found.', 'image_id' => 0 );
		}
		if ( $product->get_image_id() ) {
			return array( 'ok' => true, 'msg' => 'Already has an image.', 'image_id' => (int) $product->get_image_id() );
		}

		$header_id = (int) get_post_meta( $product_id, '_kevro_header_id', true );

		// Strategy 1: re-resolve the main image URL from Kevro and sideload it.
		if ( $header_id > 0 && $this->ready() ) {
			$url = $this->main_image_url_for_header( $header_id );
			if ( '' !== $url ) {
				$id = $this->sideload( $url, $product_id, $product_id, 'main' );
				if ( $id ) {
					$product->set_image_id( $id );
					$product->save();
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients( $product_id );
					}
					return array( 'ok' => true, 'msg' => 'Image downloaded from Kevro.', 'image_id' => (int) $id );
				}
			}
		}

		// Strategy 2: retry any logged failed images belonging to this product.
		$log = get_option( self::FAILED_IMG_KEY, array() );
		$log = is_array( $log ) ? $log : array();
		foreach ( $log as $u => $info ) {
			if ( (int) ( isset( $info['product_id'] ) ? $info['product_id'] : 0 ) !== $product_id ) {
				continue;
			}
			$role = isset( $info['role'] ) ? $info['role'] : 'main';
			$id   = $this->sideload( $u, $product_id, $product_id, $role );
			if ( $id ) {
				$product->set_image_id( $id );
				$product->save();
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}
				return array( 'ok' => true, 'msg' => 'Recovered from failed-image log.', 'image_id' => (int) $id );
			}
		}

		if ( $header_id <= 0 ) {
			return array( 'ok' => false, 'msg' => 'No Kevro header id on this product.', 'image_id' => 0 );
		}
		if ( ! $this->ready() ) {
			return array( 'ok' => false, 'msg' => 'Kevro credentials not configured.', 'image_id' => 0 );
		}
		return array( 'ok' => false, 'msg' => 'No image available from Kevro for this product.', 'image_id' => 0 );
	}

	/** Resolve the best "main" image URL for a header from the Kevro image rows. */
	private function main_image_url_for_header( $header_id ) {
		$rows = $this->get_product_images( $header_id );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return '';
		}
		$fallback = '';
		foreach ( $rows as $row ) {
			$url = trim( $this->field( $row, 'url', '' ) );
			if ( '' === $url ) {
				continue;
			}
			$cat = strtolower( trim( $this->field( $row, 'imagecategory', '' ) ) );
			if ( 'branding' === $cat || 'sizing' === $cat ) {
				continue;
			}
			return esc_url_raw( $url );
		}
		foreach ( $rows as $row ) {
			$url = trim( $this->field( $row, 'url', '' ) );
			if ( '' !== $url ) {
				$fallback = esc_url_raw( $url );
				break;
			}
		}
		return $fallback;
	}

	/**
	 * True if a Kevro "feature" string is really a certification, compliance,
	 * or licensing code rather than a product feature. These get imported
	 * verbatim by the supplier (BSCI, ISO, SEDEX, WRAP, SADC, OEKO-TEX, DISNEY…)
	 * and must not appear as bullet features or in the description — showing
	 * "DISNEY" on a generic backpack is a trademark/misrepresentation risk.
	 *
	 * Matching is exact (case-insensitive) on the whole string, so a genuine
	 * feature like "ISO-certified zip pulls" is NOT stripped. Filterable.
	 */
	private function is_blocked_feature( $feature ) {
		$default = array(
			'bsci', 'iso', 'sedex', 'wrap', 'sadc', 'oeko-tex', 'oekotex', 'oeko tex',
			'disney', 'sans', 'sabs', 'ce', 'reach', 'grs', 'fsc',
		);
		$list = apply_filters( 'corpmerch_blocked_features', $default );
		$norm = strtolower( trim( (string) $feature ) );
		return in_array( $norm, array_map( 'strtolower', (array) $list ), true );
	}

	/**
	 * Compose an original prose description from the cleaned feature bullets.
	 * The Kevro API exposes no marketing copy, so this writes fresh sentences
	 * from the licensed feature/spec data (material, dimensions, construction)
	 * and keeps the full bullet list underneath for scannability. Nothing is
	 * copied from any third-party site. Returns '' if there are no features.
	 *
	 * @param string $title    Product title for the lead sentence.
	 * @param array  $features Cleaned feature strings (blocklist already applied).
	 */
	private function features_to_description( $title, $features ) {
		$features = array_values( array_filter( array_map( 'trim', (array) $features ) ) );
		if ( empty( $features ) ) {
			return '';
		}
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$name  = $title ? $title : 'product';

		// Pull out structured specs (Material: …, Size: …) so they can be
		// written into sentences rather than left as raw "key: value" bullets.
		$material = '';
		$size     = '';
		$details  = array();
		foreach ( $features as $f ) {
			if ( preg_match( '/^materials?\s*[:\-]\s*(.+)$/i', $f, $m ) ) {
				$material = rtrim( trim( $m[1] ), '.' );
			} elseif ( preg_match( '/^(?:size|dimensions?)\s*[:\-]\s*(.+)$/i', $f, $m ) ) {
				$size = rtrim( trim( $m[1] ), '.' );
			} else {
				$details[] = rtrim( $f, '.' );
			}
		}

		// Sentence 1: what it is + material.
		$lead = sprintf( 'The %s', $name );
		if ( '' !== $material ) {
			$lead .= sprintf( ' is made from %s', $this->lcfirst_keep_caps( $material ) );
		}
		$lead .= '.';

		// Sentence 2: up to three headline construction details, woven in.
		$body = '';
		if ( ! empty( $details ) ) {
			$lead_details = array_slice( $details, 0, 3 );
			$phrases      = array();
			foreach ( $lead_details as $d ) {
				$phrases[] = $this->lcfirst_keep_caps( $d );
			}
			$joined = $this->join_list( $phrases );
			$body   = sprintf( 'It features %s.', $joined );
		}

		// Sentence 3: dimensions, if present.
		$dim = '';
		if ( '' !== $size ) {
			$dim = sprintf( 'Approximate dimensions: %s.', esc_html( $size ) );
		}

		$prose = '<p>' . esc_html( $lead );
		if ( '' !== $body ) {
			$prose .= ' ' . esc_html( $body );
		}
		if ( '' !== $dim ) {
			$prose .= ' ' . $dim;
		}
		$prose .= '</p>';

		// Full feature list underneath for shoppers who scan.
		$items = '';
		foreach ( $features as $f ) {
			$items .= '<li>' . esc_html( $f ) . '</li>';
		}
		$prose .= '<p><strong>Features</strong></p><ul>' . $items . '</ul>';

		return $prose;
	}

	/** Lowercase the first letter unless the word looks like an acronym/brand. */
	private function lcfirst_keep_caps( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return $s;
		}
		$first = strtok( $s, ' ' );
		// Leave ALL-CAPS or mixed-case tokens (e.g. "1680D", "DTF") untouched.
		if ( $first === strtoupper( $first ) || preg_match( '/[A-Z].*[A-Z]/', $first ) || preg_match( '/\d/', $first ) ) {
			return $s;
		}
		return strtolower( substr( $s, 0, 1 ) ) . substr( $s, 1 );
	}

	/** Join a list into "a, b and c" (Oxford-free, SA/UK style). */
	private function join_list( $items ) {
		$items = array_values( array_filter( (array) $items ) );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' and ' . $last;
	}

	/**
	 * Fetch features + branding positions for a product's Kevro header and
	 * cache them as post meta. Called during import (and refreshable on demand).
	 * Stores nothing destructive when the API returns empty, so a transient
	 * blip won't wipe existing data.
	 *
	 * @param int $product_id WooCommerce product (parent) ID.
	 * @param int $header_id  Kevro StockHeaderID.
	 * @return array Summary counts.
	 */
	public function fetch_and_store_branding( $product_id, $header_id ) {
		$product_id = (int) $product_id;
		$header_id  = (int) $header_id;
		$summary    = array( 'features' => 0, 'positions' => 0, 'ok' => false, 'error' => '' );
		if ( $product_id <= 0 || $header_id <= 0 ) {
			$summary['error'] = 'Missing product or header id.';
			return $summary;
		}

		$got_response = false;

		// Features → flat list of strings.
		$frows = $this->get_product_features( $header_id );
		if ( is_wp_error( $frows ) ) {
			$summary['error'] = $frows->get_error_message();
		} elseif ( is_array( $frows ) ) {
			$got_response = true;
			$features = array();
			$moq      = 0;
			foreach ( $frows as $row ) {
				$f = trim( $this->field( $row, 'features', '' ) );
				if ( '' === $f ) {
					continue;
				}
				// Extract MOQ (e.g. "MOQ 25units", "MOQ: 25 units") before blocklist filtering.
				if ( ! $moq && preg_match( '/MOQ\s*:?\s*(\d+)/i', $f, $m ) ) {
					$moq = (int) $m[1];
				}
				if ( $this->is_blocked_feature( $f ) ) {
					continue;
				}
				$features[] = $f;
			}
			update_post_meta( $product_id, '_kevro_features', $features );
			if ( $moq > 1 ) {
				update_post_meta( $product_id, '_kevro_moq', $moq );
			} else {
				delete_post_meta( $product_id, '_kevro_moq' );
			}
			// Build a clean prose description from the surviving feature bullets
			// (the Kevro API has no long-description field — features are the
			// only descriptive copy available). Stored separately so display +
			// feed can use it without overwriting any manual edits.
			$generated = $this->features_to_description( get_the_title( $product_id ), $features );
			update_post_meta( $product_id, '_kevro_desc', $generated );
			// Push it into the actual WooCommerce description, but only when the
			// existing description is empty or is still the auto-generated one
			// (never clobber a description an editor has written by hand). We
			// detect "auto" via a stored hash of what we last generated.
			if ( '' !== $generated ) {
				$current = (string) get_post_field( 'post_content', $product_id );
				$last    = (string) get_post_meta( $product_id, '_kevro_desc_auto', true );
				$is_title_only = ( trim( wp_strip_all_tags( $current ) ) === trim( (string) get_the_title( $product_id ) ) );
				if ( '' === trim( $current ) || $is_title_only || $current === $last ) {
					wp_update_post( array( 'ID' => $product_id, 'post_content' => $generated ) );
					update_post_meta( $product_id, '_kevro_desc_auto', $generated );
				}
			}
			$summary['features'] = count( $features );
		}

		// Positions → map of position => [types].
		$prows = $this->list_branding_positions( $header_id );
		if ( is_wp_error( $prows ) ) {
			$summary['error'] = $prows->get_error_message();
		} elseif ( is_array( $prows ) ) {
			$got_response = true;
			$positions = array();
			foreach ( $prows as $row ) {
				$pos  = trim( $this->field( $row, 'brandingposition', '' ) );
				$type = trim( $this->field( $row, 'brandingtype', '' ) );
				if ( '' === $pos ) {
					continue;
				}
				if ( ! isset( $positions[ $pos ] ) ) {
					$positions[ $pos ] = array();
				}
				if ( '' !== $type && ! in_array( $type, $positions[ $pos ], true ) ) {
					$positions[ $pos ][] = $type;
				}
			}
			update_post_meta( $product_id, '_kevro_branding_positions', $positions );
			update_post_meta( $product_id, '_kevro_branding_enabled', $positions ? 1 : 0 );
			$summary['positions'] = count( $positions );
		}

		// Branding pricing → map of type => size => { colours:{n:price}, setup }.
		// A branding-specific markup (default 30%) is baked in at store time so the
		// front end and cart never re-derive it. Kept separate from the base
		// product markup (kevro_markup).
		$pricing_rows = $this->get_branding_pricing( $header_id, 1 );
		if ( is_array( $pricing_rows ) ) {
			$markup = (float) corpmerch_option( 'kevro_branding_markup', 30 );
			$factor = 1 + ( $markup / 100 );
			$pricing = array();
			$setup   = 0.0;
			foreach ( $pricing_rows as $row ) {
				$type = trim( $this->field( $row, 'brandingtype', '' ) );
				$size = trim( $this->field( $row, 'brandingsize', '' ) );
				$cols = (int) $this->field( $row, 'brandingcolours', 1 );
				$unit = (float) $this->field( $row, 'unitprice', 0 );
				$sfee = (float) $this->field( $row, 'setupfee', 0 );
				if ( '' === $type || '' === $size ) {
					continue;
				}
				$cols = $cols > 0 ? $cols : 1;
				if ( ! isset( $pricing[ $type ] ) ) {
					$pricing[ $type ] = array();
				}
				if ( ! isset( $pricing[ $type ][ $size ] ) ) {
					$pricing[ $type ][ $size ] = array( 'colours' => array() );
				}
				$pricing[ $type ][ $size ]['colours'][ $cols ] = round( $unit * $factor, 2 );
				if ( $sfee > $setup ) {
					$setup = $sfee;
				}
			}
			if ( $pricing ) {
				update_post_meta( $product_id, '_kevro_branding_pricing', $pricing );
				update_post_meta( $product_id, '_kevro_branding_setup', round( $setup * $factor, 2 ) );
				$summary['pricing'] = count( $pricing );
			}
		}

		// Branding guide PDF/image (placement diagram) for this garment.
		$guide = $this->get_branding_guide_url( $header_id );
		if ( '' !== $guide ) {
			update_post_meta( $product_id, '_kevro_branding_guide', $guide );
		}

		// Size-chart image. Kevro has no SOAP endpoint for these, but hosts them
		// on the blob CDN at {base}/{item_no}/{item_no}{suffix}. We HEAD-check the
		// derived URL and cache it so the front end never makes a live request.
		$this->detect_size_chart( $product_id );

		// Only treat as a successful check when at least one endpoint responded
		// without error — so transient failures retry instead of caching empty.
		$summary['ok'] = $got_response && '' === $summary['error'];
		if ( $summary['ok'] ) {
			update_post_meta( $product_id, '_kevro_branding_checked', time() );
		}
		return $summary;
	}

	/* ------------------------------------------------ branding bulk sync */


	/** Start a background branding sync over all imported products. */
	public function ajax_branding_sync() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cm_kevro', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Unauthorized.' ), 403 );
		}
		$ids = $this->imported_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'msg' => 'No imported products found.' ) );
		}
		$ids   = array_map( 'intval', $ids );
		$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification
		update_option( self::BRAND_QUEUE, $ids, false );
		update_option(
			self::BRAND_STATE,
			array(
				'status'    => 'running',
				'total'     => count( $ids ),
				'done'      => 0,
				'branded'   => 0,
				'features'  => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'force'     => $force ? 1 : 0,
				'started'   => time(),
				'message'   => 'Starting…',
			),
			false
		);
		if ( ! wp_next_scheduled( self::BRAND_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::BRAND_HOOK );
		}
		spawn_cron();
		wp_send_json_success( array( 'total' => count( $ids ) ) );
	}

	/** Process a batch of products for branding/features. */
	public function branding_batch() {
		$queue = get_option( self::BRAND_QUEUE, array() );
		$state = get_option( self::BRAND_STATE, array() );
		if ( empty( $queue ) || empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return;
		}
		$this->raise_limits();
		$batch = array_splice( $queue, 0, 15 ); // 15 products/batch (3 API calls each).
		$force = ! empty( $state['force'] );
		$fresh = 30 * DAY_IN_SECONDS; // skip products checked within this window.
		foreach ( $batch as $pid ) {
			$header = (int) get_post_meta( $pid, '_kevro_header_id', true );
			if ( $header <= 0 ) {
				$state['done']++;
				continue;
			}
			if ( ! $force ) {
				$checked = (int) get_post_meta( $pid, '_kevro_branding_checked', true );
				if ( $checked > 0 && ( time() - $checked ) < $fresh ) {
					// Branding is fresh — skip the costly 3-call fetch, but still
					// refresh the size-chart (one cheap HEAD request) so size
					// guides populate without forcing a full re-sync.
					$this->detect_size_chart( $pid );
					$state['done']++;
					$state['skipped'] = isset( $state['skipped'] ) ? $state['skipped'] + 1 : 1;
					continue;
				}
			}
			$r = $this->fetch_and_store_branding( $pid, $header );
			$state['done']++;
			if ( ! empty( $r['ok'] ) ) {
				if ( $r['positions'] > 0 ) {
					$state['branded']++;
				}
				if ( $r['features'] > 0 ) {
					$state['features']++;
				}
			} else {
				$state['errors']++;
			}
		}
		update_option( self::BRAND_QUEUE, $queue, false );
		if ( empty( $queue ) ) {
			$state['status']  = 'done';
			$state['message'] = sprintf( 'Done. %d branded, %d with features, %d skipped (fresh), %d errors.', $state['branded'], $state['features'], isset( $state['skipped'] ) ? (int) $state['skipped'] : 0, $state['errors'] );
		} else {
			$state['message'] = sprintf( '%d / %d processed…', $state['done'], $state['total'] );
			if ( ! wp_next_scheduled( self::BRAND_HOOK ) ) {
				wp_schedule_single_event( time() + 1, self::BRAND_HOOK );
			}
			spawn_cron();
		}
		update_option( self::BRAND_STATE, $state, false );
	}

	/** Poll branding sync progress. */
	public function ajax_branding_sync_status() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cm_kevro', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Unauthorized.' ), 403 );
		}
		$state = get_option( self::BRAND_STATE, array() );
		if ( empty( $state ) ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
		}
		wp_send_json_success( $state );
	}

	/**
	 * Admin inspector: dump field names + sample values from each branding
	 * endpoint for a given StockHeaderID, so we can map the real schema.
	 */
	public function ajax_branding_inspect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cm_kevro', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Unauthorized.' ), 403 );
		}
		$header_id = isset( $_POST['header_id'] ) ? (int) $_POST['header_id'] : 0;
		if ( $header_id <= 0 ) {
			wp_send_json_error( array( 'msg' => 'Provide a StockHeaderID.' ) );
		}

		// Probe each candidate method with a couple of param-name spellings.
		// We report the raw envelope so we can see ok/error/payload head and
		// learn which method + param Kevro actually accepts.
		$probes = array(
			'features' => array(
				'GetProductFetauresByStockHeaderID' => array( 'StockHeaderID', 'StockHeaderId' ),
				'GetProductFeaturesByStockHeaderID' => array( 'StockHeaderID', 'StockHeaderId' ),
				'GetProductFeatures'                => array( 'StockHeaderID', 'StockHeaderId' ),
			),
			'positions' => array(
				'ListBrandingPositions' => array( 'StockHeaderID', 'StockHeaderId', 'stockHeaderID' ),
			),
			'options' => array(
				'ListBrandingOptions' => array( 'StockHeaderID', 'StockHeaderId', 'stockHeaderID' ),
				'ListAllBrandingOptions' => array( 'StockHeaderID' ),
			),
			'pricing' => array(
				'GetBrandingPricingByStockHeaderIDAndQty' => array( 'StockHeaderID', 'StockHeaderId' ),
				'GetBrandingPricingByStockHeaderID'       => array( 'StockHeaderID', 'StockHeaderId' ),
			),
		);

		// Image endpoints need EntityID + StockHeaderID; probe separately so we
		// can see how many views/angles Kevro returns per product (front/back…).
		$c   = $this->creds();
		$eid = isset( $c['entity_id'] ) ? $c['entity_id'] : '';
		$image_probes = array(
			'GetImagesByEntityIDAndStockHeaderID' => array( 'EntityID' => $eid, 'StockHeaderID' => $header_id ),
			'GetCategoryImages'                   => array( 'EntityID' => $eid, 'StockHeaderID' => $header_id ),
		);

		$out = array();
		foreach ( $probes as $label => $methods ) {
			$results = array();
			foreach ( $methods as $method => $param_names ) {
				foreach ( $param_names as $pname ) {
					$params = array( $pname => $header_id );
					if ( 'pricing' === $label ) {
						$params['Qty'] = 1;
					}
					$raw = $this->branding_call( $method, $params, true );
					if ( is_wp_error( $raw ) ) {
						$results[] = array( 'method' => $method, 'param' => $pname, 'wp_error' => $raw->get_error_message() );
						continue;
					}
					$results[] = array(
						'method' => $method,
						'param'  => $pname,
						'ok'     => $raw['ok'],
						'error'  => $raw['error'],
						'head'   => $raw['head'],
					);
				}
			}
			$out[ $label ] = $results;
		}

		// Image endpoints (full dump so we can see ALL categories incl. any size chart).
		$img_out = array();
		foreach ( $image_probes as $method => $params ) {
			$raw = $this->branding_call( $method, $params, true );
			if ( is_wp_error( $raw ) ) {
				$img_out[] = array( 'method' => $method, 'wp_error' => $raw->get_error_message() );
				continue;
			}
			// Decode rows and list distinct categories + their URLs, rather than
			// a truncated head, so a Size/Sizing category can't be cut off.
			$rows = $this->decode_rows( (string) $raw['head'] );
			$cats = array();
			foreach ( (array) $rows as $r ) {
				$cat = trim( $this->field( $r, 'imagecategory', $this->field( $r, 'categorytype', '' ) ) );
				$url = trim( $this->field( $r, 'url', $this->field( $r, 'categorytypeimage', '' ) ) );
				$cats[] = array( 'category' => $cat, 'url' => $url );
			}
			$img_out[] = array(
				'method'     => $method,
				'ok'         => $raw['ok'],
				'error'      => $raw['error'],
				'row_count'  => is_array( $rows ) ? count( $rows ) : 0,
				'categories' => $cats,
			);
		}
		$out['images'] = $img_out;

		wp_send_json_success( $out );
	}

	/* ------------------------------------------------------- feed parsing */

	private function parse_feed( $data ) {
		$data = trim( (string) $data );
		if ( '' === $data ) {
			return array();
		}
		// JSON return type (default). Handles bare arrays, {Table:[...]},
		// and DataSet-style {NewDataSet:{Table:[...]}} by finding the first
		// numerically-indexed list of row objects at any depth.
		if ( '[' === $data[0] || '{' === $data[0] ) {
			$j = json_decode( $data, true );
			if ( is_array( $j ) ) {
				$found = $this->flatten_json_rows( $j );
				$rows  = array();
				foreach ( $found as $r ) {
					if ( is_array( $r ) ) {
						$rows[] = array_change_key_case( $r, CASE_LOWER );
					}
				}
				return $rows;
			}
		}
		// XML return type. PARSEHUGE: feed can exceed the 10MB-per-node limit.
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $data, LIBXML_PARSEHUGE );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array();
		}
		$xp = new DOMXPath( $doc );
		// Any element that has a child element named StockCode (any case) = one row.
		$nodes = $xp->query( "//*[*[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='stockcode']]" );
		$rows  = array();
		foreach ( $nodes as $node ) {
			$row = array();
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					$row[ strtolower( $child->localName ) ] = trim( $child->textContent );
				}
			}
			if ( ! empty( $row['stockcode'] ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** Find the first numerically-indexed list of Kevro row objects at any depth. */
	private function flatten_json_rows( $decoded ) {
		if ( $this->is_row_array( $decoded ) ) {
			return $decoded;
		}
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $value ) {
				if ( $this->is_row_array( $value ) ) {
					return $value;
				}
				if ( is_array( $value ) ) {
					$inner = $this->flatten_json_rows( $value );
					if ( is_array( $inner ) && ! empty( $inner ) ) {
						return $inner;
					}
				}
			}
		}
		return array();
	}

	/** A numerically-indexed list whose first element is a Kevro row (case-insensitive markers). */
	private function is_row_array( $v ) {
		if ( ! is_array( $v ) || empty( $v ) ) {
			return false;
		}
		$keys = array_keys( $v );
		if ( $keys !== range( 0, count( $keys ) - 1 ) ) {
			return false;
		}
		$first = $v[0];
		if ( ! is_array( $first ) || empty( $first ) ) {
			return false;
		}
		$lower = array_change_key_case( $first, CASE_LOWER );
		foreach ( array( 'stockid', 'stockcode', 'stockheaderid', 'description' ) as $marker ) {
			if ( array_key_exists( $marker, $lower ) ) {
				return true;
			}
		}
		return false;
	}

	private function field( $row, $key, $default = '' ) {
		$key = strtolower( $key );
		return isset( $row[ $key ] ) && '' !== $row[ $key ] ? $row[ $key ] : $default;
	}

	private function row_qty( $row ) {
		$custom = strtolower( trim( (string) corpmerch_option( 'kevro_qty_field', '' ) ) );
		$names  = $custom ? array_merge( array( $custom ), self::QTY_CANDIDATES ) : self::QTY_CANDIDATES;
		foreach ( $names as $n ) {
			if ( isset( $row[ $n ] ) && '' !== $row[ $n ] ) {
				return (int) round( (float) $row[ $n ] );
			}
		}
		return null;
	}

	/** Group rows by StockHeaderID (fallback: StockCode = its own product). */
	private function group( $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$hid = $this->field( $row, 'stockheaderid', $this->field( $row, 'stockcode' ) );
			if ( '' === $hid ) {
				continue;
			}
			$groups[ $hid ][] = $row;
		}
		return $groups;
	}

	/* ----------------------------------------------------------- importing */

	private function price( $raw ) {
		$markup = (float) corpmerch_option( 'kevro_markup', 0 );
		$val    = (float) $raw;
		if ( $markup ) {
			$val = $val * ( 1 + $markup / 100 );
		}
		return $val > 0 ? wc_format_decimal( $val, wc_get_price_decimals() ) : '';
	}

	private function find_existing( $header_id ) {
		$q = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_kevro_header_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (string) $header_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $q ? (int) $q[0] : 0;
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

	private function assign_category( $product_id, $row ) {
		$cat  = $this->field( $row, 'category' ); // Feed Category label.
		$type = $this->field( $row, 'type' );     // Feed Type label.

		// Keep the raw feed labels on the product for later (re)allocation.
		update_post_meta( $product_id, '_kevro_category', $cat );
		update_post_meta( $product_id, '_kevro_type', $type );
		update_post_meta( $product_id, '_cm_feed_cat', ( '' !== trim( (string) $type ) ) ? $type : $cat );
		update_post_meta( $product_id, '_cm_feed_src', 'kevro' );
		update_post_meta( $product_id, 'cm_feed_cat', ( '' !== trim( (string) $type ) ) ? $type : $cat );
		update_post_meta( $product_id, 'cm_feed_src', 'kevro' );

		// Book6 is the ONLY taxonomy: never create a term from a feed label.
		// Route through the saved feed→Book6 map; unmapped products go to the
		// hidden "Unmapped" holding term so nothing is lost.
		$assign = 0;
		if ( class_exists( 'Corpmerch_Cat_Map' ) ) {
			Corpmerch_Cat_Map::record( $cat );
			Corpmerch_Cat_Map::record( $type );
			$assign = Corpmerch_Cat_Map::resolve( $cat, $type );
			if ( ! $assign ) {
				$assign = Corpmerch_Cat_Map::unmapped_term_id();
			}
		}
		if ( $assign ) {
			wp_set_object_terms( $product_id, array( (int) $assign ), 'product_cat', false );
		}
	}

	/** Drop rows by ColorStatus blocklist and (optionally) zero quantity. */
	private function filter_rows( $rows ) {
		$raw       = strtolower( (string) corpmerch_option( 'kevro_colorstatus_blocklist', '' ) );
		$block     = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$skip_zero = corpmerch_bool( 'kevro_skip_zero_qty', false );
		if ( empty( $block ) && ! $skip_zero ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( $block ) {
				$cs = strtolower( $this->field( $r, 'colorstatus' ) );
				if ( '' !== $cs && in_array( $cs, $block, true ) ) {
					continue;
				}
			}
			if ( $skip_zero ) {
				$q = $this->row_qty( $r );
				if ( null !== $q && $q <= 0 ) {
					continue;
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	private function assign_brand( $product_id, $row ) {
		$brand = $this->field( $row, 'brand' );
		if ( '' === $brand ) {
			return;
		}
		update_post_meta( $product_id, '_kevro_brand', $brand );
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

	/**
	 * Out-of-stock → draft, with auto re-publish on restock.
	 *
	 * When kevro_oos_draft is enabled, a fully out-of-stock product is forced to
	 * 'draft'. When it has stock again it returns to the configured kevro_status
	 * ('publish' or 'draft'). Products gone from the feed entirely are handled
	 * separately by prune() (trash), not here.
	 *
	 * For variable products "out of stock" means EVERY variation is out of stock.
	 * Called after stock has been written, so it reads the product's live state.
	 *
	 * @param int $product_id Parent/simple product id.
	 */
	private function reconcile_stock_status( $product_id ) {
		if ( ! corpmerch_bool( 'kevro_oos_draft', true ) ) {
			return;
		}
		if ( ! corpmerch_bool( 'kevro_manage_stock', true ) ) {
			return; // stock isn't authoritative — don't touch status.
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$in_stock = $this->product_has_stock( $product );
		$current  = $product->get_status();
		$desired  = corpmerch_option( 'kevro_status', 'draft' );
		$desired  = in_array( $desired, array( 'draft', 'publish' ), true ) ? $desired : 'draft';

		if ( ! $in_stock && 'draft' !== $current ) {
			wp_update_post( array( 'ID' => $product_id, 'post_status' => 'draft' ) );
		} elseif ( $in_stock && 'draft' === $current && 'publish' === $desired ) {
			// Restocked: return to the configured live status.
			wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish' ) );
		}
	}

	/** True if a product (or any of its variations) is in stock. */
	private function product_has_stock( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && $v->is_in_stock() ) {
					return true;
				}
			}
			return false;
		}
		return $product->is_in_stock();
	}

	/**
	 * Download an image once; dedupe by source URL meta. Returns attachment ID.
	 *
	 * On a genuine download failure (WP_Error) the URL is logged to
	 * FAILED_IMG_KEY together with enough context ($product_id + $role) to
	 * reassign it on a later retry, and the per-run failure counter is bumped.
	 * An empty URL is a no-op and is NOT counted as a failure.
	 *
	 * @param string $url        Source image URL.
	 * @param int    $parent     Attachment parent (product/variation id) for media library.
	 * @param int    $product_id Product/variation the image should be assigned to (for retry).
	 * @param string $role       'main' (featured image) or 'variation'.
	 */
	private function sideload( $url, $parent = 0, $product_id = 0, $role = 'main' ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! corpmerch_bool( 'kevro_import_images', true ) ) {
			return 0;
		}
		$this->bump_img( 'expected' );
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_kevro_src', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $existing ) {
			$this->bump_img( 'ok' );
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, $parent, null, 'id' );
		if ( is_wp_error( $id ) ) {
			$this->log_failed_image( $url, (int) $product_id, $role, $id->get_error_message() );
			$this->bump_img( 'failed' );
			return 0;
		}
		update_post_meta( (int) $id, '_kevro_src', $url );
		$this->clear_failed_image( $url );
		$this->bump_img( 'ok' );
		return (int) $id;
	}

	/** Increment a per-run image counter (expected|ok|failed) on the run state. */
	private function bump_img( $which ) {
		$s   = $this->state();
		$key = 'img_' . $which;
		$s[ $key ] = ( isset( $s[ $key ] ) ? (int) $s[ $key ] : 0 ) + 1;
		$this->save_state( $s );
	}

	/** Record a failed image download keyed by URL (latest context wins). */
	private function log_failed_image( $url, $product_id, $role, $error = '' ) {
		$log         = get_option( self::FAILED_IMG_KEY, array() );
		$log         = is_array( $log ) ? $log : array();
		$log[ $url ] = array(
			'url'        => $url,
			'product_id' => (int) $product_id,
			'role'       => ( 'variation' === $role ? 'variation' : 'main' ),
			'error'      => (string) $error,
			'time'       => time(),
		);
		update_option( self::FAILED_IMG_KEY, $log, false );
	}

	/** Drop a URL from the failed-image log once it has downloaded successfully. */
	private function clear_failed_image( $url ) {
		$log = get_option( self::FAILED_IMG_KEY, array() );
		if ( is_array( $log ) && isset( $log[ $url ] ) ) {
			unset( $log[ $url ] );
			update_option( self::FAILED_IMG_KEY, $log, false );
		}
	}

	/**
	 * Re-attempt every logged failed image and reassign it to its product or
	 * variation. Successful ones drop out of the log. Returns a result summary.
	 * Used by both the manual "Retry failed images" button and the automatic
	 * pre-sync retry pass.
	 */
	public function retry_failed_images() {
		$log = get_option( self::FAILED_IMG_KEY, array() );
		$log = is_array( $log ) ? $log : array();
		$attempted = count( $log );
		$recovered = 0;
		foreach ( $log as $url => $info ) {
			$pid  = isset( $info['product_id'] ) ? (int) $info['product_id'] : 0;
			$role = isset( $info['role'] ) ? $info['role'] : 'main';
			// sideload() clears the log entry + bumps counters on success.
			$id = $this->sideload( $url, $pid, $pid, $role );
			if ( ! $id ) {
				continue; // still failing — stays logged.
			}
			if ( $pid && ( $obj = wc_get_product( $pid ) ) ) {
				$obj->set_image_id( $id );
				$obj->save();
				if ( $obj->is_type( 'variation' ) && function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $obj->get_parent_id() );
				}
			}
			$recovered++;
		}
		return array(
			'attempted' => $attempted,
			'recovered' => $recovered,
			'remaining' => count( get_option( self::FAILED_IMG_KEY, array() ) ),
		);
	}

	/** AJAX: manual "Retry failed images" button. */
	public function ajax_retry_images() {
		$this->guard();
		wp_send_json_success( $this->retry_failed_images() );
	}

	/**
	 * Derive the Kevro "item number" from a product image URL.
	 *
	 * Kevro hosts images at .../product-images/{ITEM_NO}/{ITEM_NO}-{colour}.png
	 * The item number is the folder segment (e.g. BOT-01-NE, TST-02-PU). It is
	 * NOT the stockcode and cannot be derived from the SKU — the image URL is
	 * the only reliable source. Size charts live in the same folder as
	 * {ITEM_NO}-sizing.jpg, so we persist the item number for later lookup.
	 *
	 * @param string $image_url Source image URL from the feed `image` field.
	 * @return string Item number, or '' when it cannot be parsed.
	 */
	private function item_no_from_image( $image_url ) {
		$image_url = trim( (string) $image_url );
		if ( '' === $image_url || false === strpos( $image_url, 'product-images/' ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}
		// Grab the segment immediately after /product-images/.
		if ( preg_match( '#/product-images/([^/]+)/#i', $path, $m ) ) {
			return trim( rawurldecode( $m[1] ) );
		}
		return '';
	}

	/** Store the derived item number on a product (no-op when empty/unchanged). */
	private function store_item_no( $product_id, $first ) {
		$item_no = $this->item_no_from_image( $this->field( $first, 'image' ) );
		if ( '' !== $item_no ) {
			update_post_meta( (int) $product_id, '_kevro_item_no', $item_no );
		}
	}

	/**
	 * Detect & cache a size-chart image URL for a product.
	 *
	 * Builds {base}/{item_no}/{item_no}{suffix} from the stored item number and
	 * HEAD-checks it. On 200 the URL is cached in `_kevro_sizing_img`; otherwise
	 * the meta is cleared. Tries the configured suffix plus a couple of common
	 * variants, and a lowercased item-number folder, to tolerate casing/naming
	 * drift on the CDN. Safe to call repeatedly (idempotent).
	 *
	 * @param int $product_id Product post ID.
	 * @return string The detected URL, or '' when none found.
	 */
	public function detect_size_chart( $product_id ) {
		$product_id = (int) $product_id;
		$item_no    = trim( (string) get_post_meta( $product_id, '_kevro_item_no', true ) );
		if ( '' === $item_no ) {
			return '';
		}

		$base = untrailingslashit( corpmerch_option( 'sizing_base_url', 'https://paznsaapp02.blob.core.windows.net/product-images' ) );
		$raw  = (string) corpmerch_option( 'sizing_suffixes', '-sizing.jpg, -sizing.png' );
		$suffixes = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( empty( $suffixes ) ) {
			$suffixes = array( '-sizing.jpg', '-sizing.png' );
		}
		// Always probe both common image extensions: the CDN mixes .jpg and .png
		// for sizing charts, so add the alternate extension for each suffix.
		$expanded = array();
		foreach ( $suffixes as $sfx ) {
			$expanded[] = $sfx;
			if ( preg_match( '/\.jpe?g$/i', $sfx ) ) {
				$expanded[] = preg_replace( '/\.jpe?g$/i', '.png', $sfx );
			} elseif ( preg_match( '/\.png$/i', $sfx ) ) {
				$expanded[] = preg_replace( '/\.png$/i', '.jpg', $sfx );
			}
		}
		$suffixes = array_values( array_unique( $expanded ) );

		// Candidate folder spellings: exact, then lowercase as a fallback.
		$folders = array( $item_no );
		if ( strtolower( $item_no ) !== $item_no ) {
			$folders[] = strtolower( $item_no );
		}

		foreach ( $folders as $folder ) {
			foreach ( $suffixes as $suffix ) {
				// Dashes are part of the item number, so do not URL-encode them.
				$url = $base . '/' . $folder . '/' . $folder . $suffix;
				if ( $this->url_exists( $url ) ) {
					update_post_meta( $product_id, '_kevro_sizing_img', esc_url_raw( $url ) );
					return $url;
				}
			}
		}

		delete_post_meta( $product_id, '_kevro_sizing_img' );
		return '';
	}

	/** Lightweight existence check via HEAD (falls back to a ranged GET). */
	private function url_exists( $url ) {
		$args = array(
			'timeout'     => 8,
			'redirection' => 2,
			'sslverify'   => true,
		);
		$res = wp_remote_head( $url, $args );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return true;
		}
		// Some CDNs reject HEAD; confirm with a 1-byte ranged GET.
		if ( 405 === $code || 403 === $code || 0 === $code ) {
			$res = wp_remote_get( $url, $args + array( 'headers' => array( 'Range' => 'bytes=0-0' ) ) );
			if ( is_wp_error( $res ) ) {
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			return ( 200 === $code || 206 === $code );
		}
		return false;
	}

	/** Upsert one product (variable when >1 row, else simple). Returns post ID or 0. */
	public function import_header( $header_id, $rows ) {
		if ( ! class_exists( 'WC_Product_Variable' ) || empty( $rows ) ) {
			return 0;
		}
		$rows = array_values( $this->filter_rows( $rows ) );
		if ( empty( $rows ) ) {
			return array( 'id' => 0, 'mode' => 'skipped', 'name' => '' );
		}
		$existing = $this->find_existing( $header_id );
		$sig      = $this->signature( $rows );
		$manage   = corpmerch_bool( 'kevro_manage_stock', true );

		// Fast path: already synced and nothing structural changed — refresh
		// price + stock only (no image download, no term/attribute rebuild).
		if ( $existing && (string) get_post_meta( $existing, '_kevro_sig', true ) === $sig ) {
			$this->fast_update( $existing, $rows, $manage );
			return array( 'id' => $existing, 'mode' => 'updated', 'name' => get_the_title( $existing ) );
		}

		$first  = $rows[0];
		$status = corpmerch_option( 'kevro_status', 'draft' );
		$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
		$name   = $this->field( $first, 'description', 'Kevro ' . $header_id );
		$desc   = $this->field( $first, 'description', '' );

		$colours = array();
		$sizes   = array();
		foreach ( $rows as $r ) {
			$cv = $this->field( $r, 'colour' );
			$sv = $this->field( $r, 'size' );
			if ( '' !== $cv ) {
				$colours[ $cv ] = $cv;
			}
			if ( '' !== $sv ) {
				$sizes[ $sv ] = $sv;
			}
		}
		$is_variable = count( $rows ) > 1 && ( count( $colours ) > 0 || count( $sizes ) > 0 );

		if ( $is_variable ) {
			$product = $existing ? wc_get_product( $existing ) : null;
			if ( ! $product instanceof WC_Product_Variable ) {
				$product = new WC_Product_Variable();
			}
			$product->set_name( $name );
			$product->set_status( $status );
			$product->set_description( $desc );

			$attributes = array();
			if ( $colours ) {
				$attributes[] = $this->custom_attribute( 'Colour', array_values( $colours ) );
			}
			if ( $sizes ) {
				$attributes[] = $this->custom_attribute( 'Size', array_values( $sizes ) );
			}
			$product->set_attributes( $attributes );
			$psku = $this->unique_sku( $this->field( $first, 'stockcode' ), $existing, $header_id );
			if ( '' !== $psku ) {
				$product->set_sku( $psku );
			}
			$pid = $product->save();
			update_post_meta( $pid, '_kevro_header_id', (string) $header_id );
			$main = $this->sideload( $this->field( $first, 'image' ), $pid, $pid, 'main' );
			if ( $main ) {
				$product->set_image_id( $main );
				$product->save();
			}
			$this->store_item_no( $pid, $first );
			$this->assign_category( $pid, $first );
			$this->assign_brand( $pid, $first );

			foreach ( $rows as $r ) {
				$this->upsert_variation( $pid, $r, $manage );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
			$this->reconcile_stock_status( $pid );
			update_post_meta( $pid, '_kevro_sig', $sig );
			return array( 'id' => $pid, 'mode' => $existing ? 'refreshed' : 'created', 'name' => $name );
		}

		// Simple product.
		$product = $existing ? wc_get_product( $existing ) : null;
		if ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple();
		}
		$product->set_name( $name );
		$product->set_status( $status );
		$product->set_description( $desc );
		$ssku = $this->unique_sku( $this->field( $first, 'stockcode' ), $existing, $header_id );
		if ( '' !== $ssku ) {
			$product->set_sku( $ssku );
		}

		$reg  = $this->price( $this->field( $first, 'baseprice' ) );
		$disc = $this->price( $this->field( $first, 'discountbaseprice' ) );
		if ( '' !== $reg ) {
			$product->set_regular_price( $reg );
		}
		$product->set_sale_price( ( '' !== $disc && (float) $disc > 0 && (float) $disc < (float) $reg ) ? $disc : '' );

		$qty = $this->row_qty( $first );
		if ( $manage && null !== $qty ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $qty );
			$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		}
		$pid = $product->save();
		$main = $this->sideload( $this->field( $first, 'image' ), $pid, $pid, 'main' );
		if ( $main ) {
			$product->set_image_id( $main );
			$product->save();
		}
		update_post_meta( $pid, '_kevro_header_id', (string) $header_id );
		update_post_meta( $pid, '_kevro_stock_code', $this->field( $first, 'stockcode' ) );
		$this->store_item_no( $pid, $first );
		$gender = $this->field( $first, 'gender' );
		if ( '' !== $gender ) {
			update_post_meta( $pid, '_kevro_gender', $gender );
		}
		$this->assign_category( $pid, $first );
		$this->assign_brand( $pid, $first );
		$this->reconcile_stock_status( $pid );
		update_post_meta( $pid, '_kevro_sig', $sig );
		return array( 'id' => $pid, 'mode' => $existing ? 'refreshed' : 'created', 'name' => $name );
	}

	/** Structural fingerprint (everything except price + stock). */
	private function signature( $rows ) {
		$first = $rows[0];
		$parts = array(
			$this->field( $first, 'description' ),
			$this->field( $first, 'category' ),
			$this->field( $first, 'type' ),
			$this->field( $first, 'brand' ),
			$this->field( $first, 'stockcode' ),
			(string) corpmerch_bool( 'kevro_import_images', true ),
			(string) corpmerch_option( 'kevro_status', 'draft' ),
		);
		foreach ( $rows as $r ) {
			$parts[] = $this->field( $r, 'stockid' ) . '|' . $this->field( $r, 'colour' ) . '|' . $this->field( $r, 'size' ) . '|' . $this->field( $r, 'image' );
		}
		return md5( implode( "\n", $parts ) );
	}

	/** Lightweight re-sync: update price + stock only on an existing product. */
	private function fast_update( $product_id, $rows, $manage ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			$by_sid = array();
			foreach ( $rows as $r ) {
				$by_sid[ (string) $this->field( $r, 'stockid' ) ] = $r;
			}
			foreach ( $product->get_children() as $vid ) {
				$sid = (string) get_post_meta( $vid, '_kevro_stock_id', true );
				if ( '' !== $sid && isset( $by_sid[ $sid ] ) ) {
					$this->apply_price_stock( wc_get_product( $vid ), $by_sid[ $sid ], $manage );
				}
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			$this->reconcile_stock_status( $product_id );
			return;
		}
		$this->apply_price_stock( $product, $rows[0], $manage );
		$this->reconcile_stock_status( $product_id );
	}

	/** Set regular/sale price + managed stock on a product or variation, then save. */
	private function apply_price_stock( $obj, $row, $manage ) {
		if ( ! $obj ) {
			return;
		}
		$reg  = $this->price( $this->field( $row, 'baseprice' ) );
		$disc = $this->price( $this->field( $row, 'discountbaseprice' ) );
		if ( '' !== $reg ) {
			$obj->set_regular_price( $reg );
		}
		$obj->set_sale_price( ( '' !== $disc && (float) $disc > 0 && (float) $disc < (float) $reg ) ? $disc : '' );
		$qty = $this->row_qty( $row );
		if ( $manage && null !== $qty ) {
			$obj->set_manage_stock( true );
			$obj->set_stock_quantity( $qty );
			$obj->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		}
		$obj->save();
	}

	private function custom_attribute( $label, $options ) {
		$attr = new WC_Product_Attribute();
		$attr->set_id( 0 );
		$attr->set_name( $label );
		$attr->set_options( $options );
		$attr->set_visible( true );
		$attr->set_variation( true );
		return $attr;
	}

	/** Find an existing variation under a parent by its Kevro StockID. */
	private function find_variation( $parent_id, $stock_id ) {
		if ( '' === (string) $stock_id ) {
			return 0;
		}
		$q = get_posts(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'post_parent' => (int) $parent_id,
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_kevro_stock_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (string) $stock_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	private function upsert_variation( $parent_id, $row, $manage ) {
		$sku = $this->field( $row, 'stockcode' );
		$sid = $this->field( $row, 'stockid' );
		// Variations share the parent's stockcode and differ only by colour+size,
		// so identity is the unique StockID — never the SKU (WC forbids dupe SKUs).
		$vid = $this->find_variation( $parent_id, $sid );
		$var = $vid ? wc_get_product( $vid ) : null;
		if ( ! $var instanceof WC_Product_Variation ) {
			$var = new WC_Product_Variation();
		}
		$var->set_parent_id( $parent_id );

		$attrs = array();
		if ( '' !== $this->field( $row, 'colour' ) ) {
			$attrs['colour'] = $this->field( $row, 'colour' );
		}
		if ( '' !== $this->field( $row, 'size' ) ) {
			$attrs['size'] = $this->field( $row, 'size' );
		}
		$var->set_attributes( $attrs );
		// No SKU on variations: the parent carries the stockcode.

		$reg  = $this->price( $this->field( $row, 'baseprice' ) );
		$disc = $this->price( $this->field( $row, 'discountbaseprice' ) );
		if ( '' !== $reg ) {
			$var->set_regular_price( $reg );
		}
		$var->set_sale_price( ( '' !== $disc && (float) $disc > 0 && (float) $disc < (float) $reg ) ? $disc : '' );

		$qty = $this->row_qty( $row );
		if ( $manage && null !== $qty ) {
			$var->set_manage_stock( true );
			$var->set_stock_quantity( $qty );
			$var->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		}
		$vid = $var->save();
		if ( $vid ) {
			update_post_meta( $vid, '_kevro_stock_code', $sku );
			update_post_meta( $vid, '_kevro_stock_id', $sid );
			$cimg = $this->sideload( $this->field( $row, 'image' ), $parent_id, $vid, 'variation' );
			if ( $cimg ) {
				$var->set_image_id( $cimg );
				$var->save();
			}
		}
		return $vid;
	}

	/**
	 * Return a SKU that is guaranteed unique across the catalog.
	 *
	 * Kevro reuses the same stockcode across multiple StockHeaderIDs, so the
	 * raw stockcode collides and WooCommerce rejects the save ("Invalid or
	 * duplicated SKU"). When the preferred SKU is already owned by a *different*
	 * product, we suffix the Kevro header id (stable + unique per product), and
	 * keep suffixing a counter in the unlikely event that still collides. Never
	 * returns a value that another product owns.
	 *
	 * @param string $sku         Preferred SKU (the stockcode).
	 * @param int    $existing_id The product being saved (0 for new).
	 * @param int    $header_id   Kevro StockHeaderID, used to build a unique suffix.
	 */
	private function unique_sku( $sku, $existing_id, $header_id = 0 ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return $header_id ? 'KEV-' . (int) $header_id : '';
		}
		$existing_id = (int) $existing_id;
		$owner = (int) wc_get_product_id_by_sku( $sku );
		if ( ! $owner || $owner === $existing_id ) {
			return $sku; // free, or already ours.
		}
		// Collision with another product: build a unique variant.
		$base = $header_id ? $sku . '-' . (int) $header_id : $sku;
		$try  = $base;
		$i    = 1;
		while ( true ) {
			$o = (int) wc_get_product_id_by_sku( $try );
			if ( ! $o || $o === $existing_id ) {
				return $try;
			}
			$try = $base . '-' . $i;
			$i++;
			if ( $i > 50 ) {
				return $base . '-' . uniqid(); // hard fallback, still unique.
			}
		}
	}

	/** All Kevro-imported product IDs. */
	private function imported_ids() {
		return get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_kevro_header_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/** Remove imported products whose header IDs are absent from the latest feed. */
	private function prune( $seen_header_ids ) {
		if ( ! corpmerch_bool( 'kevro_prune', false ) ) {
			return 0;
		}
		$action = corpmerch_option( 'kevro_prune_action', 'trash' );
		$seen   = array_map( 'strval', (array) $seen_header_ids );
		$n      = 0;
		foreach ( $this->imported_ids() as $pid ) {
			$h = (string) get_post_meta( $pid, '_kevro_header_id', true );
			if ( '' === $h || in_array( $h, $seen, true ) ) {
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

	public function ajax_purge() {
		$this->guard();
		$ids = $this->imported_ids();
		foreach ( $ids as $pid ) {
			wp_trash_post( $pid );
		}
		wp_send_json_success( array( 'count' => count( $ids ) ) );
	}

	/* --------------------------------------------------------------- runner */

	/* --------------------------------------------------------------- runner */

	/** Read run-state with defaults. */
	private function state() {
		$d = array(
			'status' => 'idle', 'total' => 0, 'offset' => 0,
			'created' => 0, 'updated' => 0, 'refreshed' => 0, 'pruned' => 0, 'errors' => 0,
			'img_expected' => 0, 'img_ok' => 0, 'img_failed' => 0,
			'started' => 0, 'heartbeat' => 0, 'message' => '', 'recent' => array(), 'error_log' => array(),
		);
		$s = get_option( self::STATE_KEY, array() );
		return is_array( $s ) ? array_merge( $d, $s ) : $d;
	}

	private function save_state( $s ) {
		update_option( self::STATE_KEY, $s, false );
	}

	/** Fetch + group the feed and queue a background run. Returns total or WP_Error. */
	private function start_run() {
		if ( corpmerch_sync_paused() ) {
			return new WP_Error( 'cm_kevro', 'All feed syncs are paused. Resume them on the Product feeds page first.' );
		}
		if ( ! $this->ready() ) {
			return new WP_Error( 'cm_kevro', 'Enter Entity ID, web-service username and password first.' );
		}
		$this->raise_limits();
		$data = $this->get_feed();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$groups = $this->group( $this->parse_feed( $data ) );
		unset( $data );
		if ( empty( $groups ) ) {
			return new WP_Error( 'cm_kevro', 'No products found in feed.' );
		}
		update_option( self::QUEUE_KEY, $groups, false );
		$this->save_state(
			array(
				'status'  => 'running', 'total' => count( $groups ), 'offset' => 0,
				'created' => 0, 'updated' => 0, 'refreshed' => 0, 'pruned' => 0, 'errors' => 0,
				'img_expected' => 0, 'img_ok' => 0, 'img_failed' => 0,
				'started' => time(), 'heartbeat' => time(),
				'message' => 'Queued ' . count( $groups ) . ' products.', 'recent' => array(), 'error_log' => array(),
			)
		);
		// Automatic pre-sync pass: re-attempt any images that failed previously.
		// Runs after the state reset so recovered images count toward this run.
		$this->retry_failed_images();
		delete_transient( self::LOCK_KEY );
		$this->kick();
		return count( $groups );
	}

	/** Schedule an immediate background batch and nudge WP-Cron to run it. */
	private function kick() {
		if ( ! wp_next_scheduled( self::RUNNER_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUNNER_HOOK );
		}
		spawn_cron();
	}

	/**
	 * Process queued headers for a fixed time budget, then re-chain itself.
	 * Runs server-side via WP-Cron, so it continues after the admin leaves
	 * the import page (a page reload simply re-attaches the live progress).
	 */
	public function process_batch() {
		$s = $this->state();
		if ( 'running' !== $s['status'] ) {
			return;
		}
		if ( corpmerch_sync_paused() ) {
			delete_transient( self::LOCK_KEY );
			return; // global kill-switch: stop chaining.
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			return; // another tick is already working.
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );
		$this->raise_limits();

		$groups = get_option( self::QUEUE_KEY );
		if ( ! is_array( $groups ) ) {
			$s['status']  = 'error';
			$s['message'] = 'Import queue missing — start again.';
			$this->save_state( $s );
			delete_transient( self::LOCK_KEY );
			return;
		}

		$keys   = array_keys( $groups );
		$total  = count( $keys );
		$batch  = max( 1, min( 50, (int) corpmerch_option( 'kevro_batch', 15 ) ) );
		$budget = time() + 25; // seconds of work per cron tick.

		do {
			$slice = array_slice( $keys, $s['offset'], $batch );
			if ( empty( $slice ) ) {
				break;
			}
			foreach ( $slice as $hid ) {
				try {
					$res  = $this->import_header( $hid, $groups[ $hid ] );
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
					$name = '';
					if ( isset( $groups[ $hid ][0] ) && is_array( $groups[ $hid ][0] ) ) {
						$row  = $groups[ $hid ][0];
						$name = isset( $row['description'] ) ? (string) $row['description'] : '';
					}
					array_unshift(
						$s['error_log'],
						array(
							'header_id' => (string) $hid,
							'name'      => $name,
							'message'   => $e->getMessage(),
							'where'     => basename( $e->getFile() ) . ':' . $e->getLine(),
						)
					);
					$s['error_log'] = array_slice( $s['error_log'], 0, 20 );
				}
			}
			$s['offset']   += count( $slice );
			$s['heartbeat'] = time();
			$s['message']   = 'Synced ' . $s['offset'] . ' / ' . $total . '.';
			$this->save_state( $s ); // live progress for the poller.
		} while ( $s['offset'] < $total && time() < $budget );

		if ( $s['offset'] >= $total ) {
			$s['pruned']  = $this->prune( $keys );
			$s['status']  = 'done';
			$s['message'] = 'Import complete — ' . $total . ' products.';
			delete_option( self::QUEUE_KEY );
			update_option( self::LOG_KEY, array( 'time' => time(), 'ok' => true, 'msg' => 'Background import', 'count' => $total ) );
		}
		$this->save_state( $s );
		delete_transient( self::LOCK_KEY );

		if ( 'running' === $s['status'] ) {
			$this->kick(); // chain the next tick.
		}
	}

	/** Cron safety net: resume a run that has stalled (no heartbeat). */
	public function watchdog() {
		$s = $this->state();
		if ( 'running' === $s['status'] && ( time() - (int) $s['heartbeat'] ) >= 90 ) {
			delete_transient( self::LOCK_KEY );
			$this->kick();
		}
	}

	/** Scheduled (hourly/daily) auto-import — just queues a background run. */
	public function cron_run() {
		if ( corpmerch_sync_paused() ) {
			return;
		}
		$s = $this->state();
		if ( 'running' === $s['status'] ) {
			return;
		}
		$this->start_run();
	}

	/** Hard-stop this engine: clear schedules, queue, lock, and mark idle. */
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

	/* ----------------------------------------------------------------- cron */

	public function reconcile_schedule() {
		if ( ! wp_next_scheduled( self::WATCH_HOOK ) ) {
			wp_schedule_event( time() + 60, 'cm_minute', self::WATCH_HOOK );
		}
		$sched = corpmerch_option( 'kevro_schedule', 'off' );
		$next  = wp_next_scheduled( self::CRON_HOOK );
		if ( 'off' === $sched || ! in_array( $sched, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		// Reschedule if missing or interval changed.
		$current = wp_get_scheduled_event( self::CRON_HOOK );
		if ( ! $current || ( isset( $current->schedule ) && $current->schedule !== $sched ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( time() + 60, $sched, self::CRON_HOOK );
		}
	}

	/* ----------------------------------------------------------------- AJAX */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cm_kevro', 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Permission denied.' ) );
		}
	}

	/** Large feeds need headroom: lift time + memory before fetch/parse. */
	private function raise_limits() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '1024M' ); // phpcs:ignore
	}

	public function ajax_test() {
		$this->guard();
		if ( ! $this->ready() ) {
			wp_send_json_error( array( 'msg' => 'Enter Entity ID, web-service username and password first.' ) );
		}
		$r = $this->test_login();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'msg' => $r->get_error_message() ) );
		}
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( array( 'msg' => $r['error'] ? $r['error'] : 'Login failed.' ) );
		}
		wp_send_json_success( array( 'msg' => 'Login OK. Connection authenticated.' ) );
	}

	public function ajax_preview() {
		$this->guard();
		$this->raise_limits();
		$data = $this->get_feed();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'msg' => $data->get_error_message() ) );
		}
		$rows = $this->parse_feed( $data );
		unset( $data ); // release the raw feed string.

		// Count distinct products by header without duplicating every row.
		$seen = array();
		foreach ( $rows as $row ) {
			$hid = $this->field( $row, 'stockheaderid', $this->field( $row, 'stockcode' ) );
			if ( '' !== $hid ) {
				$seen[ $hid ] = true;
			}
		}
		$sample = array_slice( $rows, 0, 3 );
		$fields = $rows ? array_keys( $rows[0] ) : array();
		$qtyhit = ( $rows && null !== $this->row_qty( $rows[0] ) );

		$out = array(
			'rows'     => count( $rows ),
			'products' => count( $seen ),
			'fields'   => $fields,
			'qty_ok'   => $qtyhit,
			'sample'   => $sample,
		);
		// When nothing parsed, expose the raw payload so the feed shape can be diagnosed.
		if ( ! $rows ) {
			$out['debug'] = array(
				'data_len'      => strlen( (string) $this->debug['data'] ),
				'data_head'     => (string) $this->debug['data'],
				'envelope_head' => (string) $this->debug['body'],
			);
		}
		wp_send_json_success( $out );
	}

	public function ajax_start() {
		$this->guard();
		$total = $this->start_run();
		if ( is_wp_error( $total ) ) {
			wp_send_json_error( array( 'msg' => $total->get_error_message() ) );
		}
		wp_send_json_success( array( 'total' => $total ) );
	}

	/** Return live run-state; nudge the runner so it keeps moving while polled. */
	public function ajax_status() {
		$this->guard();
		$s = $this->state();
		if ( 'running' === $s['status'] ) {
			if ( ( time() - (int) $s['heartbeat'] ) >= 90 ) {
				delete_transient( self::LOCK_KEY );
			}
			$this->kick();
		}
		$log = get_option( self::FAILED_IMG_KEY, array() );
		$s['img_failed_pending'] = is_array( $log ) ? count( $log ) : 0;
		wp_send_json_success( $s );
	}

	public function ajax_stop() {
		$this->guard();
		wp_clear_scheduled_hook( self::RUNNER_HOOK );
		delete_option( self::QUEUE_KEY );
		delete_transient( self::LOCK_KEY );
		$s            = $this->state();
		$s['status']  = 'idle';
		$s['message'] = 'Import stopped.';
		$this->save_state( $s );
		wp_send_json_success( array( 'msg' => 'Import stopped.' ) );
	}
}

$GLOBALS['corpmerch_kevro'] = new Corpmerch_Kevro();

/** Accessor for the live Kevro importer instance (used by admin tools). */
function corpmerch_kevro() {
	return isset( $GLOBALS['corpmerch_kevro'] ) ? $GLOBALS['corpmerch_kevro'] : null;
}
