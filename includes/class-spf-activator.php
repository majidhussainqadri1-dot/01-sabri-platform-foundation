<?php

defined( 'ABSPATH' ) || exit;

final class SPF_Activator {
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$pages = array();
		foreach ( self::page_specs() as $key => $spec ) {
			$existing = get_page_by_path( $spec['slug'], OBJECT, 'page' );
			if ( $existing instanceof WP_Post ) {
				$pages[ $key ] = (int) $existing->ID;
				continue;
			}

			$content = 'home' === $key
				? '[sabri_platform_home]'
				: sprintf( '[sabri_platform_module key="%s"]', sanitize_key( $key ) );

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $spec['label'],
					'post_name'    => $spec['slug'],
					'post_content' => $content,
					'meta_input'   => array( '_spf_managed_page' => '1' ),
				),
				true
			);

			if ( ! is_wp_error( $page_id ) ) {
				$pages[ $key ] = (int) $page_id;
			}
		}

		update_option( 'spf_page_map', $pages, false );
		update_option( 'spf_founder_user_id', get_current_user_id(), false );
		update_option( 'spf_version', SPF_VERSION, false );
		set_transient( 'spf_activation_notice', '1', 120 );
		flush_rewrite_rules();
	}

	public static function page_specs() {
		return array(
			'home'          => array( 'label' => 'Home', 'slug' => 'sabri-platform-home' ),
			'news'          => array( 'label' => 'News', 'slug' => 'sabri-news' ),
			'founder'       => array( 'label' => 'Founder', 'slug' => 'sabri-founder' ),
			'learn'         => array( 'label' => 'Learn Sabri Classical Homeopathy', 'slug' => 'learn-sabri-classical-homeopathy' ),
			'encyclopedia'  => array( 'label' => 'Encyclopedia', 'slug' => 'homeopathy-encyclopedia' ),
			'doctors'       => array( 'label' => 'Doctors', 'slug' => 'homeopathy-doctors' ),
			'clinic'        => array( 'label' => 'Worldwide Clinic', 'slug' => 'worldwide-clinic' ),
			'videos'        => array( 'label' => 'Video Wall', 'slug' => 'video-wall' ),
			'reels'         => array( 'label' => 'Reels', 'slug' => 'reels' ),
			'pdf'           => array( 'label' => 'PDF Library', 'slug' => 'pdf-library' ),
			'radar'         => array( 'label' => 'Radar', 'slug' => 'homeopathy-radar' ),
			'ai'            => array( 'label' => 'Sabri Classical Homeopathy AI', 'slug' => 'sabri-classical-homeopathy-ai' ),
			'network'       => array( 'label' => 'Network', 'slug' => 'homeopathy-network' ),
			'marketplace'   => array( 'label' => 'Marketplace', 'slug' => 'homeopathy-marketplace' ),
		);
	}
}

