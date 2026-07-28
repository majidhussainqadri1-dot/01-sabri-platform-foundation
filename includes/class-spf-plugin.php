<?php

defined( 'ABSPATH' ) || exit;

final class SPF_Plugin {
	public function run() {
		add_shortcode( 'sabri_platform_home', array( $this, 'home_shortcode' ) );
		add_shortcode( 'sabri_platform_module', array( $this, 'module_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	public function enqueue_assets() {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! has_shortcode( $post->post_content, 'sabri_platform_home' ) && ! has_shortcode( $post->post_content, 'sabri_platform_module' ) ) {
			return;
		}

		wp_enqueue_style( 'spf-foundation', SPF_URL . 'assets/css/foundation.css', array(), SPF_VERSION );
		wp_enqueue_script( 'spf-foundation', SPF_URL . 'assets/js/foundation.js', array(), SPF_VERSION, true );
	}

	public function home_shortcode() {
		return SPF_Renderer::template(
			'home',
			array(
				'nav_items'    => $this->nav_items(),
				'viral_posts'  => SPF_Renderer::posts( 'viral', 4 ),
				'latest_posts' => SPF_Renderer::posts( 'latest', 12 ),
				'founder_posts'=> SPF_Renderer::posts( 'founder', 4 ),
			)
		);
	}

	public function module_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'key' => 'news' ), $atts, 'sabri_platform_module' );
		$key  = sanitize_key( $atts['key'] );
		$spec = SPF_Activator::page_specs();
		$desc = SPF_Renderer::module_descriptions();

		if ( ! isset( $spec[ $key ] ) || 'home' === $key ) {
			return '';
		}

		return SPF_Renderer::template(
			'module',
			array(
				'nav_items'  => $this->nav_items(),
				'module_key' => $key,
				'title'      => $spec[ $key ]['label'],
				'description'=> isset( $desc[ $key ] ) ? $desc[ $key ] : '',
				'posts'      => SPF_Renderer::posts( 'latest', 6 ),
			)
		);
	}

	private function nav_items() {
		$pages = (array) get_option( 'spf_page_map', array() );
		$items = array();
		foreach ( SPF_Activator::page_specs() as $key => $spec ) {
			$page_id = isset( $pages[ $key ] ) ? absint( $pages[ $key ] ) : 0;
			$items[] = array(
				'key'   => $key,
				'label' => $spec['label'],
				'url'   => $page_id ? get_permalink( $page_id ) : home_url( '/' . $spec['slug'] . '/' ),
			);
		}
		return $items;
	}

	public function admin_menu() {
		add_menu_page(
			'Sabri Platform Foundation',
			'Sabri Platform',
			'manage_options',
			'sabri-platform-foundation',
			array( $this, 'admin_page' ),
			'dashicons-networking',
			58
		);
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pages   = (array) get_option( 'spf_page_map', array() );
		$home_id = isset( $pages['home'] ) ? absint( $pages['home'] ) : 0;
		?>
		<div class="wrap">
			<h1>Sabri Platform Foundation</h1>
			<p>Version <?php echo esc_html( SPF_VERSION ); ?> provides the modular public home, news feed and central navigation foundation.</p>
			<?php if ( $home_id ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( get_permalink( $home_id ) ); ?>" target="_blank" rel="noopener">View Sabri Platform Home</a></p>
			<?php endif; ?>
			<p><strong>Safety:</strong> This plugin does not automatically replace the live homepage or modify the active theme.</p>
			<p><strong>Google Login:</strong> OAuth credentials and account completion will be connected in the dedicated Authentication module.</p>
		</div>
		<?php
	}

	public function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'spf_activation_notice' ) ) {
			return;
		}
		delete_transient( 'spf_activation_notice' );
		$pages = (array) get_option( 'spf_page_map', array() );
		$url   = isset( $pages['home'] ) ? get_permalink( absint( $pages['home'] ) ) : '';
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong>Sabri Platform Foundation activated.</strong>
			<?php if ( $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">View the new platform page</a> before making it the live homepage.
			<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
