<?php
/**
 * Real SQL that already does the right thing: reserved identifiers are backticked,
 * and window functions are used as functions. Must produce zero findings.
 */

global $wpdb;

$results = $wpdb->get_results(
	"SELECT id, `rank`, `lead` FROM {$wpdb->prefix}leaderboard ORDER BY `rank` ASC"
);

$ranked = $wpdb->get_results(
	"SELECT id, RANK() OVER (ORDER BY score DESC) AS position FROM {$wpdb->prefix}players"
);
