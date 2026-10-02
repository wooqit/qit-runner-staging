<?php
/**
 * Plugin Name: QIT Request Footprint Many Sites Fixture
 * Description: Known-answer fixture for the Activation request footprint: more read sites than the recorder keeps, then a read on admin_init from another file that has no call site, only a hook total.
 * Version: 1.0.0
 */

require_once __DIR__ . '/includes/late.php';

add_action( 'init', 'qit_request_footprint_many_sites_reads' );

// One statement per line: each line is its own call site, so these fill the recorder's read slots.
function qit_request_footprint_many_sites_reads(): void {
	global $wpdb;

	$wpdb->get_var( 'SELECT 1' );
	$wpdb->get_var( 'SELECT 2' );
	$wpdb->get_var( 'SELECT 3' );
	$wpdb->get_var( 'SELECT 4' );
	$wpdb->get_var( 'SELECT 5' );
	$wpdb->get_var( 'SELECT 6' );
	$wpdb->get_var( 'SELECT 7' );
	$wpdb->get_var( 'SELECT 8' );
	$wpdb->get_var( 'SELECT 9' );
	$wpdb->get_var( 'SELECT 10' );
	$wpdb->get_var( 'SELECT 11' );
	$wpdb->get_var( 'SELECT 12' );
	$wpdb->get_var( 'SELECT 13' );
	$wpdb->get_var( 'SELECT 14' );
	$wpdb->get_var( 'SELECT 15' );
	$wpdb->get_var( 'SELECT 16' );
	$wpdb->get_var( 'SELECT 17' );
	$wpdb->get_var( 'SELECT 18' );
	$wpdb->get_var( 'SELECT 19' );
	$wpdb->get_var( 'SELECT 20' );
	$wpdb->get_var( 'SELECT 21' );
	$wpdb->get_var( 'SELECT 22' );
}
