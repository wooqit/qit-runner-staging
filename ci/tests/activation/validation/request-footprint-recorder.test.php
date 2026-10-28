<?php
/**
 * Checks the recorder's hook totals outside WordPress: php request-footprint-recorder.test.php
 */

function add_action(): void {}

// Real path, as the backtrace reports it (macOS links the temp directory).
$plugins_dir = realpath( sys_get_temp_dir() ) . '/qit-request-footprint-' . getmypid();
define( 'WP_PLUGIN_DIR', $plugins_dir );
require __DIR__ . '/../test-package/bootstrap/request-footprint.php';

$failures = 0;
function check( bool $ok, string $what ): void {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $what, "\n";
	$failures += $ok ? 0 : 1;
}

// Each file records from its own frame, as a plugin's code would.
$record = [];
mkdir( "$plugins_dir/fixture", 0777, true );
for ( $i = 1; $i <= QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES + 2; $i++ ) {
	file_put_contents( "$plugins_dir/fixture/file-$i.php", '<?php return function ( array &$footprint, string $kind ) { qit_request_footprint_record( $footprint, WP_PLUGIN_DIR . "/fixture/", $kind, "SELECT ?" ); };' );
	$record[ $i ] = require "$plugins_dir/fixture/file-$i.php";
}

$footprint = [
	'sut'                => [ 'queries' => 0, 'writes' => 0, 'http' => 0, 'call_sites' => [], 'hook_totals' => [] ],
	'dropped_call_sites' => [],
];

$GLOBALS['wp_current_filter'] = [ 'init', 'query' ];
foreach ( range( 1, 21 ) as $i ) {
	$record[ $i ]( $footprint, 'queries' );
}
$GLOBALS['wp_current_filter'] = [ 'admin_init', 'query' ];
foreach ( range( 1, QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES + 2 ) as $i ) {
	$record[ $i ]( $footprint, 'queries' );
}
$record[ QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES + 2 ]( $footprint, 'writes' );
$GLOBALS['wp_current_filter'] = [ 'pre_http_request' ];
$record[1]( $footprint, 'http' );
$GLOBALS['wp_current_filter'] = [ 123, 'query' ];
$record[1]( $footprint, 'queries' );

$admin_init = $footprint['sut']['hook_totals']['admin_init'];
check( count( $footprint['dropped_call_sites'] ) > 0, 'the call-site cap was reached' );
check( 21 === $footprint['sut']['hook_totals']['init']['queries'], 'init counts all 21 reads past the call-site cap' );
check( 23 === $admin_init['queries'] && 1 === $admin_init['writes'], 'admin_init counts 22 reads and the write, which is also a query' );
check( QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES === count( $admin_init['files'] ), 'admin_init keeps ' . QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES . ' files' );
check( 2 === count( $admin_init['omitted_files'] ), 'the two files past the cap are counted as omitted' );
check( 1 === $admin_init['files'][ 'fixture/file-1.php' ]['queries'], 'a kept file has its own count' );
check( 1 === $footprint['sut']['hook_totals']['(no hook)']['http'], 'HTTP outside any other hook lands on (no hook)' );
check( isset( $footprint['sut']['hook_totals']['123'] ) && '123' === $footprint['sut']['hook_totals']['123']['hook'], 'a numeric hook name is a string' );

array_map( 'unlink', glob( "$plugins_dir/fixture/*.php" ) );
rmdir( "$plugins_dir/fixture" );
rmdir( $plugins_dir );

exit( $failures > 0 ? 1 : 0 );
