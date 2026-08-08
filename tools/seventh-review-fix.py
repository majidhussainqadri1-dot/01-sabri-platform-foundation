from pathlib import Path
import subprocess

ROOT = Path('.')
BRANCH = 'codex/file-01-complete-foundation-1.0.0'


def read(path):
    return (ROOT / path).read_text()


def write(path, text):
    (ROOT / path).write_text(text)


def replace_once(path, old, new):
    text = read(path)
    if old not in text:
        raise SystemExit(f'Expected patch anchor missing in {path}: {old[:140]!r}')
    write(path, text.replace(old, new, 1))


def sh(cmd):
    print('+', cmd, flush=True)
    subprocess.run(cmd, shell=True, check=True)


def regression(files):
    for file in files:
        sh(f"php -l {file} >/dev/null")
    sh('php tests/unit-tests.php')
    sh('php tests/future-foundation-tests.php')
    sh('php tests/sixth-ten-round-review-tests.php')
    sh('git diff --check')


def commit_round(round_no, message, files):
    regression(files)
    sh('git add ' + ' '.join(files))
    sh('git diff --cached --check')
    sh(f"git commit -m '[seventh review R{round_no}] {message}'")
    sh(f'git push origin HEAD:{BRANCH}')


def round1():
    p = 'includes/class-spf-runtime.php'
    replace_once(
        p,
        "\t\tif ( is_array( $current ) && isset( $current['created'], $current['token'] ) && wp_is_uuid( (string) $current['token'] ) && $current_expires > 0 && time() > $current_expires ) {",
        "\t\tif ( is_array( $current ) && isset( $current['created'], $current['token'] ) && wp_is_uuid( (string) $current['token'] ) && $current_expires > 0 && time() >= $current_expires ) {",
    )
    replace_once(
        p,
        "\tpublic static function table_exists( $table ) {\n\t\tglobal $wpdb;\n\t\treturn $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;\n\t}\n",
        "\tpublic static function table_exists( $table ) {\n\t\tglobal $wpdb;\n\t\t$table = is_string( $table ) ? trim( $table ) : '';\n\t\tif ( '' === $table ) {\n\t\t\treturn false;\n\t\t}\n\t\t$found = $wpdb->get_var(\n\t\t\t$wpdb->prepare(\n\t\t\t\t'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',\n\t\t\t\t$table\n\t\t\t)\n\t\t);\n\t\treturn is_string( $found ) && hash_equals( $table, $found );\n\t}\n",
    )
    commit_round(1, 'Make table identity and lock expiry exact', [p])


def round2():
    p = 'includes/class-spf-registry.php'
    old = """\t\tforeach ( array( 'capabilities','commands','queries','events','routes','data_classes' ) as $field ) {
\t\t\tif ( count( $manifest[ $field ] ) > 256 ) {
\t\t\t\treturn new WP_Error( 'spf_manifest_collection_too_large', sprintf( __( 'Manifest collection is too large: %s', 'sabri-platform-foundation' ), $field ) );
\t\t\t}
\t\t\t$manifest[ $field ] = array_values( array_unique( array_filter( array_map( static function ( $v ) { return substr( sanitize_text_field( (string) $v ), 0, 191 ); }, $manifest[ $field ] ) ) ) );
\t\t}
"""
    new = """\t\tforeach ( array( 'capabilities','commands','queries','events','routes','data_classes' ) as $field ) {
\t\t\tif ( count( $manifest[ $field ] ) > 256 ) {
\t\t\t\treturn new WP_Error( 'spf_manifest_collection_too_large', sprintf( __( 'Manifest collection is too large: %s', 'sabri-platform-foundation' ), $field ) );
\t\t\t}
\t\t\t$normalized_values = array();
\t\t\t$seen_values = array();
\t\t\tforeach ( $manifest[ $field ] as $value ) {
\t\t\t\tif ( ! is_scalar( $value ) ) {
\t\t\t\t\treturn new WP_Error( 'spf_manifest_collection_invalid', sprintf( __( 'Manifest collection contains a non-scalar value: %s', 'sabri-platform-foundation' ), $field ) );
\t\t\t\t}
\t\t\t\t$normalized_value = substr( sanitize_text_field( (string) $value ), 0, 191 );
\t\t\t\tif ( '' === $normalized_value ) {
\t\t\t\t\treturn new WP_Error( 'spf_manifest_collection_invalid', sprintf( __( 'Manifest collection contains an empty or invalid value: %s', 'sabri-platform-foundation' ), $field ) );
\t\t\t\t}
\t\t\t\tif ( isset( $seen_values[ $normalized_value ] ) ) {
\t\t\t\t\treturn new WP_Error( 'spf_manifest_collection_duplicate', sprintf( __( 'Manifest collection contains a duplicate canonical value: %s', 'sabri-platform-foundation' ), $field ) );
\t\t\t\t}
\t\t\t\t$seen_values[ $normalized_value ] = true;
\t\t\t\t$normalized_values[] = $normalized_value;
\t\t\t}
\t\t\t$manifest[ $field ] = $normalized_values;
\t\t}
"""
    replace_once(p, old, new)
    old = "\t\t$consumers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $contract['consumers'] ) ) ) );\n\t\t$schema_json = SPF_Runtime::canonical_json( $contract['schema'] );\n\t\tif ( count( $consumers ) > 64 || count( $contract['schema'] ) > 256 || false === $schema_json || strlen( $schema_json ) > 262144 ) {"
    new = """\t\tif ( count( $contract['consumers'] ) > 64 ) {
\t\t\treturn new WP_Error( 'spf_contract_too_large', __( 'Contract schema or consumer list exceeds the bounded contract envelope.', 'sabri-platform-foundation' ) );
\t\t}
\t\t$consumers = array();
\t\t$seen_consumers = array();
\t\tforeach ( $contract['consumers'] as $raw_consumer ) {
\t\t\tif ( ! is_scalar( $raw_consumer ) ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_contract_consumer', __( 'Contract consumers must be canonical module keys.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$raw_consumer = trim( (string) $raw_consumer );
\t\t\t$consumer = sanitize_key( $raw_consumer );
\t\t\tif ( '' === $consumer || $raw_consumer !== $consumer || ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $consumer ) ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_contract_consumer', __( 'Contract consumer is not a canonical module key.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\tif ( isset( $seen_consumers[ $consumer ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_duplicate_contract_consumer', __( 'A contract consumer may be declared only once.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$seen_consumers[ $consumer ] = true;
\t\t\t$consumers[] = $consumer;
\t\t}
\t\t$schema_json = SPF_Runtime::canonical_json( $contract['schema'] );
\t\tif ( count( $contract['schema'] ) > 256 || false === $schema_json || strlen( $schema_json ) > 262144 ) {"""
    replace_once(p, old, new)
    old = """\t\t$redirects = array();
\t\tforeach ( $raw_redirects as $redirect ) {
\t\t\t$redirect = '/' . trim( sanitize_text_field( $redirect ), '/' ) . '/';
\t\t\tif ( preg_match( '#^/[A-Za-z0-9/_-]+/$#', $redirect ) && $redirect !== $path ) {
\t\t\t\t$redirects[] = $redirect;
\t\t\t}
\t\t}
"""
    new = """\t\t$redirects = array();
\t\t$seen_redirects = array();
\t\tforeach ( $raw_redirects as $redirect ) {
\t\t\tif ( ! is_scalar( $redirect ) ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_route_redirect', __( 'Every route redirect must be a canonical relative path.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$redirect = '/' . trim( sanitize_text_field( (string) $redirect ), '/' ) . '/';
\t\t\t$redirect = preg_replace( '#/+#', '/', $redirect );
\t\t\tif ( ! preg_match( '#^/[A-Za-z0-9/_-]+/$#', $redirect ) || $redirect === $path ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_route_redirect', __( 'Every route redirect must be a distinct canonical relative path.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\tif ( isset( $seen_redirects[ $redirect ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_duplicate_route_redirect', __( 'A route redirect may be declared only once.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$seen_redirects[ $redirect ] = true;
\t\t\t$redirects[] = $redirect;
\t\t}
"""
    replace_once(p, old, new)
    commit_round(2, 'Reject silent registry normalization loss', [p])


