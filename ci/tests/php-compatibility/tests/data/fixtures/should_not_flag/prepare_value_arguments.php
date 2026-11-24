<?php
/**
 * Regression fixture: reserved words appearing as bound *values* in prepare()
 * calls (including nested $wpdb->query( $wpdb->prepare( … ) )). Values are not
 * column identifiers and must never be flagged — only the SQL argument is scanned.
 */

global $wpdb;

$a = $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}foo WHERE label = %s", 'rank' );

$b = $wpdb->query(
	$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}foo WHERE label = %s AND kind = %s", 'lead', 'lag' )
);

$c = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}foo", 'rank' );
