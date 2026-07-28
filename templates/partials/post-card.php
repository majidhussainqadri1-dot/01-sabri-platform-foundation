<?php defined( 'ABSPATH' ) || exit; ?>
<article class="spf-post-card" data-comments="<?php echo esc_attr( get_comments_number( $post_item->ID ) ); ?>" data-date="<?php echo esc_attr( get_post_time( 'U', true, $post_item ) ); ?>">
	<a class="spf-card-media" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( has_post_thumbnail( $post_item ) ) : ?>
			<?php echo get_the_post_thumbnail( $post_item, 'medium_large', array( 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<span class="spf-media-placeholder">SH</span>
		<?php endif; ?>
	</a>
	<div class="spf-card-body">
		<div class="spf-author-row">
			<?php echo get_avatar( (int) $post_item->post_author, 34, '', '', array( 'class' => 'spf-avatar' ) ); ?>
			<div><strong><?php echo esc_html( get_the_author_meta( 'display_name', (int) $post_item->post_author ) ); ?></strong><span><?php echo esc_html( get_the_date( '', $post_item ) ); ?></span></div>
		</div>
		<h3><a href="<?php echo esc_url( get_permalink( $post_item ) ); ?>"><?php echo esc_html( get_the_title( $post_item ) ); ?></a></h3>
		<p><?php echo esc_html( wp_trim_words( get_the_excerpt( $post_item ), 24 ) ); ?></p>
		<div class="spf-card-actions">
			<a href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">Read More</a>
			<span><?php echo esc_html( get_comments_number( $post_item->ID ) ); ?> Comments</span>
		</div>
	</div>
</article>

