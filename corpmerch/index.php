<?php
/**
 * Fallback template — Corpmerch.
 *
 * @package Corpmerch
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<div class="cm-container cm-section">
	<?php if ( have_posts() ) : ?>
		<div class="cm-posts">
			<?php while ( have_posts() ) : the_post(); ?>
				<article <?php post_class( 'cm-post' ); ?>>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<div class="cm-post__excerpt"><?php the_excerpt(); ?></div>
				</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing found.', 'corpmerch' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
