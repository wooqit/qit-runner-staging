<?php
/**
 * Reserved words used in ordinary, non-SQL PHP contexts. None of these should flag.
 */

$args = [
	'rank'  => 10,
	'lead'  => 'sales',
	'order' => 'lag',
];

$obj        = new stdClass();
$obj->rank  = 1;
$obj->lead  = 2;
$rank       = 'first_value';
$ntile      = compute_ntile( $args );

// SELECT rank FROM wp_things -- this is a comment, not SQL.

echo esc_html__( 'Sort results from rank order, highest lead first', 'text-domain' );

do_action( 'plugin_rank_updated', $rank );
