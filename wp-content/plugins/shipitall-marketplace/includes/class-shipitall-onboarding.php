<?php
/**
 * Vendor application: frontend submission + admin approval.
 *
 * Submission creates a draft shipitall_store post owned by the
 * applicant. Approval (admin only) publishes it and grants the
 * shipitall_vendor role — until then the applicant is just a regular
 * logged-in customer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Onboarding {

	public static function init() {
		add_shortcode( 'shipitall_become_a_vendor', array( __CLASS__, 'render_form' ) );
		add_action( 'admin_post_shipitall_vendor_apply', array( __CLASS__, 'handle_submission' ) );

		add_filter( 'post_row_actions', array( __CLASS__, 'add_approve_row_action' ), 10, 2 );
		add_action( 'admin_action_shipitall_approve_vendor', array( __CLASS__, 'handle_approve_from_row_action' ) );

		add_filter( 'bulk_actions-edit-shipitall_store', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shipitall_store', array( __CLASS__, 'handle_bulk_approve' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'approved_notice' ) );
	}

	public static function render_form() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to apply as a vendor.', 'shipitall-marketplace' ) . '</p>';
		}

		$existing = Shipitall_Store_CPT::get_store_for_owner( get_current_user_id() );
		if ( $existing ) {
			$status = 'publish' === $existing->post_status
				? esc_html__( 'Your store is approved. Visit your vendor dashboard to manage it.', 'shipitall-marketplace' )
				: esc_html__( 'Your application is in review. We\'ll email you once it\'s approved.', 'shipitall-marketplace' );
			return '<p>' . $status . '</p>';
		}

		ob_start();
		?>
		<form class="shipitall-vendor-application" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="shipitall_vendor_apply">
			<?php wp_nonce_field( 'shipitall_vendor_apply', 'shipitall_vendor_apply_nonce' ); ?>

			<p>
				<label for="shipitall_store_name"><?php esc_html_e( 'Store name', 'shipitall-marketplace' ); ?></label>
				<input type="text" id="shipitall_store_name" name="shipitall_store_name" required>
			</p>
			<p>
				<label for="shipitall_store_description"><?php esc_html_e( 'Tell us about what you sell', 'shipitall-marketplace' ); ?></label>
				<textarea id="shipitall_store_description" name="shipitall_store_description" rows="4" required></textarea>
			</p>
			<p>
				<button type="submit"><?php esc_html_e( 'Apply to sell on Shipitall', 'shipitall-marketplace' ); ?></button>
			</p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_submission() {
		if (
			! isset( $_POST['shipitall_vendor_apply_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shipitall_vendor_apply_nonce'] ) ), 'shipitall_vendor_apply' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'shipitall-marketplace' ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to apply.', 'shipitall-marketplace' ) );
		}

		$user_id = get_current_user_id();

		if ( Shipitall_Store_CPT::get_store_for_owner( $user_id ) ) {
			wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
			exit;
		}

		$store_name = isset( $_POST['shipitall_store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shipitall_store_name'] ) ) : '';
		$description = isset( $_POST['shipitall_store_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['shipitall_store_description'] ) ) : '';

		if ( '' === $store_name ) {
			wp_die( esc_html__( 'Store name is required.', 'shipitall-marketplace' ) );
		}

		$store_id = wp_insert_post(
			array(
				'post_type'    => Shipitall_Store_CPT::POST_TYPE,
				'post_title'   => $store_name,
				'post_content' => $description,
				'post_status'  => 'draft',
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $store_id ) ) {
			wp_die( esc_html( $store_id->get_error_message() ) );
		}

		update_post_meta( $store_id, '_shipitall_owner_id', $user_id );

		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}

	public static function approve( $store_id ) {
		$owner_id = Shipitall_Store_CPT::get_owner_id( $store_id );

		if ( ! $owner_id ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'          => $store_id,
				'post_status' => 'publish',
			)
		);

		$user = get_userdata( $owner_id );
		if ( $user ) {
			$user->add_role( Shipitall_Roles::ROLE );
		}

		return true;
	}

	public static function add_approve_row_action( $actions, $post ) {
		if ( Shipitall_Store_CPT::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_shipitall_stores' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'shipitall_approve_vendor',
					'post'   => $post->ID,
				),
				admin_url( 'edit.php' )
			),
			'shipitall_approve_vendor_' . $post->ID
		);

		$actions['shipitall_approve'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Approve vendor', 'shipitall-marketplace' ) . '</a>';

		return $actions;
	}

	public static function handle_approve_from_row_action() {
		$store_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		check_admin_referer( 'shipitall_approve_vendor_' . $store_id );

		if ( ! current_user_can( 'edit_shipitall_stores' ) ) {
			wp_die( esc_html__( 'You do not have permission to approve vendors.', 'shipitall-marketplace' ) );
		}

		self::approve( $store_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'         => Shipitall_Store_CPT::POST_TYPE,
					'shipitall_approved' => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public static function register_bulk_action( $actions ) {
		$actions['shipitall_approve_vendor'] = __( 'Approve vendor', 'shipitall-marketplace' );
		return $actions;
	}

	public static function handle_bulk_approve( $redirect_to, $action, $post_ids ) {
		if ( 'shipitall_approve_vendor' !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_shipitall_stores' ) ) {
			return $redirect_to;
		}

		$approved = 0;
		foreach ( $post_ids as $post_id ) {
			if ( self::approve( $post_id ) ) {
				++$approved;
			}
		}

		return add_query_arg( 'shipitall_approved', $approved, $redirect_to );
	}

	public static function approved_notice() {
		if ( empty( $_GET['shipitall_approved'] ) ) {
			return;
		}

		$count = absint( $_GET['shipitall_approved'] );
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of vendors approved */
					_n( '%d vendor approved.', '%d vendors approved.', $count, 'shipitall-marketplace' ),
					$count
				)
			)
		);
	}
}
