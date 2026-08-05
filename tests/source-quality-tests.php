<?php
declare(strict_types=1);
$root = dirname( __DIR__ );
$files = [];
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( $file->isFile() && in_array( $file->getExtension(), [ 'php','md','txt','yml','json','sh' ], true ) ) {
		$path = $file->getPathname();
		if ( str_contains( $path, DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR ) || str_contains( $path, DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR ) ) { continue; }
		$files[ str_replace( $root.DIRECTORY_SEPARATOR, '', $path ) ] = file_get_contents( $path );
	}
}
$runtime = implode( "\n", array_filter( $files, static fn( $k ) => ! str_starts_with( $k, 'tests/' ) && ! str_starts_with( $k, 'qa/' ), ARRAY_FILTER_USE_KEY ) );
$assertions=0; $failures=[];
$assert=static function(bool $c,string $m)use(&$assertions,&$failures){$assertions++;if(!$c)$failures[]=$m;};

$assert( ! str_contains( $runtime, "'system_seed'" ), 'Spoofable system_seed context remains.' );
$assert( ! preg_match( '/add_cap\s*\(\s*self::CAP_(?:RELEASE|FOUNDER|PURGE)/', $runtime ), 'Generic Administrator receives a sensitive capability.' );
$assert( ! preg_match( '/current_user_can\s*\(\s*[\'"]manage_options/', $runtime ), 'manage_options permissive authorization fallback remains.' );
$assert( ! str_contains( $files['includes/class-spf-rest.php']??'', "'/purge/apply'" ), 'Destructive purge is exposed over REST.' );
$assert( ! preg_match( '/strlen\s*\(\s*\$backup_reference/', $files['includes/class-spf-purge.php']??'' ), 'Purge still treats a string as backup proof.' );
$assert( str_contains( $files['includes/class-spf-purge.php']??'', 'spf_verify_backup_restore_evidence' ), 'Structured backup/restore evidence gate missing.' );
$assert( str_contains( $files['includes/class-spf-purge.php']??'', 'spf_verify_file24_purge_assurance' ), 'File 24 purge assurance gate missing.' );
$assert( ! preg_match( "/'software_version'\s*=>\s*'0\.0\.0'/", $runtime ), 'Placeholder 0.0.0 manifests remain.' );
$assert( str_contains( $files['includes/class-spf-idempotency.php']??'', "'status'          => 'processing'" ), 'Atomic idempotency reservation missing.' );
$assert( str_contains( $files['includes/class-spf-governance.php']??'', "'planned' !== \$status" ), 'Release initial-state restriction missing.' );
$assert( str_contains( $files['includes/class-spf-governance.php']??'', 'expected_record_version' ), 'Release optimistic record version missing.' );
$assert( str_contains( $files['includes/class-spf-privacy.php']??'', 'were retained under the approved governance purpose' ), 'Truthful immutable-audit privacy handling missing.' );
$assert( ! preg_match( "/array\( 'audit','release_states'.*actor_id'=>0/s", $files['includes/class-spf-privacy.php']??'' ), 'Privacy erasure mutates append-only actor facts.' );
$assert( str_contains( $files['includes/class-spf-installer.php']??'', 'CREATE TABLE {$shadow} LIKE {$table}' ), 'Activation/upgrade shadow snapshot missing.' );
$assert( str_contains( $files['includes/class-spf-reconciler.php']??'', 'spf_execute_owner_reconciliation' ), 'Canonical owner reconciliation adapter missing.' );
$assert( str_contains( $files['includes/class-spf-reconciler.php']??'', "'values'=>\$exists?get_post_meta" ), 'Exact pre-existing metadata snapshot missing.' );
$assert( str_contains( $files['tools/build-package.sh']??'', 'sabri-platform-foundation-01' ) || ! isset( $files['tools/build-package.sh'] ), 'Canonical package folder is incorrect.' );
$assert( ! preg_match( '/eval\s*\(|base64_decode\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(/', $runtime ), 'Dangerous execution primitive found.' );
$assert( ! preg_match( '/update_option\s*\(\s*[\'"]spf_founder_user_id[\'"]\s*,\s*get_current_user_id/i', $runtime ), 'Activating-user Founder assignment remains.' );
$assert( ! preg_match( '/wp_insert_post\s*\(/', $runtime ), 'File 01 silently creates public pages/posts.' );
$assert( ! preg_match( '/get_posts\s*\(/', $runtime ), 'File 01 owns a feed/post query.' );

if($failures){fwrite(STDERR,"Source-quality tests failed:\n- ".implode("\n- ",$failures)."\n");exit(1);} echo "Source-quality assertions: {$assertions}/{$assertions} PASS\n";
