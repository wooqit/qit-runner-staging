<?php
/**
 * Plugin Name: QIT API Fuzz Synthetic SUT
 * Description: Known-answer fixture for the schema-driven API fuzz PoC. Registers routes with
 *              planted, deterministic faults plus a clean control so an evaluation run has a
 *              verifiable expected outcome. NEVER ship this to a production site.
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * The fixture exists so the api-fuzz pipeline can be validated against a plugin whose faults are
 * known in advance:
 *
 *   POST /qit-fuzz-fixture/v1/deterministic-fatal  -> uncaught Error, HTTP 500 (SUT-attributed)
 *   POST /qit-fuzz-fixture/v1/swallowed-fatal       -> HTTP 200, fatal in shutdown (SUT-attributed)
 *   GET  /qit-fuzz-fixture/v1/clean                 -> HTTP 200, never faults (control)
 *
 * The two faults live in this file, so a correct campaign attributes them to this plugin's slug and
 * leaves the clean route out of the findings entirely.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'qit-fuzz-fixture/v1', '/deterministic-fatal', [
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => '__return_true',
		'args'                => [
			'id' => [
				'type'     => 'integer',
				'required' => false,
			],
		],
		'callback'            => function ( WP_REST_Request $request ) {
			// Deterministic uncaught Error regardless of the generated input: the fuzzer only has
			// to reach the callback for the fault to reproduce from a clean state every time.
			$broken = null;
			return $broken->explode(); // @phpstan-ignore-line -- intentional fatal for the fixture.
		},
	] );

	register_rest_route( 'qit-fuzz-fixture/v1', '/swallowed-fatal', [
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => '__return_true',
		'args'                => [
			'note' => [
				'type'     => 'string',
				'required' => false,
			],
		],
		'callback'            => function ( WP_REST_Request $request ) {
			// The classic swallowed fault: a valid 200 body is emitted, then a fatal occurs during
			// shutdown. Status-code-only oracles miss this; the in-process instrumentation catches it.
			register_shutdown_function( function () {
				$broken = null;
				$broken->explode(); // @phpstan-ignore-line -- intentional fatal for the fixture.
			} );
			return rest_ensure_response( [ 'ok' => true ] );
		},
	] );

	register_rest_route( 'qit-fuzz-fixture/v1', '/clean', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => '__return_true',
		'args'                => [
			'q' => [
				'type'     => 'string',
				'required' => false,
			],
		],
		'callback'            => function ( WP_REST_Request $request ) {
			// Well-behaved control: bounded, typed, no fault for any generated input.
			return rest_ensure_response( [
				'q'     => (string) $request->get_param( 'q' ),
				'items' => [],
			] );
		},
	] );
} );
