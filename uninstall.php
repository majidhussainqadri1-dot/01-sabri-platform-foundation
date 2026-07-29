<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'spf_version' );
delete_option( 'spf_founder_user_id' );
delete_transient( 'spf_activation_notice' );

// Pages and user content are intentionally preserved to prevent data loss.

