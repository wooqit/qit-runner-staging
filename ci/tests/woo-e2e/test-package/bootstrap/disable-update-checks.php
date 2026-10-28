<?php
/**
 * Plugin Name: Disable Update Checks
 * Description: Prevents external API calls to WordPress.org for plugin/theme updates.
 *              Avoids SSL errors and warnings in environments without outbound HTTPS.
 */

// Return empty update data so WordPress never calls home.
add_filter( 'pre_site_transient_update_plugins', function ( $value ) {
	return $value ?: (object) [ 'last_checked' => time(), 'response' => [], 'no_update' => [], 'translations' => [] ];
} );

add_filter( 'pre_site_transient_update_themes', function ( $value ) {
	return $value ?: (object) [ 'last_checked' => time(), 'response' => [], 'no_update' => [], 'translations' => [] ];
} );

add_filter( 'pre_site_transient_update_core', function ( $value ) {
	return $value ?: (object) [ 'last_checked' => time(), 'updates' => [], 'translations' => [] ];
} );
