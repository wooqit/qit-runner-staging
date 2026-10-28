<?php
/**
 * Plugin Name: Filter Plugin
 */

namespace FilterPlugin;

function add_child( $bundle_cart_key ) {
	return [ 'bundled_by' => $bundle_cart_key ];
}

\add_filter( 'woocommerce_store_api_cart_item_parent_item_key', function ( $parent_item_key, $cart_item ) {
	return $cart_item['bundled_by'] ?? $parent_item_key;
}, 10, 2 );
