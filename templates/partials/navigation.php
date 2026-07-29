<?php defined( 'ABSPATH' ) || exit; ?>
<nav class="spf-top-nav" aria-label="Sabri platform navigation">
	<div class="spf-brand" aria-label="Sabri Social Homeopathy Platform">SH</div>
	<div class="spf-nav-scroll">
		<?php foreach ( $nav_items as $item ) : ?>
			<a class="spf-nav-link<?php echo ( isset( $module_key ) && $module_key === $item['key'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>">
				<?php echo esc_html( $item['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<div class="spf-account-actions">
		<?php if ( is_user_logged_in() ) : ?>
			<a href="<?php echo esc_url( get_edit_profile_url() ); ?>">My Profile</a>
		<?php else : ?>
			<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Log In</a>
			<?php if ( get_option( 'users_can_register' ) ) : ?>
				<a class="spf-register" href="<?php echo esc_url( wp_registration_url() ); ?>">Sign Up</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</nav>

