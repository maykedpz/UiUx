<?php
/**
 * Footer — Corpmerch.
 *
 * @package Corpmerch
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
</main>
<!-- /#cm-content -->

<footer class="cm-footer">
	<?php
	// Newsletter band — settings: news_on, news_heading, news_sub, news_action.
	$cm_news_on = function_exists( 'corpmerch_option' ) ? (bool) corpmerch_option( 'news_on', false ) : false;
	if ( $cm_news_on ) :
		$cm_news_h      = trim( (string) corpmerch_option( 'news_heading', __( 'Join the list', 'corpmerch' ) ) );
		$cm_news_sub    = trim( (string) corpmerch_option( 'news_sub', __( 'Deals & new arrivals, straight to your inbox.', 'corpmerch' ) ) );
		$cm_news_action = trim( (string) corpmerch_option( 'news_action', '' ) );
		$cm_news_attrs  = ( '' !== $cm_news_action ) ? ' action="' . esc_url( $cm_news_action ) . '" method="post" target="_blank" rel="noopener"' : '';
		?>
		<div class="cm-newsletter">
			<div class="cm-container cm-newsletter__inner">
				<div class="cm-newsletter__copy">
					<?php if ( '' !== $cm_news_h ) : ?>
						<h2 class="cm-newsletter__heading"><?php echo esc_html( $cm_news_h ); ?></h2>
					<?php endif; ?>
					<?php if ( '' !== $cm_news_sub ) : ?>
						<p class="cm-newsletter__sub"><?php echo esc_html( $cm_news_sub ); ?></p>
					<?php endif; ?>
				</div>
				<form class="cm-newsletter__form"<?php echo $cm_news_attrs; // phpcs:ignore WordPress.Security.EscapingOutput ?>>
					<label class="screen-reader-text" for="cm-news-email"><?php esc_html_e( 'Email address', 'corpmerch' ); ?></label>
					<input id="cm-news-email" class="cm-newsletter__input" type="email" name="EMAIL" placeholder="<?php esc_attr_e( 'your@email.com', 'corpmerch' ); ?>" required>
					<button class="cm-newsletter__btn" type="submit"><?php esc_html_e( 'Subscribe', 'corpmerch' ); ?></button>
				</form>
			</div>
		</div>
	<?php endif; ?>
	<div class="cm-container">
		<div class="cm-footer__grid">
			<div class="cm-footer__brand">
				<div class="cm-logo"><?php
				$cm_footer_logo = function_exists( 'corpmerch_logo_img' ) ? corpmerch_logo_img( 'cm-logo__img cm-logo__img--footer' ) : '';
				if ( $cm_footer_logo ) {
					echo $cm_footer_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo 'Corp<span>merch</span>';
				}
				?></div>
				<p style="margin-top:12px;max-width:34ch;color:#a8a29e;">
					<?php esc_html_e( 'Corporate merchandise, fully brandable. Add your logo to apparel, bags, drinkware and more.', 'corpmerch' ); ?>
				</p>
			</div>
			<?php
			for ( $i = 1; $i <= 3; $i++ ) :
				if ( ! is_active_sidebar( 'footer-' . $i ) ) {
					continue;
				}
				ob_start();
				dynamic_sidebar( 'footer-' . $i );
				$cm_widget_html = trim( (string) ob_get_clean() );
				if ( '' === $cm_widget_html ) {
					continue;
				}
				// Lift the first widget title out to use as the accordion label
				// (mirrors the named headings on the Shop/Contact/Policies columns).
				$cm_w_title = '';
				if ( preg_match( '/<(h[1-6])\b[^>]*>(.*?)<\/\1>/is', $cm_widget_html, $cm_m ) ) {
					$cm_w_title     = trim( wp_strip_all_tags( $cm_m[2] ) );
					$cm_widget_html = trim( str_replace( $cm_m[0], '', $cm_widget_html ) );
				}
				if ( '' === $cm_w_title ) {
					$cm_w_title = __( 'More', 'corpmerch' );
				}
				?>
				<div class="cm-footer__col cm-footer__col--widget">
					<button class="cm-footer__heading cm-footer__acc cm-footer__acc--widget" aria-expanded="false">
						<?php echo esc_html( $cm_w_title ); ?>
						<svg class="cm-footer__chev" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<div class="cm-footer__links cm-footer__widgetbody"><?php echo $cm_widget_html; // phpcs:ignore WordPress.Security.EscapingOutput ?></div>
				</div>
			<?php endfor; ?>
			<?php
			// Shop column — top-level categories (reuses the homepage pinned order).
			$cm_shop_html = '';
			$cm_fa        = function_exists( 'corpmerch_active_category' ) ? corpmerch_active_category() : array( 'term' => 0, 'top' => 0, 'shop' => false );
			if ( function_exists( 'corpmerch_home_categories' ) ) {
				$cm_cats = corpmerch_home_categories();
				$cm_n    = 0;
				foreach ( $cm_cats as $cm_c ) {
					if ( $cm_n++ >= 8 ) {
						break;
					}
					$cm_c_active   = ( (int) $cm_c->term_id === (int) $cm_fa['top'] );
					$cm_shop_html .= '<li><a' . ( $cm_c_active ? ' class="is-active" aria-current="page"' : '' ) . ' href="' . esc_url( get_term_link( $cm_c ) ) . '">' . esc_html( $cm_c->name ) . '</a></li>';
				}
				$cm_shop_url   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
				$cm_shop_html .= '<li><a' . ( $cm_fa['shop'] ? ' class="is-active" aria-current="page"' : '' ) . ' href="' . esc_url( $cm_shop_url ) . '">' . esc_html__( 'All products', 'corpmerch' ) . '</a></li>';
			}
			if ( '' !== $cm_shop_html ) :
				?>
				<div class="cm-footer__col">
					<button class="cm-footer__heading cm-footer__acc" aria-expanded="false">
						<?php esc_html_e( 'Shop', 'corpmerch' ); ?>
						<svg class="cm-footer__chev" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<ul class="cm-footer__links"><?php echo $cm_shop_html; // phpcs:ignore WordPress.Security.EscapingOutput ?></ul>
				</div>
			<?php endif; ?>
			<?php
			// Contact column — business details from settings.
			$cm_opt2     = function_exists( 'corpmerch_option' ) ? 'corpmerch_option' : '';
			$cm_c_addr   = $cm_opt2 ? trim( (string) corpmerch_option( 'footer_biz_address', '' ) ) : '';
			$cm_c_email  = $cm_opt2 ? trim( (string) corpmerch_option( 'footer_biz_email', '' ) ) : '';
			$cm_c_phone  = $cm_opt2 ? trim( (string) corpmerch_option( 'footer_biz_phone', '' ) ) : '';
			$cm_c_items  = '';
			if ( '' !== $cm_c_addr ) {
				$cm_c_items .= '<li>' . nl2br( esc_html( $cm_c_addr ) ) . '</li>';
			}
			if ( '' !== $cm_c_phone ) {
				$cm_c_items .= '<li><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $cm_c_phone ) ) . '">' . esc_html( $cm_c_phone ) . '</a></li>';
			}
			if ( '' !== $cm_c_email ) {
				$cm_c_items .= '<li><a href="mailto:' . esc_attr( $cm_c_email ) . '">' . esc_html( $cm_c_email ) . '</a></li>';
			}
			if ( '' !== $cm_c_items ) :
				?>
				<div class="cm-footer__col cm-footer__contact">
					<button class="cm-footer__heading cm-footer__acc" aria-expanded="false">
						<?php esc_html_e( 'Contact', 'corpmerch' ); ?>
						<svg class="cm-footer__chev" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<ul class="cm-footer__links"><?php echo $cm_c_items; // phpcs:ignore WordPress.Security.EscapingOutput ?></ul>
				</div>
			<?php endif; ?>
			<?php
			// Auto policy/support links — rendered from the pages the theme
			// creates and keeps in the footer_url_* options. Only links that
			// point to a real, published page are shown.
			$cm_footer_links = array(
				'footer_url_help'     => __( 'Help & Support', 'corpmerch' ),
				'footer_url_track'    => __( 'Track My Order', 'corpmerch' ),
				'footer_url_shipping' => __( 'Shipping Policy', 'corpmerch' ),
				'footer_url_returns'  => __( 'Returns & Refunds', 'corpmerch' ),
				'footer_url_contact'  => __( 'Contact Us', 'corpmerch' ),
				'footer_url_terms'    => __( 'Terms & Conditions', 'corpmerch' ),
				'footer_url_privacy'  => __( 'Privacy Policy', 'corpmerch' ),
				'footer_url_popia'    => __( 'POPIA Notice', 'corpmerch' ),
			);
			$cm_links_html = '';
			foreach ( $cm_footer_links as $cm_opt => $cm_label ) {
				$cm_url = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( $cm_opt, '' ) ) : '';
				if ( '' === $cm_url ) {
					continue;
				}
				$cm_links_html .= '<li><a href="' . esc_url( $cm_url ) . '">' . esc_html( $cm_label ) . '</a></li>';
			}
			if ( '' !== $cm_links_html ) :
				?>
				<div class="cm-footer__col cm-footer__policies">
					<button class="cm-footer__heading cm-footer__acc" aria-expanded="false">
						<?php esc_html_e( 'Help & Policies', 'corpmerch' ); ?>
						<svg class="cm-footer__chev" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<ul class="cm-footer__links"><?php echo $cm_links_html; // phpcs:ignore WordPress.Security.EscapingOutput ?></ul>
				</div>
			<?php endif; ?>
		</div>
		<div class="cm-footer__bottom">
			<?php
			$cm_socials = array(
				'seo_linkedin'  => __( 'LinkedIn', 'corpmerch' ),
				'seo_facebook'  => __( 'Facebook', 'corpmerch' ),
				'seo_instagram' => __( 'Instagram', 'corpmerch' ),
				'seo_x'         => __( 'X', 'corpmerch' ),
				'seo_youtube'   => __( 'YouTube', 'corpmerch' ),
				'seo_tiktok'    => __( 'TikTok', 'corpmerch' ),
			);
			$cm_social_icons = array(
				'seo_linkedin'  => '<path d="M20.45 20.45h-3.55v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.36V9h3.41v1.56h.05c.47-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 110-4.12 2.06 2.06 0 010 4.12zM7.12 20.45H3.55V9h3.57v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.22.79 24 1.77 24h20.45c.98 0 1.78-.78 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/>',
				'seo_facebook'  => '<path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.01 10.12 11.93v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.08 24 18.09 24 12.07z"/>',
				'seo_instagram' => '<path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 01-1.38-.9 3.72 3.72 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.13 1.38C1.35 2.68.93 3.35.63 4.14c-.3.76-.5 1.64-.56 2.91C.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.79.72 1.46 1.38 2.13.67.66 1.34 1.08 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.3 1.46-.72 2.13-1.38.66-.67 1.08-1.34 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.3-.79-.72-1.46-1.38-2.13C21.32 1.35 20.65.93 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 100 12.32 6.16 6.16 0 000-12.32zM12 16a4 4 0 110-8 4 4 0 010 8zm6.41-10.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/>',
				'seo_x'         => '<path d="M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.41l-5.8-7.58-6.64 7.58H.46l8.6-9.83L0 1.15h7.6l5.24 6.93 6.06-6.93zm-1.29 19.5h2.04L6.49 3.24H4.3l13.31 17.41z"/>',
				'seo_youtube'   => '<path d="M23.5 6.2a3.02 3.02 0 00-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.51A3.02 3.02 0 00.5 6.2C0 8.08 0 12 0 12s0 3.92.5 5.8a3.02 3.02 0 002.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 002.12-2.14C24 15.92 24 12 24 12s0-3.92-.5-5.8zM9.6 15.6V8.4l6.27 3.6-6.27 3.6z"/>',
				'seo_tiktok'    => '<path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.89-4.59V9.4a6.33 6.33 0 00-5.4 10.86 6.33 6.33 0 0010.86-4.43V8.69a8.18 8.18 0 004.77 1.52V6.69z"/>',
			);
			$cm_social_html = '';
			foreach ( $cm_socials as $cm_sk => $cm_sl ) {
				$cm_su = function_exists( 'corpmerch_option' ) ? trim( (string) corpmerch_option( $cm_sk, '' ) ) : '';
				if ( '' === $cm_su ) {
					continue;
				}
				$cm_icon         = isset( $cm_social_icons[ $cm_sk ] ) ? $cm_social_icons[ $cm_sk ] : '';
				$cm_social_html .= '<a href="' . esc_url( $cm_su ) . '" rel="noopener" target="_blank" aria-label="' . esc_attr( $cm_sl ) . '" title="' . esc_attr( $cm_sl ) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">' . $cm_icon . '</svg></a>';
			}
			if ( '' !== $cm_social_html ) {
				echo '<nav class="cm-footer__social" aria-label="' . esc_attr__( 'Social media', 'corpmerch' ) . '">' . $cm_social_html . '</nav>'; // phpcs:ignore WordPress.Security.EscapingOutput
			}

			// Accepted-payment image (attachment ID stored in footer_payment_image).
			$cm_pay_id  = function_exists( 'corpmerch_option' ) ? (int) corpmerch_option( 'footer_payment_image', 0 ) : 0;
			$cm_pay_url = $cm_pay_id ? wp_get_attachment_image_url( $cm_pay_id, 'full' ) : '';
			if ( $cm_pay_url ) {
				echo '<div class="cm-footer__pay"><img src="' . esc_url( $cm_pay_url ) . '" alt="' . esc_attr__( 'Accepted payment methods', 'corpmerch' ) . '" loading="lazy" decoding="async"></div>';
			}
			?>
			<span>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'corpmerch' ); ?></span>
			<?php
			if ( has_nav_menu( 'footer' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'cm-footer__menu',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
			}
			?>
		</div>
	</div>
</footer>

	</div><!-- /.cm-shell__main -->
</div><!-- /.cm-shell -->

<?php wp_footer(); ?>
</body>
</html>
