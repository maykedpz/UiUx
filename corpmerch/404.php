<?php
/**
 * 404 — Corpmerch.
 *
 * @package Corpmerch
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<div class="cm-container cm-section" style="text-align:center;">
	<p class="cm-eyebrow"><?php esc_html_e( 'Error 404', 'corpmerch' ); ?></p>
	<h1><?php esc_html_e( 'That page has wandered off.', 'corpmerch' ); ?></h1>
	<p style="color:var(--cm-ink-soft);max-width:46ch;margin-inline:auto;"><?php esc_html_e( 'The page you were looking for is not here. Browse the catalogue instead.', 'corpmerch' ); ?></p>
	<p style="margin-top:24px;"><a class="cm-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'corpmerch' ); ?></a></p>
</div>
<?php
get_footer();