def round3():
    p = 'includes/class-spf-system-check.php'
    old = """\t\t$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
\t\t$external_cron = apply_filters( 'spf_external_cron_evidence', null, array( 'hooks'=>array('spf_dispatch_outbox','spf_privacy_retention','spf_reconcile_expired_flags','spf_future_foundation_tick') ) );
\t\t$cron_ok = ! $cron_disabled || ( is_array($external_cron) && array_key_exists('verified',$external_cron) && true===$external_cron['verified'] );
\t\t$checks[] = self::check( 'cron_runner', $cron_ok, $cron_disabled ? ( $cron_ok ? 'external-verified' : 'disabled-unverified' ) : 'wp-cron', 'WP-Cron is disabled without verified external scheduler evidence.', 'fail' );
\t\t$expected_schedules = array(
\t\t\t'spf_dispatch_outbox'          => 'spf_five_minutes',
\t\t\t'spf_privacy_retention'       => 'daily',
\t\t\t'spf_reconcile_expired_flags' => 'hourly',
\t\t\t'spf_future_foundation_tick'  => 'spf_five_minutes',
\t\t);
"""
    new = """\t\t$expected_schedules = array(
\t\t\t'spf_dispatch_outbox'          => 'spf_five_minutes',
\t\t\t'spf_privacy_retention'       => 'daily',
\t\t\t'spf_reconcile_expired_flags' => 'hourly',
\t\t\t'spf_future_foundation_tick'  => 'spf_five_minutes',
\t\t);
\t\t$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
\t\t$external_cron = null;
\t\t$cron_ok = ! $cron_disabled;
\t\tif ( $cron_disabled ) {
\t\t\t$required_hooks = array_keys( $expected_schedules );
\t\t\t$external_cron = apply_filters( 'spf_external_cron_evidence', null, array( 'hooks'=>$required_hooks ) );
\t\t\t$reported_hooks = is_array( $external_cron ) && is_array( $external_cron['hooks'] ?? null ) ? array_values( array_unique( array_map( 'sanitize_key', $external_cron['hooks'] ) ) ) : array();
\t\t\t$verified_at = is_array( $external_cron ) ? strtotime( (string) ( $external_cron['verified_at'] ?? '' ) ) : false;
\t\t\t$expires_at = is_array( $external_cron ) ? strtotime( (string) ( $external_cron['expires_at'] ?? '' ) ) : false;
\t\t\t$cron_ok = is_array( $external_cron )
\t\t\t\t&& true === ( $external_cron['verified'] ?? false )
\t\t\t\t&& '' !== trim( (string) ( $external_cron['scheduler_id'] ?? '' ) )
\t\t\t\t&& '' !== trim( (string) ( $external_cron['verifier'] ?? '' ) )
\t\t\t\t&& $verified_at && $verified_at <= time() + 60 && $verified_at >= time() - 900
\t\t\t\t&& $expires_at && $expires_at > time()
\t\t\t\t&& empty( array_diff( $required_hooks, $reported_hooks ) );
\t\t}
\t\t$checks[] = self::check( 'cron_runner', $cron_ok, $cron_disabled ? ( $cron_ok ? 'external-verified' : 'disabled-unverified' ) : 'wp-cron', 'WP-Cron is disabled without fresh, complete, expiring external scheduler evidence.', 'fail' );
"""
    replace_once(p, old, new)
    commit_round(3, 'Require complete fresh external cron evidence', [p])


