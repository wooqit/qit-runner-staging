<?php
/**
 * A genuine bug: bare reserved words used as column names in a CREATE TABLE.
 */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$sql = "CREATE TABLE {$wpdb->prefix}metrics (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	rank INT NOT NULL,
	lag INT NOT NULL,
	PRIMARY KEY (id)
)";

dbDelta( $sql );
