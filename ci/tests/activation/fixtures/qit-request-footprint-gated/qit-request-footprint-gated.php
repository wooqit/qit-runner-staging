<?php
/**
 * Plugin Name: QIT Request Footprint Gated Fixture
 * Description: Known-answer control for the Activation request footprint: the same work, gated to the one screen that needs it.
 * Version: 1.0.0
 */

add_action( 'admin_menu', function (): void {
	$hook = add_options_page( 'QIT footprint', 'QIT footprint', 'manage_options', 'qit-request-footprint-gated', '__return_null' );
	add_action( 'load-' . $hook, 'qit_request_footprint_gated_sync_note' );
} );

function qit_request_footprint_gated_sync_note(): void {
	global $wpdb;

	$wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'qit_request_footprint_note' ) );
	update_option( 'qit_request_footprint_last_seen', microtime( true ), false );
	wp_remote_get( 'http://127.0.0.1:1/qit-request-footprint', [ 'timeout' => 1 ] );
}
