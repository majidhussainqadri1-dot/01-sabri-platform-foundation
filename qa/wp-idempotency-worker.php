<?php
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'POST', '/sabri-foundation/v1/concurrency-probe' );
$request->set_header( 'X-Idempotency-Key', 'concurrency-probe-key-0001' );
$request->set_body_params( [ 'probe'=>1 ] );
$result = SPF_Idempotency::execute( $request, 'concurrency_probe', static function () {
	usleep( 750000 );
	$count = (int) get_option( 'spf_concurrency_counter', 0 );
	update_option( 'spf_concurrency_counter', $count + 1, false );
	return [ 'count'=>$count+1 ];
} );
if ( is_wp_error( $result ) ) {
	echo 'ERROR:' . $result->get_error_code() . "\n";
} else {
	echo "OK\n";
}
