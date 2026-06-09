<?php
/**
 * Corpmerch — Product Manager (admin)
 *
 * A backend dashboard listing ALL products with filters (image status,
 * publish status, stock status, recently added) and a per-product / bulk
 * "Download image" action that kicks the Kevro importer to (re)fetch a
 * missing main image. Pure admin tool — no storefront output.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Corpmerch_Products_Admin {

	const SLUG     = 'corpmerch-products';
	const PER_PAGE = 40;
	const NONCE    = 'cm_products_admin';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_cm_kick_image', array( $this, 'ajax_kick_image' ) );
	}

	/** Register as a submenu under the Corpmerch top-level menu. */
	public function register_menu() {
		add_submenu_page(
			'corpmerch',
			__( 'Product Manager', 'corpmerch' ),
			__( 'Product Manager', 'corpmerch' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		$nonce = wp_create_nonce( self::NONCE );
		wp_add_inline_script(
			'jquery-core',
			'window.CM_PRODUCTS = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => $nonce,
				)
			) . ';'
		);
	}

	/* ----------------------------------------------------------- query */

	/**
	 * Build the WP_Query args from the current request filters.
	 *
	 * Filters (GET):
	 *   img    = any|with|without   image (featured thumbnail) presence
	 *   status = any|publish|draft  publish status
	 *   stock  = any|instock|outofstock
	 *   new    = ''|7|30            added within N days
	 *   s      = search term (title/SKU)
	 *   paged  = page number
	 */
	private function query_args() {
		$img    = $this->req( 'img', 'any' );
		$status = $this->req( 'status', 'any' );
		$stock  = $this->req( 'stock', 'any' );
		$new     = (int) $this->req( 'new', '' );
		$updated = (int) $this->req( 'updated', '' );
		$search  = trim( (string) $this->req( 's', '' ) );
		$paged  = max( 1, (int) $this->req( 'paged', 1 ) );

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery
			'tax_query'      => array(), // phpcs:ignore WordPress.DB.SlowDBQuery
		);

		if ( 'publish' === $status || 'draft' === $status ) {
			$args['post_status'] = array( $status );
		}

		if ( 'with' === $img ) {
			$args['meta_query'][] = array(
				'key'     => '_thumbnail_id',
				'compare' => 'EXISTS',
			);
		} elseif ( 'without' === $img ) {
			$args['meta_query'][] = array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			);
		}

		if ( 'instock' === $stock || 'outofstock' === $stock ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => 'outofstock',
				'operator' => ( 'outofstock' === $stock ) ? 'IN' : 'NOT IN',
			);
		}

		if ( $new > 0 ) {
			$args['date_query'] = array(
				array( 'after' => $new . ' days ago', 'inclusive' => true ),
			);
		}

		if ( $updated > 0 ) {
			$args['date_query'] = isset( $args['date_query'] ) ? $args['date_query'] : array();
			$args['date_query'][] = array(
				'column'    => 'post_modified',
				'after'     => $updated . ' days ago',
				'inclusive' => true,
			);
			// Surface the most recently changed products first.
			$args['orderby'] = 'modified';
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return $args;
	}

	/* ----------------------------------------------------------- render */

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'corpmerch' ) );
		}

		$args  = $this->query_args();
		$query = new WP_Query( $args );
		$base  = menu_page_url( self::SLUG, false );

		$img    = $this->req( 'img', 'any' );
		$status = $this->req( 'status', 'any' );
		$stock  = $this->req( 'stock', 'any' );
		$new     = (string) $this->req( 'new', '' );
		$updated = (string) $this->req( 'updated', '' );
		$search  = (string) $this->req( 's', '' );

		echo '<div class="wrap cm-products">';
		echo '<h1>' . esc_html__( 'Product Manager', 'corpmerch' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'All products across every status. Use the filters to find products missing images, then kick them to (re)download from Kevro.', 'corpmerch' ) . '</p>';

		$this->render_summary();

		// Filter form.
		echo '<form method="get" class="cm-products__filters" style="margin:16px 0;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		$this->select( 'img', $img, array(
			'any'     => __( 'All images', 'corpmerch' ),
			'without' => __( 'Missing image', 'corpmerch' ),
			'with'    => __( 'Has image', 'corpmerch' ),
		), __( 'Image', 'corpmerch' ) );

		$this->select( 'status', $status, array(
			'any'     => __( 'Any status', 'corpmerch' ),
			'publish' => __( 'Live', 'corpmerch' ),
			'draft'   => __( 'Draft', 'corpmerch' ),
		), __( 'Status', 'corpmerch' ) );

		$this->select( 'stock', $stock, array(
			'any'        => __( 'Any stock', 'corpmerch' ),
			'instock'    => __( 'In stock', 'corpmerch' ),
			'outofstock' => __( 'No stock', 'corpmerch' ),
		), __( 'Stock', 'corpmerch' ) );

		$this->select( 'new', $new, array(
			''   => __( 'Any age', 'corpmerch' ),
			'7'  => __( 'Added last 7 days', 'corpmerch' ),
			'30' => __( 'Added last 30 days', 'corpmerch' ),
		), __( 'Added', 'corpmerch' ) );

		$this->select( 'updated', $updated, array(
			''   => __( 'Any time', 'corpmerch' ),
			'1'  => __( 'Updated last 24 hours', 'corpmerch' ),
			'7'  => __( 'Updated last 7 days', 'corpmerch' ),
			'30' => __( 'Updated last 30 days', 'corpmerch' ),
		), __( 'Updated', 'corpmerch' ) );

		echo '<label style="display:flex;flex-direction:column;font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;">' . esc_html__( 'Search', 'corpmerch' );
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Title or SKU', 'corpmerch' ) . '" style="min-width:200px;" /></label>';

		echo '<button class="button button-primary">' . esc_html__( 'Filter', 'corpmerch' ) . '</button>';
		echo '<a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Reset', 'corpmerch' ) . '</a>';
		echo '</form>';

		// Bulk action bar (only meaningful when filtering missing images).
		echo '<div class="cm-products__bulk" style="margin:12px 0;display:flex;gap:10px;align-items:center;">';
		echo '<button type="button" class="button" id="cm-kick-page">' . esc_html__( 'Download images for all on this page', 'corpmerch' ) . '</button>';
		echo '<span id="cm-kick-progress" style="color:#646970;"></span>';
		echo '</div>';

		// Results table.
		echo '<div class="cm-products__scroll" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
		echo '<table class="wp-list-table widefat striped" style="min-width:760px;table-layout:auto;">';
		echo '<thead><tr>';
		echo '<th style="width:64px;">' . esc_html__( 'Image', 'corpmerch' ) . '</th>';
		echo '<th style="min-width:220px;">' . esc_html__( 'Product', 'corpmerch' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Status', 'corpmerch' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Stock', 'corpmerch' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Added', 'corpmerch' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Updated', 'corpmerch' ) . '</th>';
		echo '<th style="width:200px;">' . esc_html__( 'Action', 'corpmerch' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$this->render_row( get_the_ID() );
			}
			wp_reset_postdata();
		} else {
			echo '<tr><td colspan="7">' . esc_html__( 'No products match these filters.', 'corpmerch' ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		// Pagination.
		$total = (int) $query->max_num_pages;
		if ( $total > 1 ) {
			$paged = max( 1, (int) $this->req( 'paged', 1 ) );
			echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;">';
			echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total,
				'prev_text' => '‹',
				'next_text' => '›',
			) );
			echo '</div></div>';
		}

		$this->inline_js();
		echo '</div>';
	}

	/** Top summary counts. */
	private function render_summary() {
		$counts = (array) wp_count_posts( 'product' );
		$live   = isset( $counts['publish'] ) ? (int) $counts['publish'] : 0;
		$draft  = isset( $counts['draft'] ) ? (int) $counts['draft'] : 0;

		$no_img = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$missing = (int) $no_img->found_posts;
		wp_reset_postdata();

		echo '<div class="cm-products__summary" style="display:flex;gap:18px;margin:14px 0;flex-wrap:wrap;">';
		$this->stat( __( 'Live', 'corpmerch' ), $live );
		$this->stat( __( 'Draft', 'corpmerch' ), $draft );
		$this->stat( __( 'Missing image', 'corpmerch' ), $missing, $missing ? '#b32d2e' : '#2271b1' );
		echo '</div>';
	}

	private function stat( $label, $value, $color = '#2271b1' ) {
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 16px;min-width:110px;">';
		echo '<div style="font-size:22px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( number_format_i18n( $value ) ) . '</div>';
		echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;">' . esc_html( $label ) . '</div>';
		echo '</div>';
	}

	private function render_row( $pid ) {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return;
		}
		$img_id   = (int) $product->get_image_id();
		$has_img  = $img_id > 0;
		$status   = get_post_status( $pid );
		$in_stock = $product->is_in_stock();
		$added    = get_the_date( 'Y-m-d H:i', $pid );
		$edit     = get_edit_post_link( $pid );
		$header   = (int) get_post_meta( $pid, '_kevro_header_id', true );

		echo '<tr data-pid="' . esc_attr( $pid ) . '">';

		// Thumb.
		echo '<td>';
		if ( $has_img ) {
			echo wp_get_attachment_image( $img_id, array( 48, 48 ), false, array( 'style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo '<span class="cm-noimg" style="display:inline-flex;width:48px;height:48px;align-items:center;justify-content:center;background:#fbeaea;color:#b32d2e;border-radius:4px;font-size:18px;">⚠</span>';
		}
		echo '</td>';

		// Title.
		echo '<td><strong>';
		echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : esc_html( get_the_title( $pid ) );
		echo '</strong>';
		$sku = $product->get_sku();
		if ( $sku ) {
			echo '<br><span style="color:#646970;font-size:12px;">' . esc_html__( 'SKU:', 'corpmerch' ) . ' ' . esc_html( $sku ) . '</span>';
		}
		echo '<br><span style="color:#646970;font-size:12px;">' . esc_html__( 'StockHeaderID:', 'corpmerch' ) . ' ' . ( $header ? '<code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;">' . esc_html( $header ) . '</code>' : '<em style="color:#b32d2e;">—</em>' ) . '</span>';
		echo '</td>';

		// Status.
		$badge = ( 'publish' === $status )
			? '<span style="color:#1a7f37;font-weight:600;">' . esc_html__( 'Live', 'corpmerch' ) . '</span>'
			: '<span style="color:#996800;font-weight:600;">' . esc_html( ucfirst( $status ) ) . '</span>';
		echo '<td>' . $badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Stock.
		echo '<td>' . ( $in_stock
			? '<span style="color:#1a7f37;">' . esc_html__( 'In stock', 'corpmerch' ) . '</span>'
			: '<span style="color:#b32d2e;">' . esc_html__( 'No stock', 'corpmerch' ) . '</span>' ) . '</td>';

		// Added.
		echo '<td style="color:#646970;font-size:12px;">' . esc_html( $added ) . '</td>';

		// Updated (modified).
		$modified = get_post_modified_time( 'Y-m-d H:i', false, $pid );
		echo '<td style="color:#646970;font-size:12px;">' . esc_html( $modified ) . '</td>';

		// Action.
		echo '<td>';
		if ( $has_img ) {
			echo '<span style="color:#646970;">' . esc_html__( 'Image set', 'corpmerch' ) . '</span>';
		} elseif ( $header <= 0 ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'No Kevro link', 'corpmerch' ) . '</span>';
		} else {
			echo '<button type="button" class="button cm-kick" data-pid="' . esc_attr( $pid ) . '">' . esc_html__( 'Download image', 'corpmerch' ) . '</button>';
			echo ' <span class="cm-kick-status" style="font-size:12px;"></span>';
		}
		echo '</td>';

		echo '</tr>';
	}

	/* ----------------------------------------------------------- ajax */

	public function ajax_kick_image() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'corpmerch' ) ) );
		}
		$pid    = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
		$kevro  = function_exists( 'corpmerch_kevro' ) ? corpmerch_kevro() : null;
		if ( ! $kevro || ! method_exists( $kevro, 'kick_product_image' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Importer unavailable.', 'corpmerch' ) ) );
		}
		$res = $kevro->kick_product_image( $pid );
		if ( ! empty( $res['ok'] ) && ! empty( $res['image_id'] ) ) {
			$res['thumb'] = wp_get_attachment_image_url( (int) $res['image_id'], array( 48, 48 ) );
		}
		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( $res );
		}
		wp_send_json_error( $res );
	}

	/* ----------------------------------------------------------- ui helpers */

	private function req( $key, $default = '' ) {
		// Read-only filter inputs; sanitized on use. Nonce not required for GET filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private function select( $name, $current, $options, $label ) {
		echo '<label style="display:flex;flex-direction:column;font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;">' . esc_html( $label );
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( (string) $current, (string) $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	private function inline_js() {
		?>
<script>
(function($){
	var CFG = window.CM_PRODUCTS || {};
	function kick($btn){
		var pid = $btn.data('pid');
		var $row = $btn.closest('tr');
		var $stat = $row.find('.cm-kick-status');
		return $.post(CFG.ajax, { action:'cm_kick_image', nonce:CFG.nonce, pid:pid })
			.done(function(r){
				if (r && r.success){
					$stat.css('color','#1a7f37').text(r.data.msg || 'Done');
					if (r.data.thumb){
						$row.find('td:first').html('<img src="'+r.data.thumb+'" style="width:48px;height:48px;object-fit:cover;border-radius:4px;">');
					}
					$btn.remove();
				} else {
					$stat.css('color','#b32d2e').text((r && r.data && r.data.msg) || 'Failed');
				}
			})
			.fail(function(){ $stat.css('color','#b32d2e').text('Request error'); });
	}
	$(document).on('click','.cm-kick', function(){
		var $b = $(this); $b.prop('disabled',true);
		$b.closest('tr').find('.cm-kick-status').css('color','#646970').text('Downloading…');
		kick($b).always(function(){ $b.prop('disabled',false); });
	});
	$('#cm-kick-page').on('click', function(){
		var $btns = $('.cm-kick').toArray();
		var total = $btns.length, done = 0;
		var $p = $('#cm-kick-progress');
		if (!total){ $p.text('Nothing to download on this page.'); return; }
		$(this).prop('disabled',true);
		(function next(){
			if (!$btns.length){ $p.text('Finished: '+done+'/'+total+' processed.'); $('#cm-kick-page').prop('disabled',false); return; }
			var $b = $($btns.shift());
			$b.prop('disabled',true);
			$b.closest('tr').find('.cm-kick-status').css('color','#646970').text('Downloading…');
			kick($b).always(function(){ done++; $p.text('Processing '+done+'/'+total+'…'); next(); });
		})();
	});
})(jQuery);
</script>
		<?php
	}
}

new Corpmerch_Products_Admin();