def round4():
    p = 'includes/class-spf-event-bus.php'
    replace_once(
        p,
        "\t\t$payload = self::sanitize_payload( $payload );\n\t\t$payload_json = wp_json_encode( $payload );",
        "\t\t$payload = self::sanitize_payload( $payload );\n\t\tif ( is_wp_error( $payload ) ) {\n\t\t\treturn $payload;\n\t\t}\n\t\t$payload_json = wp_json_encode( $payload );",
    )
    old = """\tprivate static function sanitize_payload( array $payload, $depth = 0 ) {
\t\tif ( $depth > 5 ) {
\t\t\treturn array( '_truncated' => true );
\t\t}
\t\t$result = array();
\t\tforeach ( array_slice( $payload, 0, 100, true ) as $key => $value ) {
\t\t\t$key = substr( sanitize_key( (string) $key ), 0, 128 );
\t\t\tif ( '' === $key ) {
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( preg_match( '/password|token|secret|authorization|cookie|nonce|patient|message|payment|identity|document|credential|private|key/i', $key ) ) {
\t\t\t\t$result[ $key ] = '[redacted]';
\t\t\t} elseif ( is_array( $value ) ) {
\t\t\t\t$result[ $key ] = self::sanitize_payload( $value, $depth + 1 );
\t\t\t} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
\t\t\t\t$result[ $key ] = $value;
\t\t\t} elseif ( is_scalar( $value ) ) {
\t\t\t\t$result[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 1000 );
\t\t\t} else {
\t\t\t\t$result[ $key ] = '[unsupported]';
\t\t\t}
\t\t}
\t\tif ( count( $payload ) > 100 ) {
\t\t\t$result['_truncated'] = true;
\t\t}
\t\treturn SPF_Runtime::canonicalize( $result );
\t}
"""
    new = """\tprivate static function sanitize_payload( array $payload, $depth = 0 ) {
\t\tif ( $depth > 5 ) {
\t\t\treturn new WP_Error( 'spf_event_payload_too_deep', __( 'Event payload nesting exceeds the bounded contract envelope.', 'sabri-platform-foundation' ) );
\t\t}
\t\tif ( count( $payload ) > 100 ) {
\t\t\treturn new WP_Error( 'spf_event_payload_too_many_fields', __( 'Event payload fields exceed the bounded contract envelope.', 'sabri-platform-foundation' ) );
\t\t}
\t\t$result = array();
\t\tforeach ( $payload as $key => $value ) {
\t\t\t$raw_key = (string) $key;
\t\t\t$safe_key = substr( sanitize_key( $raw_key ), 0, 128 );
\t\t\tif ( '' === $safe_key || $raw_key !== $safe_key || array_key_exists( $safe_key, $result ) ) {
\t\t\t\treturn new WP_Error( 'spf_event_payload_key_invalid', __( 'Event payload keys must already be unique canonical keys.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\tif ( preg_match( '/(^|_)(password|token|secret|authorization|cookie|nonce|patient|message|payment|identity|document|credential|private_key|api_key|encryption_key)($|_)/i', $safe_key ) ) {
\t\t\t\t$result[ $safe_key ] = '[redacted]';
\t\t\t} elseif ( is_array( $value ) ) {
\t\t\t\t$nested = self::sanitize_payload( $value, $depth + 1 );
\t\t\t\tif ( is_wp_error( $nested ) ) {
\t\t\t\t\treturn $nested;
\t\t\t\t}
\t\t\t\t$result[ $safe_key ] = $nested;
\t\t\t} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
\t\t\t\t$result[ $safe_key ] = $value;
\t\t\t} elseif ( is_scalar( $value ) ) {
\t\t\t\t$result[ $safe_key ] = substr( sanitize_text_field( (string) $value ), 0, 1000 );
\t\t\t} else {
\t\t\t\treturn new WP_Error( 'spf_event_payload_value_invalid', __( 'Event payload contains an unsupported value type.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t}
\t\treturn SPF_Runtime::canonicalize( $result );
\t}
"""
    replace_once(p, old, new)
    commit_round(4, 'Reject silently truncated event facts', [p])


def round5():
    p = 'includes/class-spf-platform-engineering.php'
    old = "actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2\\n      - name: PHP syntax"
    new = "actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\\n      - name: Set up PHP\\n        uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc\\n        with:\\n          php-version: '8.1'\\n          coverage: none\\n      - name: PHP syntax"
    replace_once(p, old, new)
    commit_round(5, 'Refresh Golden Path generated CI pins', [p])


def round6():
    p = 'includes/class-spf-platform-engineering.php'
    replace_once(
        p,
        "\t\t$sanitized = self::sanitize_config( $config );\n\t\t$lock_name = 'future-config-baselines';",
        "\t\t$sanitized = self::sanitize_config( $config );\n\t\tif ( is_wp_error( $sanitized ) ) {\n\t\t\treturn $sanitized;\n\t\t}\n\t\t$lock_name = 'future-config-baselines';",
    )
    replace_once(
        p,
        "\t\t$current = self::sanitize_config( $current );\n\t\t$keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $current ) ) ) );",
        "\t\t$current = self::sanitize_config( $current );\n\t\tif ( is_wp_error( $current ) ) {\n\t\t\treturn $current;\n\t\t}\n\t\t$keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $current ) ) ) );",
    )
    old = """\tprivate static function sanitize_config( array $config ) {
\t\treturn self::sanitize_config_level( $config, 0 );
\t}

\tprivate static function sanitize_config_level( array $config, $depth ) {
\t\tif ( $depth > 4 ) {
\t\t\treturn array( '_truncated'=>true );
\t\t}
\t\t$out = array();
\t\tforeach ( array_slice( $config, 0, 200, true ) as $key => $value ) {
\t\t\t$key = sanitize_key( $key );
\t\t\tif ( '' === $key ) {
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( preg_match( '/(secret|password|token|key|credential)/i', $key ) ) {
\t\t\t\t$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( is_array( $value ) ) {
\t\t\t\t$out[ $key ] = self::sanitize_config_level( $value, $depth + 1 );
\t\t\t} elseif ( is_scalar( $value ) || null === $value ) {
\t\t\t\t$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    new = """\tprivate static function sanitize_config( array $config ) {
\t\treturn self::sanitize_config_level( $config, 0 );
\t}

