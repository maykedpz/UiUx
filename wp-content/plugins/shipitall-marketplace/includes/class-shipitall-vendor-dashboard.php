<?php
/**
 * Frontend vendor dashboard: [shipitall_vendor_dashboard].
 *
 * Deliberately thin — product add/edit reuses wp-admin's WooCommerce
 * product editor (linked out to from here) rather than reimplementing
 * a frontend product form. Building a from-scratch image-upload/
 * variations-capable product editor is a substantial project of its
 * own; this dashboard is the vendor's home base for the things that
 * don't already have a good wp-admin screen: earnings, orders, and
 * store profile.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Vendor_Dashboard {

	public static function init() {
		add_shortcode( 'shipitall_vendor_dashboard', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_shipitall_save_store_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
	}

	public static function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, 'shipitall_vendor_dashboard' ) && ! has_shortcode( $post->post_content, 'shipitall_become_a_vendor' ) ) {
			return;
		}

		wp_enqueue_style(
			'shipitall-marketplace',
			SHIPITALL_MARKETPLACE_URL . 'assets/css/shipitall-marketplace.css',
			array(),
			SHIPITALL_MARKETPLACE_VERSION
		);
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to view your vendor dashboard.', 'shipitall-marketplace' ) . '</p>';
		}

		$user_id = get_current_user_id();
		$store   = Shipitall_Store_CPT::get_store_for_owner( $user_id );

		if ( ! $store ) {
			return '<p>' . esc_html__( 'You don\'t have a vendor store yet.', 'shipitall-marketplace' ) . '</p>';
		}

		if ( 'publish' !== $store->post_status ) {
			return '<p>' . esc_html__( 'Your vendor application is still in review.', 'shipitall-marketplace' ) . '</p>';
		}

		ob_start();
		self::render_overview( $user_id, $store );
		self::render_products( $user_id );
		self::render_orders( $user_id );
		self::render_store_settings( $store );
		return ob_get_clean();
	}

	private static function render_overview( $vendor_id, $store ) {
		$pending  = Shipitall_Commissions::get_pending_total_for_vendor( $vendor_id );
		$lifetime = Shipitall_Commissions::get_lifetime_total_for_vendor( $vendor_id );
		?>
		<section class="shipitall-dashboard-overview">
			<h2><?php esc_html_e( 'Overview', 'shipitall-marketplace' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Pending payout:', 'shipitall-marketplace' ); ?> <?php echo wp_kses_post( wc_price( $pending ) ); ?></li>
				<li><?php esc_html_e( 'Lifetime sales:', 'shipitall-marketplace' ); ?> <?php echo wp_kses_post( wc_price( $lifetime ) ); ?></li>
				<li><?php esc_html_e( 'Commission rate:', 'shipitall-marketplace' ); ?> <?php echo esc_html( Shipitall_Commissions::get_rate_for_store( $store->ID ) ); ?>%</li>
			</ul>
		</section>
		<?php
	}

	private static function render_products( $vendor_id ) {
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'author'         => $vendor_id,
				'posts_per_page' => 20,
			)
		);
		?>
		<section class="shipitall-dashboard-products">
			<h2><?php esc_html_e( 'My Products', 'shipitall-marketplace' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>">
					<?php esc_html_e( 'Add a product', 'shipitall-marketplace' ); ?>
				</a>
			</p>
			<?php if ( empty( $products ) ) : ?>
				<p><?php esc_html_e( 'No products yet.', 'shipitall-marketplace' ); ?></p>
			<?php else : ?>
				<table class="shipitall-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'shipitall-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Price', 'shipitall-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Status', 'shipitall-marketplace' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $products as $post ) : ?>
							<?php $product = wc_get_product( $post->ID ); ?>
							<tr>
								<td><?php echo esc_html( $post->post_title ); ?></td>
								<td><?php echo $product ? wp_kses_post( $product->get_price_html() ) : ''; ?></td>
								<td><?php echo esc_html( get_post_status_object( $post->post_status )->label ); ?></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'shipitall-marketplace' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_orders( $vendor_id ) {
		$suborders = Shipitall_Order_Splitter::get_suborders_for_vendor( $vendor_id );
		?>
		<section class="shipitall-dashboard-orders">
			<h2><?php esc_html_e( 'My Orders', 'shipitall-marketplace' ); ?></h2>
			<?php if ( empty( $suborders ) ) : ?>
				<p><?php esc_html_e( 'No orders yet.', 'shipitall-marketplace' ); ?></p>
			<?php else : ?>
				<table class="shipitall-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'shipitall-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Date', 'shipitall-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Total', 'shipitall-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Status', 'shipitall-marketplace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suborders as $suborder ) : ?>
							<tr>
								<td>#<?php echo esc_html( $suborder->get_id() ); ?></td>
								<td><?php echo esc_html( wc_format_datetime( $suborder->get_date_created() ) ); ?></td>
								<td><?php echo wp_kses_post( $suborder->get_formatted_order_total() ); ?></td>
								<td><?php echo esc_html( wc_get_order_status_name( $suborder->get_status() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_store_settings( $store ) {
		?>
		<section class="shipitall-dashboard-settings">
			<h2><?php esc_html_e( 'Store Settings', 'shipitall-marketplace' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shipitall_save_store_settings">
				<input type="hidden" name="store_id" value="<?php echo esc_attr( $store->ID ); ?>">
				<?php wp_nonce_field( 'shipitall_save_store_settings_' . $store->ID ); ?>

				<p>
					<label for="shipitall_store_title"><?php esc_html_e( 'Store name', 'shipitall-marketplace' ); ?></label>
					<input type="text" id="shipitall_store_title" name="store_title" value="<?php echo esc_attr( $store->post_title ); ?>" required>
				</p>
				<p>
					<label for="shipitall_store_description"><?php esc_html_e( 'Store description', 'shipitall-marketplace' ); ?></label>
					<textarea id="shipitall_store_description" name="store_description" rows="4"><?php echo esc_textarea( $store->post_content ); ?></textarea>
				</p>
				<p>
					<button type="submit"><?php esc_html_e( 'Save', 'shipitall-marketplace' ); ?></button>
				</p>
			</form>
		</section>
		<?php
	}

	public static function handle_save_settings() {
		$store_id = isset( $_POST['store_id'] ) ? absint( $_POST['store_id'] ) : 0;

		check_admin_referer( 'shipitall_save_store_settings_' . $store_id );

		if ( Shipitall_Store_CPT::get_owner_id( $store_id ) !== get_current_user_id() ) {
			wp_die( esc_html__( 'You can only edit your own store.', 'shipitall-marketplace' ) );
		}

		wp_update_post(
			array(
				'ID'           => $store_id,
				'post_title'   => isset( $_POST['store_title'] ) ? sanitize_text_field( wp_unslash( $_POST['store_title'] ) ) : '',
				'post_content' => isset( $_POST['store_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['store_description'] ) ) : '',
			)
		);

		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}
}
