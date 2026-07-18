<?php
/**
 * Settings API registration for the global commission rate.
 * Rendering the settings page itself lives in Shipitall_Admin_Menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipitall_Admin_Settings {

	const OPTION_GROUP = 'shipitall_marketplace_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Shipitall_Commissions::DEFAULT_RATE_OPTION,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( __CLASS__, 'sanitize_rate' ),
				'default'           => 10,
			)
		);

		add_settings_section(
			'shipitall_commission_section',
			__( 'Commission', 'shipitall-marketplace' ),
			'__return_false',
			self::OPTION_GROUP
		);

		add_settings_field(
			Shipitall_Commissions::DEFAULT_RATE_OPTION,
			__( 'Default commission rate (%)', 'shipitall-marketplace' ),
			array( __CLASS__, 'render_rate_field' ),
			self::OPTION_GROUP,
			'shipitall_commission_section'
		);
	}

	public static function sanitize_rate( $value ) {
		$value = (float) $value;
		return max( 0, min( 100, $value ) );
	}

	public static function render_rate_field() {
		printf(
			'<input type="number" step="0.1" min="0" max="100" name="%1$s" value="%2$s"> %%
			<p class="description">%3$s</p>',
			esc_attr( Shipitall_Commissions::DEFAULT_RATE_OPTION ),
			esc_attr( Shipitall_Commissions::get_default_rate() ),
			esc_html__( 'Applied to every vendor unless a store has its own override.', 'shipitall-marketplace' )
		);
	}
}
