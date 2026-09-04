<?php
/**
 * Regression fixture: prose that begins with a SQL verb and contains other SQL-ish
 * words. "Update ... from ... lead ..." must NOT be treated as SQL — only a real
 * statement shape (UPDATE ... SET) qualifies.
 */

$labels = [
	'rank_order' => __( 'Update the rank order from highest lead to lowest', 'text-domain' ),
	'create_new' => __( 'Create a new rank for this product', 'text-domain' ),
	'delete_row' => __( 'Delete the selected rank from the leaderboard', 'text-domain' ),
];
