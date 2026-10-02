<?php

// Minimal reusable fixtures for routes that require existing Woo objects. Seeding is best-effort:
// unsupported object types must reduce reachability, not make the whole campaign unavailable.
if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

if ( function_exists( 'wc_create_page' ) ) {
	update_option( 'woocommerce_onboarding_profile_completed', 'yes' );
	update_option( 'woocommerce_redirect_to_setup', 'no' );
	update_option( 'woocommerce_coming_soon', 'no' );
}

if ( class_exists( 'WC_Product_Simple' ) && function_exists( 'wc_get_products' ) ) {
	$existing_products = wc_get_products( [
		'limit'  => 1,
		'return' => 'ids',
	] );

	if ( empty( $existing_products ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'QIT API Fuzz Fixture' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();
	}
}

if ( ! get_user_by( 'login', 'qit-api-fuzz-customer' ) ) {
	wp_insert_user( [
		'user_login' => 'qit-api-fuzz-customer',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'qit-api-fuzz-customer@example.test',
		'role'       => 'customer',
	] );
}
