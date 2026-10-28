<?php
/**
 * Plugin Name: QIT Request Footprint Ungated Fixture
 * Description: Known-answer fixture for the Activation request footprint: database and HTTP work on every admin request, AJAX included.
 * Version: 1.0.0
 */

add_action( 'admin_init', 'qit_request_footprint_ungated_sync_note' );

// The Inbox Notes pattern: look up and refresh state on every admin_init, with no
// wp_doing_ajax() or screen check.
function qit_request_footprint_ungated_sync_note(): void {
	global $wpdb;

	$wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'qit_request_footprint_note' ) );
	// The write is issued from wp-includes/option.php, so only attribution on any frame of
	// the stack, not the top one, credits it to this plugin.
	update_option( 'qit_request_footprint_last_seen', microtime( true ), false );
	wp_remote_get( 'http://127.0.0.1:1/qit-request-footprint', [ 'timeout' => 1 ] );
}
