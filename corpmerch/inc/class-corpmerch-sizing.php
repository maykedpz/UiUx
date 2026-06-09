<?php
/**
 * Corpmerch — Size charts.
 *
 * Shows a small size-chart thumbnail on apparel product pages that expands to a
 * full-resolution lightbox on click. The chart image comes from Kevro's blob
 * CDN (detected & cached as `_kevro_sizing_img` during the branding sync), with
 * an optional per-category fallback chart assigned in Corpmerch Settings.
 *
 * @package Corpmerch
 */

defined( 'ABSPATH' ) || exit;

final class Corpmerch_Sizing {

	/** @var bool Tracks whether the lightbox markup/CSS has been printed. */
	private $lightbox_printed = false;

	public function __construct() {
		// After the short description / features (26), before branding (the
		// add-to-cart hooks fire later), so the guide sits with product info.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 27 );
	}

	/** Resolve the size-chart URL for a product: blob image first, then category fallback. */
	private function chart_url( $product_id ) {
		$url = trim( (string) get_post_meta( (int) $product_id, '_kevro_sizing_img', true ) );
		if ( '' !== $url ) {
			return $url;
		}
		return $this->category_fallback_url( $product_id );
	}

	/**
	 * Per-category fallback chart. Settings store a newline/comma list of
	 * "category-slug | image-url" pairs (term slug from product_cat). The first
	 * matching term on the product wins.
	 */
	private function category_fallback_url( $product_id ) {
		$raw = trim( (string) corpmerch_option( 'sizing_fallbacks', '' ) );
		if ( '' === $raw ) {
			return '';
		}
		$map = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $slug, $img ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $slug && '' !== $img ) {
				$map[ strtolower( $slug ) ] = $img;
			}
		}
		if ( empty( $map ) ) {
			return '';
		}
		$terms = wp_get_post_terms( (int) $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		foreach ( $terms as $slug ) {
			$slug = strtolower( $slug );
			if ( isset( $map[ $slug ] ) ) {
				return $map[ $slug ];
			}
		}
		return '';
	}

	/** Render the thumbnail + lightbox trigger on the single product summary. */
	public function render() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$url = $this->chart_url( $product->get_id() );
		if ( '' === $url ) {
			$this->admin_diagnostic( $product->get_id() );
			return;
		}

		$label = __( 'Size guide', 'corpmerch' );
		echo '<div class="sia-sizing" data-sia-sizing>';
		echo '<button type="button" class="sia-sizing-trigger" data-sia-sizing-open aria-haspopup="dialog">';
		echo '<img class="sia-sizing-thumb" src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" loading="lazy" width="140" height="140">';
		echo '<span class="sia-sizing-label">' . esc_html( $label ) . '</span>';
		echo '</button>';
		echo '</div>';

		$this->print_lightbox( $url, $label );
	}

	/**
	 * Admin-only diagnostic shown when no size guide renders. Surfaces the
	 * stored meta and live-tests the derived blob URL so we can tell
	 * "sync not run" apart from "blob 404 / item_no missing". Never shown to
	 * customers (manage_options gate).
	 */
	private function admin_diagnostic( $product_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$product_id = (int) $product_id;
		$item_no  = trim( (string) get_post_meta( $product_id, '_kevro_item_no', true ) );
		$sizing   = trim( (string) get_post_meta( $product_id, '_kevro_sizing_img', true ) );
		$guide    = trim( (string) get_post_meta( $product_id, '_kevro_branding_guide', true ) );
		$checked  = (int) get_post_meta( $product_id, '_kevro_branding_checked', true );
		$header   = (int) get_post_meta( $product_id, '_kevro_header_id', true );

		// Build the candidate blob URLs the same way detect_size_chart() does.
		$candidates = array();
		if ( '' !== $item_no ) {
			$base = untrailingslashit( corpmerch_option( 'sizing_base_url', 'https://paznsaapp02.blob.core.windows.net/product-images' ) );
			$raw  = (string) corpmerch_option( 'sizing_suffixes', '-sizing.jpg, -sizing.png' );
			$suffixes = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			if ( empty( $suffixes ) ) {
				$suffixes = array( '-sizing.jpg', '-sizing.png' );
			}
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
			$folders = array( $item_no );
			if ( strtolower( $item_no ) !== $item_no ) {
				$folders[] = strtolower( $item_no );
			}
			foreach ( $folders as $folder ) {
				foreach ( $suffixes as $suffix ) {
					$candidates[] = $base . '/' . $folder . '/' . $folder . $suffix;
				}
			}
		}

		$rows = array();
		$rows[] = array( 'Header ID (_kevro_header_id)', $header > 0 ? (string) $header : '— missing —' );
		$rows[] = array( 'Item no (_kevro_item_no)', '' !== $item_no ? $item_no : '— missing (size chart needs this) —' );
		$rows[] = array( 'Sizing img (_kevro_sizing_img)', '' !== $sizing ? $sizing : '— empty —' );
		$rows[] = array( 'Branding guide (_kevro_branding_guide)', '' !== $guide ? $guide : '— empty —' );
		$rows[] = array( 'Branding sync run? (_kevro_branding_checked)', $checked > 0 ? date_i18n( 'Y-m-d H:i', $checked ) : '— never (run Branding sync on Kevro tab) —' );

		echo '<div class="sia-sizing-diag" style="margin:14px 0;padding:12px 14px;border:1px dashed #c026d3;border-radius:8px;background:#fdf4ff;font:13px/1.5 monospace;color:#3b0764">';
		echo '<strong style="display:block;margin-bottom:6px">Corpmerch size/branding diagnostic (admins only)</strong>';
		echo '<table style="border-collapse:collapse;width:100%">';
		foreach ( $rows as $r ) {
			echo '<tr><td style="padding:2px 8px 2px 0;vertical-align:top;white-space:nowrap;color:#701a75">' . esc_html( $r[0] ) . '</td><td style="padding:2px 0;word-break:break-all">' . esc_html( $r[1] ) . '</td></tr>';
		}
		echo '</table>';

		if ( ! empty( $candidates ) ) {
			echo '<div style="margin-top:8px"><strong>Live blob URL test:</strong></div>';
			foreach ( $candidates as $u ) {
				$res  = wp_remote_head( $u, array( 'timeout' => 8, 'redirection' => 2 ) );
				$code = is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_response_code( $res );
				$ok   = ( '200' === $code );
				echo '<div style="word-break:break-all;color:' . ( $ok ? '#15803d' : '#b91c1c' ) . '">[' . esc_html( $code ) . '] ' . esc_html( $u ) . '</div>';
			}
		} elseif ( '' === $item_no ) {
			echo '<div style="margin-top:8px;color:#b91c1c">No item_no stored → cannot derive blob URL. Re-import or run branding sync to populate it from the product image URL.</div>';
		}
		echo '</div>';
	}

	/** Print the lightbox markup, CSS and a tiny self-contained script once. */
	private function print_lightbox( $url, $label ) {
		if ( $this->lightbox_printed ) {
			return;
		}
		$this->lightbox_printed = true;
		?>
		<style>
			.sia-sizing{margin:14px 0}
			.sia-sizing-trigger{display:inline-flex;align-items:center;gap:10px;padding:6px 6px 6px 6px;background:var(--sia-surface-alt,#eef2f8);border:1px solid rgba(30,58,138,.12);border-radius:10px;cursor:zoom-in;line-height:1}
			.sia-sizing-trigger:hover{border-color:var(--sia-accent,#2563eb)}
			.sia-sizing-thumb{width:48px;height:48px;object-fit:contain;border-radius:6px;background:#fff;flex:0 0 auto;display:block}
			.sia-sizing-label{font-weight:600;color:var(--sia-primary,#1e3a8a);font-size:.9rem;padding-right:8px}
			.sia-sizing-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.82);padding:24px}
			.sia-sizing-modal.is-open{display:flex}
			.sia-sizing-modal-img{max-width:96vw;max-height:90vh;width:auto;height:auto;border-radius:8px;background:#fff;box-shadow:0 12px 48px rgba(0,0,0,.4)}
			.sia-sizing-close{position:absolute;top:18px;right:22px;width:42px;height:42px;border-radius:50%;border:none;background:rgba(255,255,255,.92);color:#16306e;font-size:24px;line-height:42px;cursor:pointer}
			.sia-sizing-close:hover{background:#fff}
		</style>
		<div class="sia-sizing-modal" data-sia-sizing-modal role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $label ); ?>">
			<button type="button" class="sia-sizing-close" data-sia-sizing-close aria-label="<?php esc_attr_e( 'Close', 'corpmerch' ); ?>">&times;</button>
			<img class="sia-sizing-modal-img" src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $label ); ?>">
		</div>
		<script>
		(function(){
			var modal=document.querySelector('[data-sia-sizing-modal]');
			if(!modal)return;
			function open(e){if(e)e.preventDefault();modal.classList.add('is-open');document.body.style.overflow='hidden';}
			function close(){modal.classList.remove('is-open');document.body.style.overflow='';}
			document.querySelectorAll('[data-sia-sizing-open]').forEach(function(b){b.addEventListener('click',open);});
			modal.querySelector('[data-sia-sizing-close]').addEventListener('click',close);
			modal.addEventListener('click',function(e){if(e.target===modal)close();});
			document.addEventListener('keyup',function(e){if(e.key==='Escape')close();});
		})();
		</script>
		<?php
	}
}

new Corpmerch_Sizing();
