<?php
/**
 * A genuine bug: bare reserved words as column names in an INSERT column list.
 */

global $wpdb;

$wpdb->query(
	"INSERT INTO {$wpdb->prefix}metrics (product_id, rank, lag) VALUES (1, 2, 3)"
);
