from pathlib import Path


def once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one anchor, found {count}")
    return text.replace(old, new, 1)


p = Path('includes/class-spf-idempotency.php')
s = p.read_text()
s = once(
    s,
    "\t\t$inserted = $wpdb->insert(\n\t\t\t$table,",
    "\t\t$previous_suppress = $wpdb->suppress_errors( true );\n\t\t$inserted = $wpdb->insert(\n\t\t\t$table,",
    'idempotency suppress before insert',
)
s = once(
    s,
    "\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s' )\n\t\t);\n\n\t\tif ( false === $inserted ) {",
    "\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s' )\n\t\t);\n\t\t$insert_error = (string) $wpdb->last_error;\n\t\t$wpdb->suppress_errors( $previous_suppress );\n\n\t\tif ( false === $inserted ) {",
    'idempotency restore suppression',
)
p.write_text(s)

p = Path('includes/class-spf-event-bus.php')
s = p.read_text()
s = once(
    s,
    "\t\t$now = SPF_Runtime::now_mysql();\n\t\t$inserted = $wpdb->insert(\n\t\t\tSPF_Installer::table( 'outbox' ),",
    "\t\t$now = SPF_Runtime::now_mysql();\n\t\t$previous_suppress = $wpdb->suppress_errors( true );\n\t\t$inserted = $wpdb->insert(\n\t\t\tSPF_Installer::table( 'outbox' ),",
    'outbox suppress before insert',
)
s = once(
    s,
    "\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s' )\n\t\t);\n\t\tif ( false === $inserted && false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {",
    "\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s' )\n\t\t);\n\t\t$insert_error = (string) $wpdb->last_error;\n\t\t$wpdb->suppress_errors( $previous_suppress );\n\t\tif ( false === $inserted && false !== stripos( $insert_error, 'duplicate' ) ) {",
    'outbox restore suppression',
)
p.write_text(s)

p = Path('tests/source-quality-tests.php')
s = p.read_text()
anchor = "$assert( str_contains( $files['includes/class-spf-idempotency.php']??'', 'recovery_receipt' ), 'Idempotency recovery receipt missing.' );"
replacement = anchor + "\n$assert( str_contains( $files['includes/class-spf-idempotency.php']??'', '$wpdb->suppress_errors( true )' ), 'Expected idempotency duplicate insert noise is not suppressed.' );"
s = once(s, anchor, replacement, 'idempotency source assertion')
anchor = "$assert( str_contains( $files['includes/class-spf-event-bus.php']??'', \"'stale_processing_recovered'\" ), 'Outbox stale-lease recovery missing.' );"
replacement = anchor + "\n$assert( str_contains( $files['includes/class-spf-event-bus.php']??'', '$wpdb->suppress_errors( true )' ), 'Expected outbox dedupe insert noise is not suppressed.' );"
s = once(s, anchor, replacement, 'outbox source assertion')
p.write_text(s)
