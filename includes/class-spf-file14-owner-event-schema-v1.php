<?php
/**
 * Proposed cross-owner File14 outcome event envelope.
 *
 * This is a schema validator only. It does NOT verify producer identity,
 * cryptographic evidence, consent provenance or a delivery/erasure ledger.
 * Not registered at runtime. It MUST NOT unlock File14 conversion scoring.
 * File08 native outbox records AppointmentRequested.v1 (a request, NOT a
 * completed booking); File09 native post-commit hook is submitted, NOT merely
 * started. Normalized names below are an UNAPPROVED compatibility proposal;
 * existing File14 legacy counters must not be reinterpreted or migrated by
 * this schema-only class.
 */
defined( 'ABSPATH' ) || exit;

final class SPF_File14_Owner_Event_Schema_V1 {
    const CONTRACT = 'spf.file14.owner-attestation.v1';

    public static function stages() {
        return array(
            'file07' => array( 'event' => 'File07.DirectoryServed.v1', 'stage' => 'destination_served' ),
            'file08' => array( 'event' => 'File08.AppointmentRequested.v1', 'stage' => 'appointment_requested' ),
            'file09' => array( 'event' => 'File09.ApplicationSubmitted.v1', 'stage' => 'application_submitted' ),
        );
    }

    public static function validate( $value, $now = null ) {
        $no = array( 'schema_valid' => false, 'trusted_owner' => false,
            'release_ready' => false, 'reason' => 'invalid_envelope' );
        if ( ! is_array( $value ) ) { return $no; }
        $fields = array( 'contract', 'owner', 'event', 'stage', 'event_id',
            'emitted_at', 'purpose', 'optional_analytics_consent', 'gpc',
            'minor', 'data_class', 'source_commit' );
        // Any unexpected key may conceal a patient/applicant/booking identifier.
        $keys = array_keys( $value );
        sort( $keys, SORT_STRING );
        $expected = $fields;
        sort( $expected, SORT_STRING );
        if ( $keys !== $expected ) { $no['reason'] = 'unapproved_fields'; return $no; }
        if ( ! isset( $value['contract'], $value['owner'], $value['event'], $value['stage'],
            $value['event_id'], $value['emitted_at'], $value['purpose'], $value['data_class'],
            $value['source_commit'] ) ) { return $no; }
        if ( self::CONTRACT !== $value['contract'] || ! is_string( $value['owner'] )
            || ! isset( self::stages()[ $value['owner'] ] ) ) { return $no; }
        $owner = self::stages()[ $value['owner'] ];
        if ( $value['event'] !== $owner['event'] || $value['stage'] !== $owner['stage'] ) {
            $no['reason'] = 'owner_stage_mismatch'; return $no;
        }
        if ( ! is_string( $value['event_id'] ) || ! preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $value['event_id'] ) ) { $no['reason'] = 'event_id_unverified'; return $no; }
        if ( ! is_int( $value['emitted_at'] ) || $value['emitted_at'] <= 0 ) { return $no; }
        $clock = null === $now ? time() : $now;
        if ( ! is_int( $clock ) || abs( $clock - $value['emitted_at'] ) > 300 ) {
            $no['reason'] = 'event_expired_or_clock_unknown'; return $no;
        }
        if ( 'conversion_analytics' !== $value['purpose']
            || true !== $value['optional_analytics_consent']
            || false !== $value['gpc'] || false !== $value['minor']
            || 'aggregate_only' !== $value['data_class'] ) {
            $no['reason'] = 'optional_consent_or_privacy_unverified'; return $no;
        }
        if ( ! is_string( $value['source_commit'] )
            || ! preg_match( '/^[a-f0-9]{40}$/D', $value['source_commit'] ) ) {
            $no['reason'] = 'producer_source_unverified'; return $no;
        }
        // This only confirms structural compliance; a Git SHA in payload is
        // not an authenticated installed plugin or post-commit acknowledgement.
        return array( 'schema_valid' => true, 'trusted_owner' => false,
            'release_ready' => false, 'reason' => 'owner_signature_registry_and_live_acceptance_missing' );
    }

    public static function release_ready() {
        // Must not be replaced by a non-owner filter or manual toggle.
        return false;
    }
}
