<?php

add_action( 'admin_init', 'qit_request_footprint_many_sites_late_read' );

// One read after the init reads have taken every read slot, so it gets no call site.
function qit_request_footprint_many_sites_late_read(): void {
	global $wpdb;

	$wpdb->get_var( 'SELECT 23' );
}
