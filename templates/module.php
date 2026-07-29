<?php defined( 'ABSPATH' ) || exit; ?>
<div class="spf-platform spf-module-page" data-module="<?php echo esc_attr( $module_key ); ?>">
	<?php include SPF_DIR . 'templates/partials/navigation.php'; ?>
	<header class="spf-module-hero"><span class="spf-eyebrow">Sabri Social Homeopathy Platform</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></header>
	<section class="spf-section">
		<div class="spf-section-heading"><div><span>Foundation Content</span><h2>Latest Publications</h2></div></div>
		<?php if ( $posts ) : ?><div class="spf-card-grid"><?php foreach ( $posts as $post_item ) { include SPF_DIR . 'templates/partials/post-card.php'; } ?></div><?php else : ?><div class="spf-empty"><h3>This section is ready for its first content</h3><p>The dedicated module can now be expanded without changing the rest of the website.</p></div><?php endif; ?>
	</section>
</div>

