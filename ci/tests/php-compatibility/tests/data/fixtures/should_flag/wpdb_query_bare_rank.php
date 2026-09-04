<?php
/**
 * A genuine bug: `rank` used as a bare column identifier in a real query.
 * This breaks on MariaDB 10.2+/MySQL 8.0+ and must be flagged.
 */

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT id, rank FROM {$wpdb->prefix}leaderboard ORDER BY rank DESC"
);
