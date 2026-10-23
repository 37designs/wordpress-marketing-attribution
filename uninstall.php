<?php
// Removes the plugin's settings. Fields already added to forms, and the source data on entries, are left alone.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'tssc_settings' );
delete_option( 'tssc_migrated' );
delete_transient( 'tssc_migration_notice' );
