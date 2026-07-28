<?php defined( 'ABSPATH' ) || exit; $module_key = 'home'; ?>
<div class="spf-platform" id="spf-platform-home">
	<?php include SPF_DIR . 'templates/partials/navigation.php'; ?>

	<div class="spf-home-controls" role="navigation" aria-label="Home content filters">
		<button type="button" class="is-active" data-spf-filter="all">For You</button>
		<button type="button" data-spf-filter="viral">Most Viral</button>
		<button type="button" data-spf-filter="latest">Latest</button>
		<a href="#spf-founder">Founder Posts</a><a href="#spf-news">Doctors Posts</a>
		<a href="#spf-news">Classical Learning</a><a href="#spf-news">Remedies</a>
		<a href="#spf-news">Diseases</a><a href="#spf-news">Clinical Cases</a>
		<a href="#spf-news">Videos</a><a href="#spf-news">Reels</a><a href="#spf-news">PDF Books</a>
	</div>

	<header class="spf-hero">
		<div><span class="spf-eyebrow">Sabri Social Homeopathy Platform</span><h1>Classical Homeopathy, Global Knowledge and Your Worldwide Clinic</h1><p>Read public knowledge without registration. Create an account to comment, publish and use personal services.</p></div>
		<form class="spf-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>"><label class="screen-reader-text" for="spf-search-input">Search</label><input id="spf-search-input" type="search" name="s" placeholder="Search posts and knowledge"><button type="submit">Search</button></form>
	</header>

	<?php if ( $viral_posts ) : ?>
	<section class="spf-section spf-content-group" data-spf-group="viral">
		<div class="spf-section-heading"><div><span>Trending</span><h2>Most Viral Now</h2></div><p>Ranked initially by public discussion and freshness.</p></div>
		<div class="spf-card-grid spf-card-grid-featured">
			<?php foreach ( $viral_posts as $post_item ) { include SPF_DIR . 'templates/partials/post-card.php'; } ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $founder_posts ) : ?>
	<section class="spf-section spf-content-group" id="spf-founder" data-spf-group="latest">
		<div class="spf-section-heading"><div><span>Official</span><h2>From the Founder</h2></div></div>
		<div class="spf-card-grid">
			<?php foreach ( $founder_posts as $post_item ) { include SPF_DIR . 'templates/partials/post-card.php'; } ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="spf-section spf-content-group" id="spf-news" data-spf-group="latest">
		<div class="spf-section-heading"><div><span>Public News Area</span><h2>Latest Publications</h2></div><p>Published founder and approved doctor content appears here automatically.</p></div>
		<?php if ( $latest_posts ) : ?>
			<div class="spf-card-grid">
				<?php foreach ( $latest_posts as $post_item ) { include SPF_DIR . 'templates/partials/post-card.php'; } ?>
			</div>
		<?php else : ?>
			<div class="spf-empty"><h3>No published posts yet</h3><p>Published WordPress posts will appear here automatically.</p></div>
		<?php endif; ?>
	</section>

	<footer class="spf-footer-note">Foundation Version <?php echo esc_html( SPF_VERSION ); ?> · Modular and ready for the next approved module.</footer>
</div>