\tprivate static function sanitize_config_level( array $config, $depth ) {
\t\tif ( $depth > 4 ) {
\t\t\treturn new WP_Error( 'spf_config_too_deep', __( 'Configuration nesting exceeds the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\tif ( count( $config ) > 200 ) {
\t\t\treturn new WP_Error( 'spf_config_too_large', __( 'Configuration keys exceed the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\t$out = array();
\t\tforeach ( $config as $raw_key => $value ) {
\t\t\tif ( ! is_string( $raw_key ) ) {
\t\t\t\treturn new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$key = sanitize_key( $raw_key );
\t\t\tif ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) ) {
\t\t\t\treturn new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must already be unique canonical keys.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\tif ( preg_match( '/(^|_)(secret|password|token|private_key|api_key|encryption_key|credential)($|_)/i', $key ) ) {
\t\t\t\t$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( is_array( $value ) ) {
\t\t\t\t$nested = self::sanitize_config_level( $value, $depth + 1 );
\t\t\t\tif ( is_wp_error( $nested ) ) {
\t\t\t\t\treturn $nested;
\t\t\t\t}
\t\t\t\t$out[ $key ] = $nested;
\t\t\t} elseif ( is_scalar( $value ) || null === $value ) {
\t\t\t\t$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
\t\t\t} else {
\t\t\t\treturn new WP_Error( 'spf_config_value_invalid', __( 'Configuration drift input contains an unsupported value type.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    replace_once(p, old, new)
    commit_round(6, 'Make config drift fail closed on truncation risks', [p])


def round7():
    p = 'includes/class-spf-platform-engineering.php'
    old = """\t\t$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
\t\t$rings = array_values( array_unique( array_filter( array_map( 'sanitize_key', $rings ) ) ) );
\t\t$slo = self::sanitize_numeric_map( $slo );
\t\t$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );
"""
    new = """\t\t$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
\t\tif ( count( $rings ) > 20 ) {
\t\t\treturn new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings exceed the bounded rollout envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\t$normalized_rings = array();
\t\t$seen_rings = array();
\t\tforeach ( $rings as $raw_ring ) {
\t\t\tif ( ! is_string( $raw_ring ) ) {
\t\t\t\treturn new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$ring = sanitize_key( $raw_ring );
\t\t\tif ( '' === $ring || $raw_ring !== $ring || isset( $seen_rings[ $ring ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be unique canonical values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$seen_rings[ $ring ] = true;
\t\t\t$normalized_rings[] = $ring;
\t\t}
\t\t$rings = $normalized_rings;
\t\t$slo = self::sanitize_numeric_map( $slo );
\t\tif ( is_wp_error( $slo ) ) {
\t\t\treturn $slo;
\t\t}
\t\t$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );
"""
    replace_once(p, old, new)
    old = """\tpublic static function evaluate_slo_gate( array $metrics, array $objectives ) {
\t\t$metrics = self::sanitize_numeric_map( $metrics );
\t\t$objectives = self::sanitize_numeric_map( $objectives );
\t\tif ( empty( $objectives ) ) {
"""
    new = """\tpublic static function evaluate_slo_gate( array $metrics, array $objectives ) {
\t\t$metrics = self::sanitize_numeric_map( $metrics );
\t\t$objectives = self::sanitize_numeric_map( $objectives );
\t\tif ( is_wp_error( $metrics ) || is_wp_error( $objectives ) ) {
\t\t\t$error = is_wp_error( $objectives ) ? $objectives : $metrics;
\t\t\treturn array( 'allow'=>false, 'reason'=>'invalid_slo_input', 'violations'=>array( array( 'code'=>$error->get_error_code() ) ), 'checked_at'=>function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ) );
\t\t}
\t\tif ( empty( $objectives ) ) {
"""
    replace_once(p, old, new)
    old = """\tprivate static function sanitize_numeric_map( array $values ) {
\t\t$out = array();
\t\tforeach ( array_slice( $values, 0, 100, true ) as $key => $value ) {
\t\t\t$key = sanitize_key( $key );
\t\t\tif ( $key && is_numeric( $value ) ) {
\t\t\t\t$out[ $key ] = (float) $value;
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    new = """\tprivate static function sanitize_numeric_map( array $values ) {
\t\tif ( count( $values ) > 100 ) {
\t\t\treturn new WP_Error( 'spf_numeric_map_too_large', __( 'Numeric metric/objective maps exceed the bounded envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\t$out = array();
\t\tforeach ( $values as $raw_key => $value ) {
\t\t\tif ( ! is_string( $raw_key ) ) {
\t\t\t\treturn new WP_Error( 'spf_numeric_map_key_invalid', __( 'Metric/objective keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$key = sanitize_key( $raw_key );
\t\t\tif ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) || ! is_numeric( $value ) ) {
\t\t\t\treturn new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective entries must use unique canonical keys and numeric values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$number = (float) $value;
\t\t\tif ( ! is_finite( $number ) ) {
\t\t\t\treturn new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective values must be finite numbers.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$out[ $key ] = $number;
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    replace_once(p, old, new)
    commit_round(7, 'Make SLO and rollout declarations strict', [p])


def round8():
    p = 'includes/class-spf-platform-engineering.php'
    replace_once(
        p,
        "\t\t$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string)($schema['event_name']??'') );\n\t\t$version = sanitize_text_field( $schema['version']??'1.0.0' );",
        "\t\t$raw_name = (string) ( $schema['event_name'] ?? '' );\n\t\t$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', $raw_name );\n\t\t$version = sanitize_text_field( $schema['version']??'1.0.0' );",
    )
    replace_once(
        p,
        "\t\t$allowed_privacy = array( 'public','internal','personal','sensitive','restricted','secret','security' );",
        "\t\t$allowed_privacy = array( 'public','internal','restricted','confidential','ephemeral' );",
    )
    replace_once(
        p,
        "\t\t\t$type = sanitize_key( $definition['type']??'string' );\n\t\t\tif ( array_key_exists( 'required', $definition ) && ! is_bool( $definition['required'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\tif ( $field && in_array( $type, array('string','integer','number','boolean','array','object','timestamp'), true ) ) { $fields[$field] = array( 'type'=>$type, 'required'=>true === ($definition['required']??false) ); }",
        "\t\t\t$type = sanitize_key( $definition['type']??'string' );\n\t\t\tif ( array_key_exists( 'required', $definition ) && ! is_bool( $definition['required'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\tif ( ! in_array( $type, array('string','integer','number','boolean','array','object','timestamp'), true ) ) { return new WP_Error( 'spf_event_schema_type_invalid', __( 'Event-schema field type is not supported.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\t$fields[$field] = array( 'type'=>$type, 'required'=>true === ($definition['required']??false) );",
    )
    old = "\t\tif ( ''===$name || !SPF_Registry::valid_semver($version) || !preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner) || !in_array($privacy_class,$allowed_privacy,true) || empty($fields) ) { return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\treturn array( 'event_name'=>substr($name,0,160), 'version'=>$version, 'owner_module'=>$owner, 'privacy_class'=>$privacy_class, 'allow_additional'=>true===($schema['allow_additional']??false), 'fields'=>$fields, 'deprecated_at'=>substr(sanitize_text_field($schema['deprecated_at']??''),0,40) );"
    new = """\t\tif ( ''===$name || $raw_name !== $name || strlen( $name ) > 160 || !SPF_Registry::valid_semver($version) || !preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner) || !in_array($privacy_class,$allowed_privacy,true) || empty($fields) ) { return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t$deprecated_at = '';
\t\tif ( '' !== trim( (string) ( $schema['deprecated_at'] ?? '' ) ) ) {
\t\t\t$deprecated_ts = strtotime( (string) $schema['deprecated_at'] );
\t\t\tif ( false === $deprecated_ts ) { return new WP_Error( 'spf_event_schema_deprecation_invalid', __( 'Event-schema deprecation timestamp is invalid.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$deprecated_at = gmdate( 'Y-m-d H:i:s', $deprecated_ts );
\t\t}
\t\treturn array( 'event_name'=>$name, 'version'=>$version, 'owner_module'=>$owner, 'privacy_class'=>$privacy_class, 'allow_additional'=>true===($schema['allow_additional']??false), 'fields'=>$fields, 'deprecated_at'=>$deprecated_at );"""
    replace_once(p, old, new)
    commit_round(8, 'Align and harden event schema contracts', [p])


def round9():
    p = 'includes/class-spf-governance.php'
    old = """\t\t$status = sanitize_key( $release['status'] ?? 'planned' );
\t\tif ( 'planned' !== $status ) {
"""
    new = """\t\t$status = sanitize_key( $release['status'] ?? 'planned' );
\t\t$package_name = substr( sanitize_file_name( (string) $release['package_name'] ), 0, 191 );
\t\t$raw_evidence_json = wp_json_encode( $release['evidence'] );
\t\tif ( '' === $package_name ) {
\t\t\treturn new WP_Error( 'spf_release_package_name_invalid', __( 'Release evidence must bind a non-empty canonical package filename.', 'sabri-platform-foundation' ), array( 'status'=>422 ) );
\t\t}
\t\tif ( false === $raw_evidence_json || strlen( $raw_evidence_json ) > 262144 ) {
\t\t\treturn new WP_Error( 'spf_release_evidence_too_large', __( 'Release evidence exceeds the bounded immutable evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) );
\t\t}
\t\tif ( 'planned' !== $status ) {
"""
    replace_once(p, old, new)
    replace_once(
        p,
        "\t\t\t\t\t'package_name' => substr( sanitize_file_name( $release['package_name'] ), 0, 191 ),",
        "\t\t\t\t\t'package_name' => $package_name,",
    )
    replace_once(
        p,
        "\t\t$evidence = self::sanitize_evidence( $evidence );\n\t\t$evidence_error = self::validate_evidence_for_state( $next_status, $evidence );",
        "\t\t$raw_evidence_json = wp_json_encode( $evidence );\n\t\tif ( false === $raw_evidence_json || strlen( $raw_evidence_json ) > 262144 ) {\n\t\t\treturn new WP_Error( 'spf_release_evidence_too_large', __( 'Release transition evidence exceeds the bounded immutable evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) );\n\t\t}\n\t\t$evidence = self::sanitize_evidence( $evidence );\n\t\t$evidence_error = self::validate_evidence_for_state( $next_status, $evidence );",
    )
    replace_once(
        p,
        "\t\tif ( ! is_array( $amendment['decision'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );",
        "\t\tif ( ! is_array( $amendment['decision'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$decision_json = wp_json_encode( $amendment['decision'] );\n\t\tif ( false === $decision_json || strlen( $decision_json ) > 262144 ) {\n\t\t\treturn new WP_Error( 'spf_amendment_decision_too_large', __( 'Amendment decision evidence exceeds the bounded governance envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) );\n\t\t}\n\t\t$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );",
    )
    commit_round(9, 'Bound release and amendment evidence truth', [p])


def round10():
    p = 'includes/class-spf-resilience-lab.php'
    replace_once(
        p,
        "\t\t\t$pre = SPF_Audit::record_required( 'self_heal_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'], 'option_count'=>count( $before ) ) );\n",
        "\t\t\t$existing_recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );\n\t\t\t$existing_recoveries = is_array( $existing_recoveries ) ? $existing_recoveries : array();\n\t\t\tif ( count( $existing_recoveries ) >= 20 ) {\n\t\t\t\treturn new WP_Error( 'spf_self_heal_recovery_capacity_full', __( 'Self-heal recovery capacity is full; explicitly reconcile or retire an older recovery before another repair.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );\n\t\t\t}\n\t\t\t$pre = SPF_Audit::record_required( 'self_heal_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'], 'option_count'=>count( $before ) ) );\n",
    )
    replace_once(
        p,
        "\t\t\t\t\t\t\t\tupdate_option( $option . '_quarantine_' . time(), array( 'hash'=>SPF_Runtime::hash( $old ), 'quarantined_at'=>SPF_Runtime::now_mysql() ), false );\n\t\t\t\t\t\t\t\tupdate_option( $option, array(), false );",
        "\t\t\t\t\t\t\t\t// The canonical recovery snapshot below is the quarantine evidence; avoid creating orphan dynamic options.\n\t\t\t\t\t\t\t\tupdate_option( $option, array(), false );",
    )
    replace_once(
        p,
        "\t\t\t\t$recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );\n\t\t\t\t$recoveries = is_array( $recoveries ) ? $recoveries : array();\n\t\t\t\t$recoveries[ $recovery_id ] = $recovery;\n\t\t\t\t$expected_recoveries = array_slice( $recoveries, -20, null, true );\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $expected_recoveries, false );",
        "\t\t\t\t$recoveries = $existing_recoveries;\n\t\t\t\t$recoveries[ $recovery_id ] = $recovery;\n\t\t\t\t$expected_recoveries = $recoveries;\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $expected_recoveries, false );",
    )
    old = """\t\t\t} catch ( Throwable $error ) {
\t\t\t\tforeach ( $before as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t}
\t\t\t\treturn new WP_Error( 'spf_self_heal_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    new = """\t\t\t} catch ( Throwable $error ) {
\t\t\t\t$compensation_failures = array();
\t\t\t\tforeach ( $before as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t\tif ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) {
\t\t\t\t\t\t$compensation_failures[] = $option;
\t\t\t\t\t}
\t\t\t\t}
\t\t\t\tif ( $compensation_failures ) {
\t\t\t\t\treturn new WP_Error( 'spf_self_heal_compensation_incomplete', __( 'Self-heal failed and one or more File 01-owned values could not be restored.', 'sabri-platform-foundation' ), array( 'status'=>500, 'options'=>$compensation_failures ) );
\t\t\t\t}
\t\t\t\treturn new WP_Error( 'spf_self_heal_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    replace_once(p, old, new)
    old = """\t\t\t} catch ( Throwable $error ) {
\t\t\t\tforeach ( $current_values as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t}
\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );
\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    new = """\t\t\t} catch ( Throwable $error ) {
\t\t\t\t$compensation_failures = array();
\t\t\t\tforeach ( $current_values as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t\tif ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) {
\t\t\t\t\t\t$compensation_failures[] = $option;
\t\t\t\t\t}
\t\t\t\t}
\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );
\t\t\t\tif ( SPF_Runtime::hash( get_option( self::SELF_HEAL_RECOVERY_OPTION, array() ) ) !== SPF_Runtime::hash( $recoveries_before ) ) {
\t\t\t\t\t$compensation_failures[] = self::SELF_HEAL_RECOVERY_OPTION;
\t\t\t\t}
\t\t\t\tif ( $compensation_failures ) {
\t\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_compensation_incomplete', __( 'Self-heal rollback failed and its compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'options'=>$compensation_failures ) );
\t\t\t\t}
\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    replace_once(p, old, new)
    commit_round(10, 'Preserve and verify self-heal recovery truth', [p])


def finalize():
    tests = '''<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';

$root = dirname( __DIR__ );
$pass = 0;
$fail = static function ( string $message ): void { fwrite( STDERR, "FAIL: {$message}\\n" ); exit( 1 ); };
$expect = static function ( bool $condition, string $message ) use ( &$pass, $fail ): void { if ( ! $condition ) { $fail( $message ); } $pass++; };
$src = static function ( string $file ) use ( $root ): string { return (string) file_get_contents( $root . '/' . $file ); };

$runtime = $src('includes/class-spf-runtime.php');
$expect(str_contains($runtime,'information_schema.TABLES') && str_contains($runtime,'TABLE_NAME = %s') && str_contains($runtime,'time() >= $current_expires'),'Round 1 exact table/expiry protections missing');

$manifestMethod = new ReflectionMethod(SPF_Registry::class,'normalize_manifest'); $manifestMethod->setAccessible(true);
$baseManifest=array('module_key'=>'file-01','owner_file'=>'01','owner_name'=>'File 01','slug'=>'file-01','namespace_prefix'=>'SPF_','software_version'=>'2.0.0','contract_version'=>'2.0.0','state'=>'active','required'=>array(),'optional'=>array(),'capabilities'=>array(),'commands'=>array('ok',''),'queries'=>array(),'events'=>array(),'routes'=>array(),'data_classes'=>array('internal'),'health'=>array(),'canonical_entities'=>array(),'writes'=>array());
$r=$manifestMethod->invoke(null,$baseManifest); $expect(is_wp_error($r)&&'spf_manifest_collection_invalid'===$r->get_error_code(),'Round 2 manifest silent invalid-value collapse remains');
$contractMethod=new ReflectionMethod(SPF_Registry::class,'normalize_contract'); $contractMethod->setAccessible(true);
$r=$contractMethod->invoke(null,array('contract_key'=>'Test.v1','contract_version'=>'1.0.0','owner_module'=>'file-01','status'=>'current','schema'=>array('x'=>array('type'=>'string')),'consumers'=>array('file-00','file-00'))); $expect(is_wp_error($r)&&'spf_duplicate_contract_consumer'===$r->get_error_code(),'Round 2 duplicate contract consumer not rejected');
$registry=$src('includes/class-spf-registry.php'); $expect(str_contains($registry,'spf_invalid_route_redirect')&&str_contains($registry,'spf_duplicate_route_redirect'),'Round 2 route redirect strictness missing');

$system=$src('includes/class-spf-system-check.php'); $expect(str_contains($system,"'scheduler_id'")&&str_contains($system,"'verified_at'")&&str_contains($system,"'expires_at'")&&str_contains($system,'array_diff( $required_hooks, $reported_hooks )'),'Round 3 external cron evidence binding missing');

$payloadMethod=new ReflectionMethod(SPF_Event_Bus::class,'sanitize_payload'); $payloadMethod->setAccessible(true);
$oversized=array(); for($i=0;$i<101;$i++){$oversized['f'.$i]=$i;} $r=$payloadMethod->invoke(null,$oversized,0); $expect(is_wp_error($r)&&'spf_event_payload_too_many_fields'===$r->get_error_code(),'Round 4 oversized event payload was not rejected');
$r=$payloadMethod->invoke(null,array('Bad Key'=>'x'),0); $expect(is_wp_error($r)&&'spf_event_payload_key_invalid'===$r->get_error_code(),'Round 4 noncanonical event payload key was not rejected');

$scaffold=SPF_Platform_Engineering::scaffold_module(array('module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>array('file-01'),'optional'=>array()));
$ci=is_array($scaffold)?($scaffold['files']['.github/workflows/qa.yml']??''):''; $expect(str_contains($ci,'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1')&&str_contains($ci,'shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc')&&!str_contains($ci,'11bd71901bbe5b1630ceea73d27597364c9af683'),'Round 5 Golden Path still emits deprecated checkout CI');

$configMethod=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_config'); $configMethod->setAccessible(true);
$big=array(); for($i=0;$i<201;$i++){$big['k'.$i]=$i;} $r=$configMethod->invoke(null,$big); $expect(is_wp_error($r)&&'spf_config_too_large'===$r->get_error_code(),'Round 6 oversized config was not rejected');
$r=$configMethod->invoke(null,array('Bad Key'=>'x')); $expect(is_wp_error($r)&&'spf_config_key_invalid'===$r->get_error_code(),'Round 6 noncanonical config key was not rejected');

$numericMethod=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_numeric_map'); $numericMethod->setAccessible(true);
$r=$numericMethod->invoke(null,array('latency_p95'=>'not-a-number')); $expect(is_wp_error($r)&&'spf_numeric_map_invalid'===$r->get_error_code(),'Round 7 malformed SLO value was silently dropped');
$gate=SPF_Platform_Engineering::evaluate_slo_gate(array('latency_p95'=>100),array('Bad Metric'=>200)); $expect(is_array($gate)&&empty($gate['allow'])&&'invalid_slo_input'===($gate['reason']??''),'Round 7 invalid objective did not fail the SLO gate');
$engineering=$src('includes/class-spf-platform-engineering.php'); $expect(str_contains($engineering,'unique canonical values'),'Round 7 rollout duplicate/canonical-ring rejection missing');

$schemaMethod=new ReflectionMethod(SPF_Platform_Engineering::class,'normalize_event_schema'); $schemaMethod->setAccessible(true);
$bad=array('event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>array('x'=>array('type'=>'mystery'))); $r=$schemaMethod->invoke(null,$bad); $expect(is_wp_error($r)&&'spf_event_schema_type_invalid'===$r->get_error_code(),'Round 8 invalid event-schema type silently disappeared');
$bad=array('event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>array('x'=>array('type'=>'string')),'deprecated_at'=>'not-a-date'); $r=$schemaMethod->invoke(null,$bad); $expect(is_wp_error($r)&&'spf_event_schema_deprecation_invalid'===$r->get_error_code(),'Round 8 invalid event-schema deprecation accepted');
$good=array('event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'confidential','fields'=>array('x'=>array('type'=>'string'))); $r=$schemaMethod->invoke(null,$good); $expect(is_array($r)&&'confidential'===($r['privacy_class']??''),'Round 8 schema/runtime privacy vocabulary remains inconsistent');

$governance=$src('includes/class-spf-governance.php'); $expect(str_contains($governance,'spf_release_package_name_invalid')&&substr_count($governance,'spf_release_evidence_too_large')>=2&&str_contains($governance,'spf_amendment_decision_too_large'),'Round 9 bounded release/amendment evidence guards missing');

$resilience=$src('includes/class-spf-resilience-lab.php'); $expect(str_contains($resilience,'spf_self_heal_recovery_capacity_full')&&!str_contains($resilience,"array_slice( $recoveries, -20")&&str_contains($resilience,'spf_self_heal_compensation_incomplete')&&str_contains($resilience,'spf_self_heal_rollback_compensation_incomplete'),'Round 10 self-heal recovery/compensation truth missing');

printf("Seventh ten-round review assertions: %d/%d PASS\\n",$pass,$pass);
'''
    write('tests/seventh-ten-round-review-tests.php', tests)

    qa = read('qa/run-tests.sh')
    anchor = 'php tests/sixth-ten-round-review-tests.php\n'
    if 'seventh-ten-round-review-tests.php' not in qa:
        qa = qa.replace(anchor, anchor + 'php tests/seventh-ten-round-review-tests.php\n', 1)
    write('qa/run-tests.sh', qa)

    review = '''# File 01 — Seventh Fresh Ten-Round Review and Fix Cycle — 2026-08-08

This is a seventh independent adversarial review of File 01 v2.0 after the sixth ten-round cycle. Each round was reviewed, corrected immediately on the working branch, regression-checked, committed and pushed before the next round. The governing basis remains the consolidated central plan and the File 01 v2.0 Future Foundation plan. Staging, live and operational acceptance remain separate evidence gates.

1. **Exact database/lock runtime truth.** `SHOW TABLES LIKE` treated underscores as SQL wildcards and could misidentify similarly named tables; stale lock takeover also waited past the exact expiry second. Fixed with exact `information_schema.TABLES` equality and `>=` expiry handling.
2. **Canonical registry normalization truth.** Manifest collections, contract consumers and route redirects could silently discard or collapse malformed/duplicate declarations. Fixed with explicit invalid/duplicate errors and no silent declaration loss.
3. **External cron evidence truth.** With WP-Cron disabled, a bare `verified=true` adapter claim could mark scheduling healthy without scheduler identity, freshness, expiry or complete hook coverage. Fixed with fresh, expiring, identified evidence and required-hook coverage.
4. **Event fact completeness.** Runtime event payloads could silently truncate after 100 fields or excessive depth and continue publishing incomplete facts. Fixed by rejecting oversized/deep/noncanonical/unsupported payloads rather than truncating them.
5. **Golden-Path generated CI currency.** The scaffolder still emitted the old Node-20-targeting checkout v4 pin after the repository workflow had moved to v7. Fixed generated CI to immutable checkout v7.0.1 plus pinned PHP setup.
6. **Configuration drift completeness.** Config normalization silently sliced after 200 keys/depth 4, allowing drift beyond the truncation boundary to disappear. Fixed by fail-closed bounds, canonical-key checks and explicit unsupported-value errors.
7. **SLO and progressive-ring input integrity.** Numeric maps silently discarded malformed/excess entries and rollout rings silently normalized/deduplicated. Fixed with strict bounded numeric maps, finite-number checks, canonical unique rings and fail-closed SLO evaluation.
8. **Event-schema/runtime contract alignment.** Schema registry privacy classes did not match Event Bus runtime classes; invalid field types could disappear silently and malformed deprecation timestamps were accepted. Fixed by one canonical privacy vocabulary, explicit type rejection and validated UTC deprecation timestamps.
9. **Release/amendment evidence envelope.** A release package filename could normalize empty and release/transition/amendment evidence had no explicit 256 KiB envelope at the governance entry point. Fixed with canonical non-empty package binding and bounded evidence checks.
10. **Self-heal recovery and compensation truth.** The 21st self-heal recovery silently evicted older rollback truth; apply/rollback compensation writes were not read-back verified, and a dynamic quarantine option could be orphaned. Fixed by capacity fail-closed behavior, canonical recovery snapshot use and verified compensation.

**Defects found:** rounds 1, 2, 3, 4, 5, 6, 7, 8, 9 and 10.

**Defect-free rounds before correction in this seventh cycle:** none.

The final exact-head source, WordPress/MySQL runtime, concurrency, purge and deterministic-package QA must be green before Automated-QA Green is reasserted for this head. Hostinger staging acceptance, real companion coexistence, browser/device/accessibility/RTL/weak-network testing, representative load/cache/cron testing, independent backup/restore and rollback proof, Founder staging acceptance, production cutover and sustained monitoring remain pending separate gates.
'''
    write('SEVENTH-TEN-ROUND-REVIEW-2026-08-08.md', review)

    changelog = read('CHANGELOG.md')
    if 'Seventh fresh ten-round corrective cycle' not in changelog:
        changelog += "\n## 2.0.0 — Seventh fresh ten-round corrective cycle (2026-08-08)\n- Hardened exact table/lock truth, registry normalization, cron evidence, event payloads, generated CI, config drift, SLO/rings, event schemas, release evidence and self-heal recovery/compensation.\n- Added permanent seventh-cycle regression assertions and review evidence.\n"
    write('CHANGELOG.md', changelog)

    for transient in ['.github/workflows/seventh-fix-runner.yml', 'tools/seventh-review-fix.py']:
        path = Path(transient)
        if path.exists():
            path.unlink()

    sh("find . -type f -not -path './.git/*' -not -path './build/*' -not -path './dist/*' -not -name 'SOURCE-CHECKSUMS.sha256' -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SOURCE-CHECKSUMS.sha256")
    sh('bash qa/run-tests.sh')
    sh('bash tools/build-package.sh')
    sh('git add -A')
    sh('git diff --cached --check')
    sh("git commit -m '[seventh review final] Add regression evidence and refresh exact checksums'")
    sh(f'git push origin HEAD:{BRANCH}')
    print('SEVENTH_CYCLE_FINAL_HEAD=' + subprocess.check_output('git rev-parse HEAD', shell=True, text=True).strip())


def main():
    sh("git config user.name 'majidhussainqadri1-dot'")
    sh("git config user.email 'majidhussainqadri1@gmail.com'")
    sh('git merge-base --is-ancestor b1a0f825fd42dab21bd02b5832810a053c4037aa HEAD')
    round1()
    round2()
    round3()
    round4()
    round5()
    round6()
    round7()
    round8()
    round9()
    round10()
    finalize()


if __name__ == '__main__':
    main()
