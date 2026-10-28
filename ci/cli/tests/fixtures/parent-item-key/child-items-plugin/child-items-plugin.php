<?php
/**
 * Plugin Name: Child Items Plugin
 *
 * Hooks woocommerce_store_api_cart_item_parent_item_key? Only in this docblock.
 */

// add_filter( 'woocommerce_store_api_cart_item_parent_item_key', 'child_items_parent_key' );

require __DIR__ . '/includes/class-child-items-cart.php';
