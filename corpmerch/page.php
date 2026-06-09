<?php
/**
 * Page template — Corpmerch.
 *
 * @package Corpmerch
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<div class="cm-container cm-section">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class(); ?>>
			<h1><?php the_title(); ?></h1>
			<div class="cm-entry"><?php the_content(); ?></div>
		</article>
	<?php endwhile; ?>
</div>
<?php
get_footer();
