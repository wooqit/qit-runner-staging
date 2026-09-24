<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../check-db-reserved-words.php';

/**
 * Unit tests for the DB reserved words scanner.
 *
 * Two layers:
 *  1. Focused unit tests against cd_scan_source() with inline PHP snippets — these
 *     are the executable spec for what does and does not get flagged.
 *  2. A fixture-corpus test that walks data/fixtures/{should_flag,should_not_flag}.
 *     Every newly reported false positive should be dropped into should_not_flag/
 *     as a regression file, and every confirmed bug into should_flag/.
 */
class DbReservedWordsTest extends TestCase {

	/**
	 * Convenience: scan a snippet and return just the flagged reserved words.
	 *
	 * @return string[]
	 */
	private function flagged_words( string $php ): array {
		return array_map(
			static fn( array $m ) => $m['word'] ?? null,
			array_map(
				static function ( array $m ): array {
					// Recover the bare word from the message for easy assertions.
					preg_match( '/Unquoted SQL identifier "([^"]+)"/', $m['message'], $matches );

					return [ 'word' => $matches[1] ?? '' ];
				},
				cd_scan_source( $php )
			)
		);
	}

	// ── True negatives: must NOT flag ───────────────────────────────────────────

	/**
	 * The original reported false positive: a reserved word inside a translatable
	 * label string that also happens to contain the word "from".
	 */
	public function test_does_not_flag_reserved_word_in_translatable_label(): void {
		$php = <<<'PHP'
<?php
$field_list['SeoByRankMath:getPrimaryCategory'] = __(
	'Primary Category from SEO by Rank Math extension',
	'woocommerce_gpf'
);
PHP;

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_php_array_key(): void {
		$php = "<?php\n\$config['rank'] = 1;\n\$config[ 'lag' ] = 2;\n";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_object_property_or_variable(): void {
		$php = "<?php\n\$rank = 1;\n\$obj->rank = 2;\n\$this->lead = 3;\n";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_inside_comments(): void {
		$php = <<<'PHP'
<?php
// SELECT rank FROM wp_things ORDER BY rank
/* SELECT lag, lead FROM wp_other */
# UPDATE wp_x SET rank = 1
$x = 1;
PHP;

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_reserved_word_inside_sql_comment(): void {
		// SQL comments inside a real query string are not identifiers. All three
		// MySQL comment forms (-- , #, /* */) must be masked before matching.
		$cases = [
			'block'        => '<?php $wpdb->get_results( "SELECT id /* rank */ FROM wp_foo" );',
			'double-dash'  => "<?php \$wpdb->get_results( \"SELECT id FROM wp_foo -- ORDER BY rank\n\" );",
			'hash'         => "<?php \$wpdb->get_results( \"SELECT id FROM wp_foo # rank lag lead\n\" );",
			'inline hint'  => '<?php $wpdb->get_results( "SELECT /*+ MAX_EXECUTION_TIME(1000) rank */ id FROM wp_foo" );',
		];

		foreach ( $cases as $label => $php ) {
			$this->assertSame( [], cd_scan_source( $php ), "comment form: {$label}" );
		}
	}

	public function test_flags_real_identifier_after_inline_comment(): void {
		// Masking a comment must not swallow a genuine reserved-word identifier that
		// follows it on the same statement.
		$php = '<?php $wpdb->get_results( "SELECT id /* note */, rank FROM wp_foo" );';

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_real_identifier_after_dashes_that_are_not_a_comment(): void {
		// `a--1` is arithmetic (unary minus), not a `-- ` comment — MySQL requires
		// whitespace after the dashes — so the dashes must not mask the rest of the
		// line and the trailing `rank` identifier must still be flagged.
		$php = '<?php $wpdb->get_results( "SELECT a--1 AS x, rank FROM wp_foo" );';

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_line_comment_with_escape_newline_masks_only_the_comment(): void {
		// The query is a double-quoted literal whose newline is the escape sequence
		// `\n` (backslash + n, two bytes), not a real newline. The `-- ` comment must
		// be masked up to — but not past — that escape-newline: `rank` inside the
		// comment is ignored, and `lag` on the next logical line is still flagged. A
		// literal-only end-of-line scan masks to end-of-string and wrongly swallows
		// `lag`.
		$php = <<<'PHP'
<?php
$wpdb->get_results( "SELECT id FROM wp_foo -- top rank\nWHERE lag = 1" );
PHP;

		$this->assertSame( [ 'lag' ], $this->flagged_words( $php ) );
	}

	public function test_line_comment_with_crlf_escape_newline_masks_only_the_comment(): void {
		// Same as above but with a `\r\n` escape sequence — the comment must end at
		// the `\r`, leaving the `lag` identifier on the next logical line flagged.
		$php = <<<'PHP'
<?php
$wpdb->get_results( "SELECT id FROM wp_foo -- top rank\r\nWHERE lag = 1" );
PHP;

		$this->assertSame( [ 'lag' ], $this->flagged_words( $php ) );
	}

	public function test_dashes_directly_before_escape_newline_open_a_comment(): void {
		// `--\n`: the dashes are immediately followed by an escape-sequence newline,
		// which is the whitespace MySQL requires to open a line comment. Detection
		// must treat this as a comment starter (not arithmetic) — the `rank` on the
		// next logical line is a real identifier and must still be flagged.
		$php = <<<'PHP'
<?php
$wpdb->get_results( "SELECT id FROM wp_foo --\nWHERE rank = 1" );
PHP;

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_does_not_flag_reserved_word_in_comment_started_by_escape_newline_dashes(): void {
		// The `--` is immediately followed by an escape-sequence newline opening an
		// empty comment, then a real `-- ` comment containing `rank` on the next
		// logical line. The reserved word in that comment must be masked, not flagged.
		$php = <<<'PHP'
<?php
$wpdb->get_results( "SELECT id FROM wp_foo --\n-- ORDER BY rank\n" );
PHP;

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_escape_sequence_comment_detection_respects_php_string_type(): void {
		$double_quoted = <<<'PHP'
<?php
$wpdb->get_results( "SELECT id FROM wp_foo --\t rank" );
PHP;

		$single_quoted = <<<'PHP'
<?php
$wpdb->get_results( 'SELECT id FROM wp_foo --\t rank' );
PHP;

		$heredoc = <<<'PHP'
<?php
$sql = <<<SQL
SELECT id FROM wp_foo --\t rank
SQL;
$wpdb->query( $sql );
PHP;

		$nowdoc = <<<'PHP'
<?php
$sql = <<<'SQL'
SELECT id FROM wp_foo --\t rank
SQL;
$wpdb->query( $sql );
PHP;

		$this->assertSame(
			[],
			cd_scan_source( $double_quoted ),
			'Double-quoted \t is a runtime tab, so it opens a comment.'
		);
		$this->assertSame(
			[ 'rank' ],
			$this->flagged_words( $single_quoted ),
			'Single-quoted \t is literal, so it is not a comment.'
		);
		$this->assertSame( [], cd_scan_source( $heredoc ), 'Heredoc \t is a runtime tab, so it opens a comment.' );
		$this->assertSame( [ 'rank' ], $this->flagged_words( $nowdoc ), 'Nowdoc \t is literal, so it is not a comment.' );
	}

	public function test_does_not_treat_comment_marker_inside_string_value_as_comment(): void {
		// The `--` lives inside a SQL string literal value, so it is data, not a
		// comment: the trailing `rank` identifier must still be flagged.
		$php = '<?php $wpdb->get_results( "SELECT \'-- not a comment\' AS note, rank FROM wp_foo" );';

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_does_not_flag_already_backticked_identifier(): void {
		$php = '<?php $wpdb->get_results( "SELECT id, `rank` FROM wp_foo ORDER BY `rank`" );';

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_reserved_word_as_function_call(): void {
		// RANK() OVER (...) is a legitimate use of the window function, not a column.
		$php = '<?php $wpdb->get_results( "SELECT RANK() OVER (ORDER BY score) FROM wp_foo" );';

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_substring_of_longer_identifier(): void {
		$php = '<?php $wpdb->get_results( "SELECT rank_math_score, page_rank FROM wp_foo" );';

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_prose_passed_to_translation_even_with_sql_words(): void {
		$php = "<?php esc_html__( 'Update the rank order from highest to lowest', 'td' );";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_prose_that_looks_like_select_from_shape(): void {
		$php = "<?php esc_html__( 'Select rank from the list', 'td' );";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_non_db_query_or_prepare_methods(): void {
		$cases = [
			'graphql query' => '<?php $client->query( "query Product { rank lead }" );',
			'search query'  => '<?php $search->query( "rank lead" );',
			'formatter'     => '<?php $formatter->prepare( "rank lead" );',
		];

		foreach ( $cases as $label => $php ) {
			$this->assertSame( [], cd_scan_source( $php ), $label );
		}
	}

	public function test_does_not_flag_value_argument_of_prepare(): void {
		// prepare()'s second+ arguments are bound values, not identifiers. The
		// `rank` value must NOT be flagged; only the `rank` column in the SQL is.
		$php = "<?php \$wpdb->prepare( \"SELECT id FROM wp_foo WHERE label = %s\", 'rank' );";

		$this->assertSame( [], cd_scan_source( $php ), 'A bound value of `rank` is not a column identifier.' );
	}

	public function test_does_not_flag_value_argument_of_nested_prepare(): void {
		// The classic $wpdb->query( $wpdb->prepare( $sql, $value ) ) nesting: the
		// outer query() must not mark the inner prepare()'s value argument as SQL.
		$php = "<?php \$wpdb->query( \$wpdb->prepare( \"SELECT id FROM wp_foo WHERE label = %s\", 'rank' ) );";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	/**
	 * The idiomatic $wpdb->prepare() form passes replacements as a list — either an
	 * array, a short array, or variadic positional values. A reserved word used as
	 * a replacement value must never be scanned as a SQL identifier, regardless of
	 * how the replacements are passed.
	 */
	public function test_does_not_flag_reserved_words_in_prepare_replacements_list(): void {
		$cases = [
			'array'      => '<?php $wpdb->get_results( $wpdb->prepare( "SELECT id FROM wp_foo WHERE a = %s AND b = %s", array( \'rank\', \'lead\' ) ) );',
			'short array' => '<?php $wpdb->prepare( "SELECT id FROM wp_foo WHERE a = %s", [ \'rank\', \'lag\' ] );',
			'variadic'   => '<?php $wpdb->prepare( "SELECT id FROM wp_foo WHERE a = %s AND b = %s", \'rank\', \'lead\' );',
		];

		foreach ( $cases as $label => $php ) {
			$this->assertSame( [], cd_scan_source( $php ), "replacements form: {$label}" );
		}
	}

	public function test_flags_sql_identifier_but_not_replacements_list(): void {
		// The `rank` column in the query (arg 0) flags; the `lead` replacement
		// value (in the arg-1 array) does not.
		$php = "<?php \$wpdb->prepare( \"SELECT rank FROM wp_foo WHERE a = %s\", array( 'lead' ) );";

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_does_not_flag_non_sql_argument_after_query_string(): void {
		// get_results()'s second argument (output type constant) and any trailing
		// args are not SQL. A reserved word there must not flag.
		$php = "<?php \$wpdb->get_results( \"SELECT id FROM wp_foo\", 'rank' );";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_does_not_flag_reserved_word_as_quoted_sql_value(): void {
		// A reserved word used as a string *value* inside real SQL (a WHERE
		// comparison, an IN list) is data, not a column identifier, and must not
		// flag. This is an extremely common pattern, so it is a key regression guard.
		$cases = [
			'<?php $wpdb->get_results( "SELECT id FROM wp_foo WHERE status = \'rank\'" );',
			'<?php $wpdb->get_results( "SELECT id FROM wp_foo WHERE label = \'primary rank label\'" );',
			'<?php $wpdb->get_results( "SELECT id FROM wp_foo WHERE label LIKE \'%rank%\'" );',
			'<?php $wpdb->get_results( "SELECT id FROM wp_foo WHERE type IN (\'lead\', \'lag\')" );',
			'<?php $wpdb->get_var( "SELECT COUNT(*) FROM wp_foo WHERE label = \'to_date\'" );',
		];

		foreach ( $cases as $php ) {
			$this->assertSame( [], cd_scan_source( $php ), $php );
		}
	}

	// ── True positives: must flag ───────────────────────────────────────────────

	public function test_flags_reserved_word_in_wpdb_query(): void {
		$php = '<?php $wpdb->get_results( "SELECT id, rank FROM wp_foo ORDER BY rank ASC" );';

		$words = $this->flagged_words( $php );

		$this->assertSame( [ 'rank', 'rank' ], $words, 'Both bare `rank` identifiers should be flagged.' );
	}

	public function test_flags_reserved_word_in_query_assigned_to_variable_by_shape(): void {
		// Not directly inside a sink call — caught by the SHAPE signal (starts with SELECT).
		$php = "<?php\n\$sql = \"SELECT lag FROM wp_metrics\";\n\$wpdb->query( \$sql );\n";

		$this->assertSame( [ 'lag' ], $this->flagged_words( $php ) );
	}

	public function test_flags_reserved_word_in_concatenated_query_assigned_to_sql_variable(): void {
		$php = <<<'PHP'
<?php
$sql = "SELECT id FROM wp_foo " . $where . " ORDER BY rank";
$wpdb->query( $sql );
PHP;

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_reserved_word_in_concatenated_sql_fragment(): void {
		// The "rank" fragment has no leading verb; it is caught because it sits
		// inside the sink's argument list.
		$php = "<?php\n\$wpdb->query( \"SELECT id FROM wp_foo \" . \$where . \" ORDER BY rank\" );\n";

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_sql_argument_of_prepare_but_not_value(): void {
		// The `rank` column in the SQL (arg 0) flags exactly once; the `rank`
		// value (arg 1) does not.
		$php = "<?php \$wpdb->prepare( \"SELECT rank FROM wp_foo WHERE label = %s\", 'rank' );";

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_db_looking_query_method_receivers(): void {
		$cases = [
			'wpdb'     => '<?php $wpdb->query( "SELECT rank FROM wp_foo" );',
			'db var'   => '<?php $db->query( "SELECT rank FROM wp_foo" );',
			'property' => '<?php $this->db->query( "SELECT rank FROM wp_foo" );',
		];

		foreach ( $cases as $label => $php ) {
			$this->assertSame( [ 'rank' ], $this->flagged_words( $php ), $label );
		}
	}

	public function test_flags_reserved_word_in_nested_prepare_sql(): void {
		// The identifier inside the nested prepare's SQL (arg 0) is still flagged.
		$php = "<?php \$wpdb->query( \$wpdb->prepare( \"SELECT rank FROM wp_foo WHERE id = %d\", \$id ) );";

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_mysqli_query_second_argument(): void {
		// Procedural mysqli_query($link, $query): the SQL is the SECOND argument.
		// Use a concatenated query so the SHAPE signal can't carry it — this
		// proves the sink targets arg index 1, not arg 0.
		$php = "<?php mysqli_query( \$link, \"SELECT id FROM wp_foo \" . \$where . \" ORDER BY rank\" );";

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_to_date_symbol(): void {
		$php = '<?php $wpdb->get_var( "SELECT to_date FROM wp_orders" );';

		$this->assertSame( [ 'to_date' ], $this->flagged_words( $php ) );
	}

	public function test_flags_prepare_sink_with_placeholder(): void {
		// prepare() is the most common wpdb query method. Confirm it is treated as
		// a sink and that a %d placeholder in the SQL does not interfere with
		// finding the bare `rank` identifier.
		$php = '<?php $wpdb->get_results( $wpdb->prepare( "SELECT rank FROM wp_foo WHERE id = %d", $id ) );';

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	public function test_flags_insert_into_column_list(): void {
		$php = '<?php $wpdb->query( "INSERT INTO wp_foo (id, rank, lag) VALUES (1, 2, 3)" );';

		$this->assertSame( [ 'rank', 'lag' ], $this->flagged_words( $php ) );
	}

	/**
	 * The SHAPE patterns are hand-written, start-anchored regexes — easy to get
	 * subtly wrong. Each case is a variable-assigned query (no sink) so detection
	 * relies purely on cd_looks_like_sql() recognising the statement shape.
	 *
	 * @dataProvider provide_statement_shapes
	 */
	public function test_flags_reserved_word_by_statement_shape( string $query, array $expected ): void {
		$php = sprintf( "<?php\n\$sql = \"%s\";\n\$wpdb->query( \$sql );\n", $query );

		$this->assertSame( $expected, $this->flagged_words( $php ), $query );
	}

	/** @return array<string, array{0: string, 1: string[]}> */
	public function provide_statement_shapes(): array {
		return [
			'SELECT…FROM' => [ 'SELECT id, rank FROM wp_foo', [ 'rank' ] ],
			'UPDATE…SET'  => [ 'UPDATE wp_foo SET rank = rank + 1 WHERE id = 5', [ 'rank', 'rank' ] ],
			'DELETE FROM' => [ 'DELETE FROM wp_foo WHERE rank > 100', [ 'rank' ] ],
			'INSERT INTO' => [ 'INSERT INTO wp_foo (rank) VALUES (1)', [ 'rank' ] ],
			'REPLACE INTO' => [ 'REPLACE INTO wp_foo (rank) VALUES (1)', [ 'rank' ] ],
			'ALTER TABLE' => [ 'ALTER TABLE wp_foo ADD COLUMN rank INT', [ 'rank' ] ],
			'CREATE TABLE' => [ 'CREATE TABLE wp_foo ( rank INT )', [ 'rank' ] ],
			'TRUNCATE TABLE' => [ 'TRUNCATE TABLE wp_rank', [] ], // `wp_rank` is a longer identifier, not a match
		];
	}

	public function test_flags_in_dbdelta_create_table(): void {
		$php = <<<'PHP'
<?php
dbDelta( "CREATE TABLE wp_foo ( id BIGINT, rank INT, lead INT )" );
PHP;

		// Findings are sorted by source position: `rank` precedes `lead`.
		$this->assertSame( [ 'rank', 'lead' ], $this->flagged_words( $php ) );
	}

	public function test_flags_interpolated_query_assigned_to_variable(): void {
		// The standard dbDelta idiom: an interpolated, multi-line CREATE TABLE
		// assigned to a variable. PHP splits this into fragments around
		// {$wpdb->prefix}; the reserved words live in the trailing fragment, which
		// is only reachable when the whole interpolated span is judged together.
		$php = <<<'PHP'
<?php
$sql = "CREATE TABLE {$wpdb->prefix}metrics (
	id BIGINT NOT NULL,
	rank INT NOT NULL,
	lag INT NOT NULL
)";
dbDelta( $sql );
PHP;

		$this->assertSame( [ 'rank', 'lag' ], $this->flagged_words( $php ) );
	}

	public function test_does_not_flag_prose_beginning_with_sql_verb(): void {
		// "Update"/"Delete"/"Create" are common UI verbs. A leading verb alone must
		// not qualify a string as SQL — only a full statement shape (UPDATE…SET) does.
		$php = "<?php __( 'Update the rank order from highest lead to lowest', 'td' );";

		$this->assertSame( [], cd_scan_source( $php ) );
	}

	public function test_flags_heredoc_query(): void {
		$php = <<<'PHP'
<?php
$sql = <<<SQL
SELECT id, rank
FROM wp_foo
SQL;
$wpdb->query( $sql );
PHP;

		$this->assertSame( [ 'rank' ], $this->flagged_words( $php ) );
	}

	// ── Message structure / position mapping ────────────────────────────────────

	public function test_reports_accurate_line_and_message_shape(): void {
		$php = "<?php\n\$x = 1;\n\$wpdb->query( \"SELECT rank FROM wp_foo\" );\n";

		$messages = cd_scan_source( $php );

		$this->assertCount( 1, $messages );
		$message = $messages[0];

		$this->assertSame( 3, $message['line'], '`rank` is on line 3.' );
		$this->assertSame( 'QITStandard.DB.ReservedWordInSQL', $message['source'] );
		$this->assertSame( 'ERROR', $message['type'] );
		$this->assertSame( 5, $message['severity'] );
		$this->assertFalse( $message['fixable'] );
		$this->assertStringContainsString( 'rank', $message['message'] );

		// Column should point at the `rank` token within the SQL string.
		$line = explode( "\n", $php )[ $message['line'] - 1 ];
		$this->assertSame( 'rank', substr( $line, $message['column'] - 1, 4 ) );
	}

	// ── cd_scan_plugin_dir(): the JSON contract consumed by normalizer.php ───────

	/** @var string[] */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->temp_dirs = [];
		parent::tearDown();
	}

	/**
	 * Create a throwaway plugin directory with the given relative-path => contents
	 * files. Registered for cleanup in tearDown().
	 *
	 * @param array<string, string> $files
	 */
	private function make_temp_plugin( array $files ): string {
		$base = sys_get_temp_dir() . '/cd_db_test_' . uniqid( '', true );
		mkdir( $base, 0777, true );
		$this->temp_dirs[] = $base;

		foreach ( $files as $relative => $contents ) {
			$path = $base . '/' . ltrim( $relative, '/' );
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $contents );
		}

		return $base;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/** Run cd_scan_plugin_dir() with its console output suppressed. */
	private function scan_dir( string $dir, ?array $reserved_words = null ): array {
		ob_start();
		$result = cd_scan_plugin_dir( $dir, $reserved_words );
		ob_end_clean();

		return $result;
	}

	public function test_scan_plugin_dir_aggregates_errors_and_builds_structure(): void {
		$dir = $this->make_temp_plugin(
			[
				'queries.php' => '<?php $wpdb->get_results( "SELECT id, rank FROM wp_foo ORDER BY rank" );',
				'clean.php'   => "<?php\n\$rank = 1; // not SQL, no findings\n",
			]
		);

		$result = $this->scan_dir( $dir );

		$this->assertSame( 2, $result['totals']['errors'] );
		$this->assertSame( 0, $result['totals']['warnings'] );

		// Only the file with findings appears, keyed by its real path.
		$this->assertCount( 1, $result['files'] );
		$file = $result['files'][ realpath( $dir . '/queries.php' ) ];
		$this->assertSame( 2, $file['errors'] );
		$this->assertSame( 0, $file['warnings'] );
		$this->assertCount( 2, $file['messages'] );
		$this->assertSame( 'QITStandard.DB.ReservedWordInSQL', $file['messages'][0]['source'] );
	}

	public function test_scan_plugin_dir_splits_warning_type(): void {
		// All shipped reserved words are ERROR, so exercise the WARNING branch with
		// a custom list. This pins down both the type plumbing and the error/warning
		// split that normalizer.php and the metrics builder rely on.
		$reserved = [
			[
				'word'   => 'rank',
				'reason' => 'Reserved only in a future version.',
				'since'  => 'MariaDB 99.0',
				'ticket' => 'https://example.test/MDEV-0',
				'type'   => 'WARNING',
			],
		];

		$dir = $this->make_temp_plugin(
			[ 'q.php' => '<?php $wpdb->get_results( "SELECT rank FROM wp_foo" );' ]
		);

		$result = $this->scan_dir( $dir, $reserved );

		$this->assertSame( 0, $result['totals']['errors'] );
		$this->assertSame( 1, $result['totals']['warnings'] );

		$file = $result['files'][ realpath( $dir . '/q.php' ) ];
		$this->assertSame( 1, $file['warnings'] );
		$this->assertSame( 'WARNING', $file['messages'][0]['type'] );
	}

	public function test_scan_plugin_dir_skips_unreadable_file_and_continues(): void {
		// An unreadable file must not abort the scan: readable files are still
		// reported, and no exception/TypeError is thrown.
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Cannot make a file unreadable while running as root.' );
		}

		$dir = $this->make_temp_plugin(
			[
				'readable.php'   => '<?php $wpdb->get_results( "SELECT rank FROM wp_foo" );',
				'unreadable.php' => '<?php $wpdb->get_results( "SELECT lag FROM wp_bar" );',
			]
		);

		$unreadable = $dir . '/unreadable.php';
		chmod( $unreadable, 0000 );

		if ( is_readable( $unreadable ) ) {
			chmod( $unreadable, 0644 ); // let tearDown clean up
			$this->markTestSkipped( 'Filesystem does not enforce the unreadable permission here.' );
		}

		try {
			$result = $this->scan_dir( $dir );
		} finally {
			chmod( $unreadable, 0644 ); // restore so tearDown can remove it
		}

		// The readable file was scanned; the unreadable one was skipped, not fatal.
		$this->assertSame( 1, $result['totals']['errors'] );
		$this->assertArrayHasKey( realpath( $dir . '/readable.php' ), $result['files'] );
		$this->assertArrayNotHasKey( realpath( $unreadable ), $result['files'] );
	}

	public function test_scan_plugin_dir_skips_configured_directories(): void {
		// vendor/ is in cd_skip_dirs(); SQL there must not be scanned.
		$dir = $this->make_temp_plugin(
			[ 'vendor/lib.php' => '<?php $wpdb->get_results( "SELECT rank FROM wp_foo" );' ]
		);

		$result = $this->scan_dir( $dir );

		$this->assertSame( 0, $result['totals']['errors'] );
		$this->assertSame( [], $result['files'] );
	}

	// ── Fixture corpus ──────────────────────────────────────────────────────────

	/**
	 * @dataProvider provide_should_flag_fixtures
	 */
	public function test_should_flag_fixtures( string $file ): void {
		$messages = cd_scan_source( (string) file_get_contents( $file ) );

		$this->assertNotEmpty(
			$messages,
			sprintf( 'Expected %s to produce at least one finding.', basename( $file ) )
		);
	}

	/**
	 * @dataProvider provide_should_not_flag_fixtures
	 */
	public function test_should_not_flag_fixtures( string $file ): void {
		$messages = cd_scan_source( (string) file_get_contents( $file ) );

		$this->assertSame(
			[],
			$messages,
			sprintf(
				'Expected %s to produce no findings, got: %s',
				basename( $file ),
				implode( '; ', array_column( $messages, 'message' ) )
			)
		);
	}

	/** @return array<string, string[]> */
	public function provide_should_flag_fixtures(): array {
		return $this->fixtures( 'should_flag' );
	}

	/** @return array<string, string[]> */
	public function provide_should_not_flag_fixtures(): array {
		return $this->fixtures( 'should_not_flag' );
	}

	/** @return array<string, string[]> */
	private function fixtures( string $bucket ): array {
		$dir   = __DIR__ . '/../data/fixtures/' . $bucket;
		$cases = [];

		foreach ( glob( $dir . '/*.php' ) ?: [] as $file ) {
			$cases[ basename( $file ) ] = [ $file ];
		}

		return $cases;
	}
}
