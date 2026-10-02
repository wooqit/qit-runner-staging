<?php
/**
 * Prints the qit-request-footprint annotation of a CTRF result (`qit get <id> --json-results`)
 * one line per request, call site, omitted count, hook and file, for run-known-answer.sh to match against.
 *
 * Usage: php print-request-footprint.php <ctrf-result.json>
 */

$ctrf = json_decode( (string) file_get_contents( $argv[1] ?? '' ), true );

foreach ( $ctrf['results']['tests'] ?? [] as $test ) {
	foreach ( $test['extra']['annotations'] ?? [] as $annotation ) {
		if ( ( $annotation['type'] ?? '' ) !== 'qit-request-footprint' ) {
			continue;
		}

		$footprint = json_decode( $annotation['description'], true );
		printf( "state: %s\n", $footprint['state'] );

		foreach ( $footprint['probes'] as $id => $probe ) {
			printf( "%s: %d queries, %d writes, %d HTTP calls\n", $id, $probe['queries'], $probe['writes'], $probe['http'] );

			foreach ( $probe['call_sites'] ?? [] as $site ) {
				printf( "  %s -> %s:%d (%d queries, %d writes, %d HTTP calls)\n", $site['hook'], $site['file'], $site['line'], $site['queries'], $site['writes'], $site['http'] );
			}
			printf( "%s omitted call sites: %d\n", $id, $probe['omitted_call_sites'] ?? 0 );

			foreach ( $probe['hook_totals'] ?? [] as $hook ) {
				printf( "%s hook %s: %d queries, %d writes, %d HTTP calls\n", $id, $hook['hook'], $hook['queries'], $hook['writes'], $hook['http'] );

				foreach ( $hook['files'] as $file ) {
					printf( "%s hook %s in %s: %d queries, %d writes, %d HTTP calls\n", $id, $hook['hook'], $file['file'], $file['queries'], $file['writes'], $file['http'] );
				}
			}
		}

		exit( 0 );
	}
}

fwrite( STDERR, "The result has no qit-request-footprint annotation.\n" );
exit( 1 );
