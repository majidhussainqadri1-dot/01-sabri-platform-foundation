<?php
$root = dirname( __DIR__ );
$pass = 0;
$fail = static function ( $message ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); };
$expect = static function ( $condition, $message ) use ( &$pass, $fail ) { if ( ! $condition ) { $fail( $message ); } $pass++; };
$src = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . $file ); };

$control = $src( 'includes/class-spf-governance-control-plane.php' );
$expect( str_contains( $control, "true === ( \$item['design'] ?? false )" ) && str_contains( $control, "true === ( \$item['operational'] ?? false )" ), 'Round 1 strict traceability evidence truth missing' );
$engineering = $src( 'includes/class-spf-platform-engineering.php' );
$expect( str_contains( $engineering, "true === ( \$execution['verified'] ?? false )" ) && str_contains( $engineering, 'hash_equals( $release_id, $execution_release_id )' ), 'Round 2 strict deployment evidence binding missing' );
$expect( str_contains( $engineering, '$allowed_rings' ) && str_contains( $engineering, 'spf_rollout_terminal_state_invalid' ), 'Round 3 rollout taxonomy/terminal gate missing' );
$expect( str_contains( $engineering, 'spf_event_schema_registry_full' ), 'Round 4 event-schema capacity guard missing' );
$expect( str_contains( $control, 'shell_owner_missing' ) && ! str_contains( $control, '$shell_owners[] = \'file-20\';' ), 'Round 5 runtime shell-owner truth guard missing' );
$resilience = $src( 'includes/class-spf-resilience-lab.php' );
$expect( str_contains( $resilience, "true === SPF_CHAOS_MODE" ), 'Round 6 literal chaos-mode gate missing' );
$expect( str_contains( $resilience, 'spf_self_heal_rollback_failed' ) && str_contains( $resilience, '$recoveries_before' ), 'Round 7 compensating self-heal rollback missing' );
$expect( str_contains( $resilience, 'spf_future_foundation_tick_failure' ) && str_contains( $resilience, 'spf_future_foundation_metric_failed' ), 'Round 8 periodic-tick failure propagation missing' );
$system = $src( 'includes/class-spf-system-check.php' );
$expect( str_contains( $system, 'privacy_requests_query' ) && str_contains( $system, 'privacy_holds_query' ), 'Round 9 privacy health DB fail-closed guards missing' );
$expect( str_contains( $engineering, 'spf_scaffold_self_dependency' ), 'Round 10 Golden-Path self-dependency guard missing' );
printf( "Fifth ten-round review assertions: %d/%d PASS\n", $pass, 10 );
