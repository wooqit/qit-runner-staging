<?php
/**
 * Plugin Name: Basic Auth
 * Description: Enables HTTP Basic Authentication for the WordPress REST API.
 */

add_filter( 'determine_current_user', function ( $user ) {
	if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) ) {
		return $user;
	}

	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	$user = wp_authenticate( $username, $password );

	if ( is_wp_error( $user ) ) {
		return 0;
	}

	return $user->ID;
}, 20 );
