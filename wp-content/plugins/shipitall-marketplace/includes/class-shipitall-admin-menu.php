<?php
/**
 * Top-level "Shipitall" admin menu: Settings + Payouts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Admin_Menu {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_shipitall_mark_paid', array( __CLASS__, 'handle_mark_paid' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Shipitall Marketplace', 'shipitall-marketplace' ),
			__( 'Shipitall', 'shipitall-marketplace' ),
			'manage_woocommerce',
			'shipitall-marketplace',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-store',
			56
		);

		add_submenu_page(
			'shipitall-marketplace',
			__( 'Settings', 'shipitall-marketplace' ),
			__( 'Settings', 'shipitall-marketplace' ),
			'manage_woocommerce',
			'shipitall-marketplace',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'shipitall-marketplace',
			__( 'Payouts', 'shipitall-marketplace' ),
			__( 'Payouts', 'shipitall-marketplace' ),
			'manage_woocommerce',
			'shipitall-payouts',
			array( __CLASS__, 'render_payouts_page' )
		);
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shipitall Marketplace Settings', 'shipitall-marketplace' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( Shipitall_Admin_Settings::OPTION_GROUP );
				do_settings_sections( Shipitall_Admin_Settings::OPTION_GROUP );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function render_payouts_page() {
		$summary = Shipitall_Payouts::get_pending_summary();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vendor Payouts', 'shipitall-marketplace' ); ?></h1>
			<p><?php esc_html_e( 'Pending commission owed to each vendor. Marking a vendor paid records the payout and clears their pending balance — pay them via EFT first, this just logs that it happened.', 'shipitall-marketplace' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vendor', 'shipitall-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Pending payout', 'shipitall-marketplace' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $summary ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No pending payouts.', 'shipitall-marketplace' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $summary as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row['pending'] ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="shipitall_mark_paid">
										<input type="hidden" name="vendor_id" value="<?php echo esc_attr( $row['vendor_id'] ); ?>">
										<?php wp_nonce_field( 'shipitall_mark_paid_' . $row['vendor_id'] ); ?>
										<button type="submit" class="button"><?php esc_html_e( 'Mark paid', 'shipitall-marketplace' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_mark_paid() {
		$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;

		check_admin_referer( 'shipitall_mark_paid_' . $vendor_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'shipitall-marketplace' ) );
		}

		if ( $vendor_id ) {
			Shipitall_Payouts::create_for_vendor( $vendor_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=shipitall-payouts&shipitall_paid=1' ) );
		exit;
	}
}
