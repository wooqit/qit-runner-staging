<?php
/**
 * Plugin Name: Filter Constant Plugin
 */

define( 'FILTER_CONSTANT_PARENT_KEY_HOOK', "woocommerce_store_api_cart_item_parent_item_key" );

function filter_constant_add_child( $bundle_cart_key ) {
	return array( 'bundled_by' => $bundle_cart_key );
}

add_filter( FILTER_CONSTANT_PARENT_KEY_HOOK, 'filter_constant_parent_key', 10, 2 );
