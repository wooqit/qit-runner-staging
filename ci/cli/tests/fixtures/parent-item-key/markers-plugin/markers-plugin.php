<?php
/**
 * Plugin Name: Markers Plugin
 */

function markers_plugin_children( $bundle_cart_key ) {
	$a = [ 'bundled_by' => $bundle_cart_key ]; // qit-ignore: parent-item-key

	$b = [ 'bundled_by' => $bundle_cart_key ]; # qit-ignore: parent-item-key

	$c = [
		'bundled_by' =>
			$bundle_cart_key, // qit-ignore: parent-item-key
	];

	$d = [ 'bundled_by' => $bundle_cart_key ]; // phpcs:ignore

	$e = [ 'bundled_by' => $bundle_cart_key ]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing

	$f = [ 'bundled_by' => $bundle_cart_key, 'note' => 'qit-ignore: parent-item-key' ];

	$g = [ 'bundled_by' => $bundle_cart_key ]; /* qit-ignore: parent-item-key */

	$h = [ 'bundled_by' => $bundle_cart_key ]; /** qit-ignore: parent-item-key */

	// qit-ignore: parent-item-key
	$i = [ 'bundled_by' => $bundle_cart_key ];

	$j = [ 'bundled_by' => $bundle_cart_key ];
	// qit-ignore: parent-item-key

	$k = [ // qit-ignore: parent-item-key
		'bundled_by' => $bundle_cart_key,
	];

	return [ $a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k ];
}
