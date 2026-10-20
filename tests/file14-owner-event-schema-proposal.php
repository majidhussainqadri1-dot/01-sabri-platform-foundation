<?php
/** R1-File14: 40 schema-only adversarial cases; NOT native owner authentication. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-spf-file14-owner-event-schema-v1.php';
$now = 1750000000;
$good = array(
    'contract' => SPF_File14_Owner_Event_Schema_V1::CONTRACT,
    'owner' => 'file09', 'event' => 'File09.ApplicationStarted.v1',
    'stage' => 'application_started',
    'event_id' => '12345678-1234-4123-8123-123456789abc',
    'emitted_at' => $now, 'purpose' => 'conversion_analytics',
    'optional_analytics_consent' => true, 'gpc' => false, 'minor' => false,
    'data_class' => 'aggregate_only', 'source_commit' => str_repeat( 'a', 40 ),
);
$cases = array();
$cases[] = array( 'canonical File09 schema', $good, true );
foreach ( array(
    array( 'owner'=>'file08', 'event'=>'File08.BookingStarted.v1', 'stage'=>'booking_started' ),
    array( 'owner'=>'file07', 'event'=>'File07.DirectoryServed.v1', 'stage'=>'destination_served' ),
) as $change ) {
    $cases[] = array( 'other canonical owner schema', array_merge( $good, $change ), true );
}
$edits = array(
    array( 'contract', 'v2' ), array( 'owner', 'file99' ),
    array( 'owner', 'FILE09' ), array( 'owner', '' ),
    array( 'event', 'File08.BookingStarted.v1' ), array( 'stage', 'booking_started' ),
    array( 'stage', 'cta_selected' ), array( 'event_id', 'bad' ),
    array( 'event_id', 123 ), array( 'event_id', '12345678-1234-0123-8123-123456789abc' ),
    array( 'emitted_at', $now - 301 ), array( 'emitted_at', $now + 301 ),
    array( 'emitted_at', $now + 50.5 ), array( 'emitted_at', null ),
    array( 'purpose', 'booking_care' ), array( 'purpose', '' ),
    array( 'optional_analytics_consent', false ), array( 'optional_analytics_consent', 'true' ),
    array( 'optional_analytics_consent', 1 ), array( 'gpc', true ),
    array( 'gpc', 'false' ), array( 'minor', true ), array( 'minor', null ),
    array( 'data_class', 'patient' ), array( 'data_class', '' ),
    array( 'source_commit', 'HEAD' ), array( 'source_commit', str_repeat('z',40) ),
    array( 'source_commit', str_repeat('b',39) ),
);
foreach ( $edits as $edit ) {
    $copy = $good;
    $copy[ $edit[0] ] = $edit[1];
    $cases[] = array( 'malformed '.$edit[0], $copy, false );
}
foreach ( array( 'patient_user_id', 'doctor_id', 'appointment_ref', 'clinical_note',
    'ip_address', 'email', 'nonce', 'signature', 'consent_cookie' ) as $key ) {
    $copy = $good;
    $copy[ $key ] = 'private';
    $cases[] = array( 'unexpected private field', $copy, false );
}
if ( count( $cases ) !== 40 ) {
    fwrite( STDERR, 'Wrong case count '.count( $cases ).PHP_EOL );
    exit( 1 );
}
foreach ( $cases as $i => $row ) {
    list( $name, $payload, $expected ) = $row;
    $result = SPF_File14_Owner_Event_Schema_V1::validate( $payload, $now );
    if ( $result['schema_valid'] !== $expected || $result['trusted_owner'] !== false
        || $result['release_ready'] !== false ) {
        fwrite( STDERR, 'FAIL '.( $i + 1 ).' '.$name.PHP_EOL );
        exit( 1 );
    }
    echo 'R1 owner event schema '.sprintf( '%02d', $i + 1 ).'/40 PASS'.PHP_EOL;
}
if ( SPF_File14_Owner_Event_Schema_V1::release_ready() ) { exit( 1 ); }
echo 'File01 40/40 owner event SCHEMA tests PASS; signing/dedupe/consent evidence and staging NOT ACCEPTED'.PHP_EOL;
