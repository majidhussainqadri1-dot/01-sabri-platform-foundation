<?php

defined( 'ABSPATH' ) || exit;

final class SPF_Renderer {
	public static function posts( $mode = 'latest', $limit = 12 ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => absint( $limit ),
			'ignore_sticky_posts' => false,
			'no_found_rows'       => true,
		);

		if ( 'viral' === $mode ) {
			$args['orderby'] = array( 'comment_count' => 'DESC', 'date' => 'DESC' );
		} elseif ( 'founder' === $mode ) {
			$args['author']  = absint( get_option( 'spf_founder_user_id', 0 ) );
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		return get_posts( $args );
	}

	public static function module_descriptions() {
		return array(
			'news'         => 'Verified founder and approved doctor publications, clinical cases, research and platform updates.',
			'founder'      => 'Official founder profile, mission, publications, research and institutional guidance.',
			'learn'        => 'Structured lessons, books and foundational study material for classical homeopathy.',
			'encyclopedia' => 'Organized access to remedies, diseases, symptoms, causes, books and clinical knowledge.',
			'doctors'      => 'A public foundation for discovering approved doctors by country, language and professional focus.',
			'clinic'       => 'The foundation of global clinic profiles, consultation requests and appointment access.',
			'videos'       => 'Long-form educational videos, lectures, case discussions and clinical demonstrations.',
			'reels'        => 'Short educational videos presented in a focused, mobile-friendly viewing format.',
			'pdf'          => 'An organized reading area for books, research papers and educational documents.',
			'radar'        => 'A structured foundation for symptom and remedy search; advanced repertorization will be added in its dedicated module.',
			'ai'           => 'A dedicated knowledge-assistance area; it will not issue autonomous diagnoses or prescriptions.',
			'network'      => 'The foundation for professional connections, messages and doctor-patient contact.',
			'marketplace'  => 'A structured foundation for approved books, products, courses and professional services.',
		);
	}

	public static function template( $name, array $vars = array() ) {
		$path = SPF_DIR . 'templates/' . sanitize_file_name( $name ) . '.php';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}
}
