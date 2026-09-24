<?php

/**
 * DB Reserved Words Scanner
 *
 * Scans PHP files for SQL reserved words used as unquoted column identifiers.
 * Part of the Code Compatibility test. Catches SQL that will break on current or
 * future MariaDB/MySQL versions due to reserved word collisions.
 *
 * Reserved words are sourced from the MariaDB lex.h symbols[] and sql_functions[] arrays:
 * https://github.com/MariaDB/server/blob/main/sql/lex.h
 *
 * ── How detection works ──────────────────────────────────────────────────────
 * The scanner does NOT pattern-match raw PHP lines. Doing so produces false
 * positives on ordinary prose: a translatable label such as
 * "Primary Category from SEO by Rank Math extension" contains the word "Rank"
 * (looks like a bare identifier) and the word "from" (looks like SQL context),
 * so a line-based heuristic flags it even though there is no SQL anywhere near.
 *
 * Instead, the scanner tokenises the PHP source (token_get_all) and only inspects
 * string literals that are actually SQL. A string is treated as SQL when either:
 *   1. SINK  — it sits in the query-argument position of a known database query
 *              call ($wpdb->query/get_results/get_var/get_col/get_row/prepare,
 *              dbDelta(), mysqli_query(), ...). Only that argument is treated as
 *              SQL — a sink's other arguments (e.g. prepare()'s bound values) are
 *              not — and nested calls are left for their own detection. This
 *              catches concatenated/fragmented queries that don't individually
 *              start with a verb.
 *   2. SHAPE — the string is assigned to a SQL-looking variable and the assigned
 *              expression begins with a SQL statement verb (SELECT/INSERT/UPDATE/...).
 *              This catches queries assigned to a variable and only later handed
 *              to a sink without treating arbitrary UI strings as SQL.
 * Comments are tokens too, so they are skipped structurally rather than guessed.
 *
 * Reserved words are then matched only within those SQL strings. Prose, array
 * keys, option names, hook names, and variable/property names are never scanned.
 *
 * This trades a few false negatives (SQL assembled in genuinely opaque ways) for
 * the elimination of prose false positives — the correct trade for a linter.
 *
 * Output: PHPCS-compatible JSON, integrated into the Code Compatibility report
 * under tool.db_reserved_words.
 */

/**
 * Reserved words from MariaDB lex.h symbols[] and sql_functions[] arrays that conflict
 * with commonly used column names.
 *
 * Each entry has:
 *   word   - lowercase SQL identifier to scan for
 *   reason - human-readable explanation of why it breaks
 *   since  - earliest version where this is reserved
 *   ticket - reference issue/MDEV
 *   type   - 'ERROR' for words reserved in current widely-deployed versions (breaks today),
 *            'WARNING' for words reserved only in unreleased/future versions (breaks on upgrade)
 *
 * Source: https://github.com/MariaDB/server/blob/main/sql/lex.h
 *
 * To add a new reserved word, append an entry to this array.
 *
 * @return array<int, array{word: string, reason: string, since: string, ticket: string, type: string}>
 */
function cd_db_reserved_words(): array {
	return [
		// ── From symbols[] ──────────────────────────────────────────────────────
		[
			'word'   => 'to_date',
			'reason' => 'TO_DATE() is reserved in MariaDB (MDEV-19683). '
			            . 'Using `to_date` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.x (lex.h symbols[])',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-19683',
			'type'   => 'ERROR',
		],

		// ── From sql_functions[] — window functions reserved since MariaDB 10.2 ─
		[
			'word'   => 'rank',
			'reason' => 'RANK() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `rank` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'dense_rank',
			'reason' => 'DENSE_RANK() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `dense_rank` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'row_number',
			'reason' => 'ROW_NUMBER() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `row_number` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'lead',
			'reason' => 'LEAD() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `lead` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'lag',
			'reason' => 'LAG() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `lag` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'ntile',
			'reason' => 'NTILE() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `ntile` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'cume_dist',
			'reason' => 'CUME_DIST() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `cume_dist` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'percent_rank',
			'reason' => 'PERCENT_RANK() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `percent_rank` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'first_value',
			'reason' => 'FIRST_VALUE() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `first_value` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'last_value',
			'reason' => 'LAST_VALUE() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `last_value` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
		[
			'word'   => 'nth_value',
			'reason' => 'NTH_VALUE() is a reserved window function in MariaDB 10.2+ and MySQL 8.0+. '
			            . 'Using `nth_value` as an unquoted column name causes a syntax error.',
			'since'  => 'MariaDB 10.2 / MySQL 8.0',
			'ticket' => 'https://jira.mariadb.org/browse/MDEV-10524',
			'type'   => 'ERROR',
		],
	];
}

/**
 * Directories to skip when scanning. These are unlikely to contain plugin SQL
 * and generate high false-positive rates.
 *
 * @return string[]
 */
function cd_skip_dirs(): array {
	return [
		'vendor',
		'vendor-prefixed',
		'vendor-scoped',
		'tests',
		'dist',
		'build',
		'node_modules',
	];
}

/**
 * Database query "sinks" — when a string literal sits in the SQL-argument
 * position of one of these calls, its contents are treated as SQL.
 *
 * Each entry maps the call name to the 0-based index of its SQL argument. Only
 * that argument is treated as SQL: a sink's other arguments are values, not
 * identifiers (e.g. the second argument of $wpdb->prepare( 'SELECT …', 'rank' )
 * is a bound value, not a column name), and must not be scanned.
 *
 * SINK methods are matched only when the receiver looks database-ish ($wpdb,
 * $this->wpdb, $db, $this->db, ...). Method names such as query() are too common
 * in non-SQL clients (GraphQL, search, HTTP APIs) to scan on name alone. SINK
 * functions are bare function calls. Note mysqli_query()'s procedural signature
 * is mysqli_query($link, $query), so its SQL argument is index 1.
 *
 * Note: insert()/update()/delete()/replace() are deliberately excluded — they
 * take column-name arrays, not raw SQL strings, and would need separate handling.
 *
 * @return array{methods: array<string, int>, functions: array<string, int>}
 */
function cd_sql_sinks(): array {
	return [
		'methods'   => [
			'query'       => 0,
			'get_results' => 0,
			'get_var'     => 0,
			'get_col'     => 0,
			'get_row'     => 0,
			'prepare'     => 0,
		],
		'functions' => [
			'dbdelta'      => 0,
			'mysql_query'  => 0,
			'mysqli_query' => 1,
		],
	];
}

/**
 * Recursively collect PHP files from $dir, skipping $skip_dirs by directory name.
 *
 * @param string   $dir
 * @param string[] $skip_dirs
 *
 * @return string[]
 */
function cd_get_php_files( string $dir, array $skip_dirs ): array {
	$files    = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			function ( SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator ) use ( $skip_dirs ): bool {
				if ( $iterator->hasChildren() ) {
					return ! in_array( $file->getFilename(), $skip_dirs, true );
				}

				return $file->getExtension() === 'php';
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( $file->getExtension() === 'php' ) {
			$files[] = $file->getRealPath();
		}
	}

	return $files;
}

/**
 * Does this string value look like a SQL statement? Used as the "SHAPE" signal
 * for SQL detection (the fallback when a query is built in a variable before
 * being handed to a sink).
 *
 * Each pattern is anchored to the START of the string AND requires a second
 * structural keyword (SELECT…FROM, UPDATE…SET, INSERT INTO, DELETE FROM, …).
 * A leading verb alone is not enough: ordinary UI/prose strings frequently begin
 * with "Update", "Delete" or "Create" ("Update the rank order from highest…"),
 * and matching those is precisely the false-positive class this scanner avoids.
 */
function cd_looks_like_sql( string $value ): bool {
	$patterns = [
		'/^\s*\(*\s*SELECT\b.*\bFROM\b/is',
		'/^\s*\(*\s*INSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)*INTO\b/i',
		'/^\s*\(*\s*REPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)*INTO\b/i',
		'/^\s*\(*\s*UPDATE\b.*\bSET\b/is',
		'/^\s*\(*\s*DELETE\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*FROM\b/i',
		'/^\s*\(*\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i',
		'/^\s*\(*\s*ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\b/i',
		'/^\s*\(*\s*DROP\s+(?:TEMPORARY\s+)?TABLE\b/i',
		'/^\s*\(*\s*TRUNCATE\s+(?:TABLE\s+)?\S/i',
	];

	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $value ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Strip the surrounding quotes from a T_CONSTANT_ENCAPSED_STRING token's raw text.
 * Only used to inspect the string's content for the SHAPE signal — escape
 * sequences inside are irrelevant because we only look at the leading verb.
 */
function cd_unquote( string $token_text ): string {
	if ( strlen( $token_text ) >= 2 ) {
		$first = $token_text[0];
		$last  = $token_text[ strlen( $token_text ) - 1 ];
		if ( ( $first === "'" || $first === '"' ) && $first === $last ) {
			return substr( $token_text, 1, -1 );
		}
	}

	return $token_text;
}

/**
 * Does a PHP string literal interpret common escape sequences such as \n and \t?
 */
function cd_php_string_escapes_active( string $token_text ): bool {
	return $token_text !== '' && $token_text[0] === '"';
}

/**
 * Is a heredoc/nowdoc token a nowdoc? Nowdoc markers are single-quoted and do
 * not interpret escape sequences.
 */
function cd_is_nowdoc_start( string $token_text ): bool {
	return preg_match( '/<<<\s*\'/', $token_text ) === 1;
}

/**
 * Extract a useful identifier name from a PHP token.
 *
 * @param mixed $token
 */
function cd_token_identifier_name( $token ): ?string {
	if ( ! is_array( $token ) ) {
		return null;
	}

	if ( $token[0] === T_VARIABLE ) {
		return strtolower( ltrim( $token[1], '$' ) );
	}

	if ( $token[0] === T_STRING ) {
		return strtolower( $token[1] );
	}

	return null;
}

/**
 * Does an object method receiver look like a DB connection/wrapper?
 *
 * This intentionally recognises common WordPress/plugin shapes such as $wpdb,
 * $db, $this->wpdb, $this->db, $this->database, and $mysqli while rejecting
 * generic clients such as $client->query() or $search->query().
 *
 * @param array<int, mixed> $tokens
 * @param callable          $prev_meaningful
 */
function cd_method_receiver_looks_like_db(
	array $tokens,
	int $object_operator_index,
	callable $prev_meaningful
): bool {
	$receiver_index = $prev_meaningful( $object_operator_index );
	if ( $receiver_index === null ) {
		return false;
	}

	$name = cd_token_identifier_name( $tokens[ $receiver_index ] );
	if ( $name === null ) {
		return false;
	}

	$db_names = [
		'wpdb',
		'db',
		'database',
		'database_connection',
		'db_connection',
		'conn',
		'connection',
		'mysql',
		'mysqli',
		'pdo',
	];

	if ( in_array( $name, $db_names, true ) ) {
		return true;
	}

	return str_starts_with( $name, 'wpdb' )
	       || str_starts_with( $name, 'database' )
	       || str_starts_with( $name, 'db_' )
	       || str_ends_with( $name, '_wpdb' )
	       || str_ends_with( $name, '_db' )
	       || str_ends_with( $name, '_database' )
	       || str_ends_with( $name, '_connection' )
	       || str_ends_with( $name, '_conn' )
	       || str_ends_with( $name, '_mysqli' )
	       || str_ends_with( $name, '_pdo' );
}

/**
 * Does an assignment target look intended to hold SQL?
 *
 * @param array<int, mixed> $tokens
 * @param callable          $prev_meaningful
 */
function cd_assignment_target_looks_like_sql( array $tokens, int $assignment_index, callable $prev_meaningful ): bool {
	$names_seen = 0;

	for ( $i = $prev_meaningful( $assignment_index ); $i !== null && $names_seen < 8; $i = $prev_meaningful( $i ) ) {
		$token = $tokens[ $i ];

		if ( $token === ';' || $token === ',' || $token === '(' || $token === '{' ) {
			break;
		}

		$name = cd_token_identifier_name( $token );
		if ( $name === null ) {
			continue;
		}

		$names_seen++;

		$sql_names = [ 'sql', 'query', 'queries', 'statement', 'stmt', 'ddl', 'schema', 'dbdelta' ];
		if ( in_array( $name, $sql_names, true )
		     || str_starts_with( $name, 'sql_' )
		     || str_ends_with( $name, '_sql' )
		     || str_starts_with( $name, 'query_' )
		     || str_ends_with( $name, '_query' )
		     || str_starts_with( $name, 'statement_' )
		     || str_ends_with( $name, '_statement' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Mark top-level string tokens in SQL-looking assignments.
 *
 * This recovers common patterns such as:
 *   $sql = "SELECT id FROM wp_foo " . $where . " ORDER BY rank";
 * The trailing fragment does not look like SQL by itself, but the whole assigned
 * expression does. Nested calls are not marked here so value arguments inside
 * helpers like sprintf() are not mistaken for identifiers.
 *
 * @param array<int, mixed> $tokens
 * @param bool[]            $in_sql_assignment
 */
function cd_mark_sql_assignment_tokens( array $tokens, int $assignment_index, array &$in_sql_assignment ): void {
	$count    = count( $tokens );
	$depth    = 0;
	$groups   = [];
	$combined = '';

	for ( $i = $assignment_index + 1; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( $depth === 0 && ( $token === ';' || $token === ',' ) ) {
			break;
		}

		if ( $depth === 0 && is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
			$text      = cd_unquote( $token[1] );
			$groups[]  = [ 'indices' => [ $i ] ];
			$combined .= $text;
			continue;
		}

		$is_dquote  = $token === '"';
		$is_heredoc = is_array( $token ) && $token[0] === T_START_HEREDOC;

		if ( $depth === 0 && ( $is_dquote || $is_heredoc ) ) {
			$indices = [];
			$text    = '';

			for ( $j = $i + 1; $j < $count; $j++ ) {
				$inner_token = $tokens[ $j ];

				if ( $is_dquote && $inner_token === '"' ) {
					break;
				}
				if ( $is_heredoc && is_array( $inner_token ) && $inner_token[0] === T_END_HEREDOC ) {
					break;
				}

				if ( is_array( $inner_token ) && $inner_token[0] === T_ENCAPSED_AND_WHITESPACE ) {
					$indices[] = $j;
					$text     .= $inner_token[1];
				}
			}

			if ( ! empty( $indices ) ) {
				$groups[]  = [ 'indices' => $indices ];
				$combined .= $text;
			}

			$i = $j;
			continue;
		}

		if ( in_array( $token, [ '(', '[', '{' ], true ) ) {
			$depth++;
			continue;
		}

		if ( in_array( $token, [ ')', ']', '}' ], true ) && $depth > 0 ) {
			$depth--;
		}
	}

	if ( empty( $groups ) || ! cd_looks_like_sql( $combined ) ) {
		return;
	}

	foreach ( $groups as $group ) {
		foreach ( $group['indices'] as $index ) {
			$in_sql_assignment[ $index ] = true;
		}
	}
}

/**
 * Extract the raw text and absolute byte offset of every string token in
 * $content that is part of a SQL statement.
 *
 * @param string $content PHP source.
 *
 * @return array<int, array{text: string, offset: int, escapes_active: bool}>
 *         Each scannable SQL string in source order. `text` is the string's
 *         INNER content (delimiting quotes stripped) and `offset` is the absolute
 *         byte offset of that inner content in $content, so match offsets map
 *         directly back to the source. Scanning inner content (rather than the
 *         raw token) means a reserved word at a string boundary — e.g. the `rank`
 *         in a concatenated `" ORDER BY rank"` fragment — is no longer hidden
 *         behind the closing quote. `escapes_active` indicates whether PHP will
 *         interpret escape sequences such as \n, \r, and \t for that string.
 */
function cd_extract_sql_string_tokens( string $content ): array {
	$tokens = token_get_all( $content );
	$count  = count( $tokens );

	// Object operators that introduce a method call (-> and ?->, the latter PHP 8+).
	$object_operators = [ T_OBJECT_OPERATOR ];
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$object_operators[] = T_NULLSAFE_OBJECT_OPERATOR;
	}

	// Precompute the absolute byte offset where each token starts.
	$offsets = [];
	$pos     = 0;
	foreach ( $tokens as $i => $token ) {
		$offsets[ $i ] = $pos;
		$pos          += strlen( is_array( $token ) ? $token[1] : $token );
	}

	// Index of the previous / next "meaningful" token (skipping whitespace + comments).
	$skip_ids       = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
	$prev_meaningful = static function ( int $i ) use ( $tokens, $skip_ids ): ?int {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip_ids, true ) ) {
				continue;
			}
			return $j;
		}
		return null;
	};
	$next_meaningful = static function ( int $i ) use ( $tokens, $count, $skip_ids ): ?int {
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip_ids, true ) ) {
				continue;
			}
			return $j;
		}
		return null;
	};

	// Mark every token that sits inside a SQL sink's argument list.
	$sinks             = cd_sql_sinks();
	$in_sink           = array_fill( 0, $count, false );
	$in_sql_assignment = array_fill( 0, $count, false );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( $tokens[ $i ] !== '=' ) {
			continue;
		}

		if ( ! cd_assignment_target_looks_like_sql( $tokens, $i, $prev_meaningful ) ) {
			continue;
		}

		cd_mark_sql_assignment_tokens( $tokens, $i, $in_sql_assignment );
	}

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || $token[0] !== T_STRING ) {
			continue;
		}

		$name = strtolower( $token[1] );
		$prev = $prev_meaningful( $i );
		$prev_is_object_op = $prev !== null
		                     && is_array( $tokens[ $prev ] )
		                     && in_array( $tokens[ $prev ][0], $object_operators, true );
		$prev_is_static    = $prev !== null
		                     && is_array( $tokens[ $prev ] )
		                     && $tokens[ $prev ][0] === T_DOUBLE_COLON;

		$sql_arg = null;
		if ( $prev_is_object_op && isset( $sinks['methods'][ $name ] ) ) {
			if ( ! cd_method_receiver_looks_like_db( $tokens, $prev, $prev_meaningful ) ) {
				continue;
			}
			$sql_arg = $sinks['methods'][ $name ]; // $wpdb->query(...), $this->db->prepare(...)
		} elseif ( ! $prev_is_object_op && ! $prev_is_static && isset( $sinks['functions'][ $name ] ) ) {
			$sql_arg = $sinks['functions'][ $name ]; // dbDelta(...), mysqli_query($link, ...)
		}

		if ( $sql_arg === null ) {
			continue;
		}

		$open = $next_meaningful( $i );
		if ( $open === null || $tokens[ $open ] !== '(' ) {
			continue;
		}

		// Mark only the tokens of the SQL argument, and only at this call's own
		// paren depth (depth 1). Tokens inside nested calls (depth > 1) are left
		// untouched so they are detected by their own call — e.g. the value
		// argument of a nested $wpdb->prepare(...) is not mismarked as SQL by an
		// outer $wpdb->query(...), and a sink's non-SQL arguments are skipped.
		$depth     = 0;
		$arg_index = 0;
		for ( $j = $open; $j < $count; $j++ ) {
			$tj = $tokens[ $j ];

			if ( $tj === '(' ) {
				$depth++;
				continue;
			}
			if ( $tj === ')' ) {
				$depth--;
				if ( $depth === 0 ) {
					break; // closed the sink's argument list
				}
				continue;
			}
			if ( $depth !== 1 ) {
				continue; // inside a nested call — leave it for that call's detection
			}
			if ( $tj === ',' ) {
				$arg_index++;
				if ( $arg_index > $sql_arg ) {
					break; // past the SQL argument; nothing left to mark
				}
				continue;
			}
			if ( $arg_index === $sql_arg ) {
				$in_sink[ $j ] = true;
			}
		}
	}

	// Collect SQL strings. Two forms:
	//   * Standalone literal  — a single T_CONSTANT_ENCAPSED_STRING token.
	//   * Interpolated string — a span delimited by `"` … `"` or heredoc markers,
	//     whose literal text is split into several T_ENCAPSED_AND_WHITESPACE
	//     fragments around the interpolated expressions. The reserved word often
	//     lives in a trailing fragment (the standard dbDelta idiom
	//     "CREATE TABLE {$wpdb->prefix}foo ( rank INT )"), so the whole span is
	//     judged together and every fragment of a SQL span becomes scannable.
	$result = [];
	$i       = 0;

	while ( $i < $count ) {
		$token = $tokens[ $i ];

		// Standalone, non-interpolated string literal.
		if ( is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
			$inner        = cd_unquote( $token[1] );
			$inner_offset = $offsets[ $i ] + ( $inner === $token[1] ? 0 : 1 ); // skip opening quote when stripped

			if ( $in_sink[ $i ] || $in_sql_assignment[ $i ] ) {
				$result[] = [
					'text'           => $inner,
					'offset'         => $inner_offset,
					'escapes_active' => cd_php_string_escapes_active( $token[1] ),
				];
			}

			$i++;
			continue;
		}

		// Interpolated string span: `"` … `"`  or  heredoc … T_END_HEREDOC.
		$is_dquote  = $token === '"';
		$is_heredoc = is_array( $token ) && $token[0] === T_START_HEREDOC;

		if ( $is_dquote || $is_heredoc ) {
			$j              = $i + 1;
			$fragments      = [];
			$span_sink      = false;
			$escapes_active = $is_dquote || ! cd_is_nowdoc_start( $token[1] );

			for ( ; $j < $count; $j++ ) {
				$inner_token = $tokens[ $j ];

				if ( $is_dquote && $inner_token === '"' ) {
					break;
				}
				if ( $is_heredoc && is_array( $inner_token ) && $inner_token[0] === T_END_HEREDOC ) {
					break;
				}

				if ( is_array( $inner_token ) && $inner_token[0] === T_ENCAPSED_AND_WHITESPACE ) {
					$fragments[] = [ 'text' => $inner_token[1], 'offset' => $offsets[ $j ], 'index' => $j ];
					$span_sink   = $span_sink || $in_sink[ $j ];
				}
			}

			$span_assignment = false;
			foreach ( $fragments as $fragment ) {
				$span_assignment = $span_assignment || $in_sql_assignment[ $fragment['index'] ];
			}

			if ( ! empty( $fragments ) && ( $span_sink || $span_assignment ) ) {
				foreach ( $fragments as $fragment ) {
					$result[] = [
						'text'           => $fragment['text'],
						'offset'         => $fragment['offset'],
						'escapes_active' => $escapes_active,
					];
				}
			}

			$i = $j + 1;
			continue;
		}

		$i++;
	}

	return $result;
}

/**
 * Blank out SQL comment and string-literal spans in $text, preserving every byte offset.
 *
 * Reserved words that appear inside SQL comments — `/* rank *​/`, `-- rank`,
 * `# rank` — are not identifiers and must not be flagged. Each comment span is
 * overwritten with spaces (newlines preserved) so the returned string has the
 * exact same length as the input: a match offset in the masked text still maps
 * back to the original source for accurate line/column reporting.
 *
 * String literals are masked too. A reserved word inside
 * `WHERE label = 'primary rank label'` is data, not an identifier, and must not
 * be flagged. Tracking string literals also keeps comment markers inside data
 * (`SELECT '-- not a comment' AS rank`) from swallowing the rest of the statement.
 * MySQL executable comments (`/*!...*​/`) are left intact because their contents
 * actually run and any reserved word there really would break.
 *
 * @param string $text Inner content of a string already known to be SQL.
 *
 * @return string Same length as $text with ignored characters replaced by spaces.
 */
function cd_mask_sql_comments( string $text, bool $escapes_active ): string {
	$len   = strlen( $text );
	$out   = $text;
	$i     = 0;
	$quote = null; // Open SQL string-literal delimiter ("'" or '"'), or null.

	while ( $i < $len ) {
		$ch = $text[ $i ];

		// Inside a SQL string literal: mask data and only look for its
		// (un-doubled, un-escaped) close.
		if ( $quote !== null ) {
			if ( $ch === '\\' && $i + 1 < $len ) {
				$out[ $i ]     = ' ';
				$out[ $i + 1 ] = ' ';
				$i += 2; // Backslash-escaped char stays in the string.
				continue;
			}
			if ( $ch === $quote ) {
				if ( $i + 1 < $len && $text[ $i + 1 ] === $quote ) {
					$out[ $i ]     = ' ';
					$out[ $i + 1 ] = ' ';
					$i += 2; // Doubled quote ('' or "") is an escaped quote.
					continue;
				}
				$quote = null;
			}
			if ( $out[ $i ] !== "\n" ) {
				$out[ $i ] = ' ';
			}
			$i++;
			continue;
		}

		// Block comment /* ... */, but NOT an executable /*!...*/ comment.
		if ( $ch === '/' && $i + 1 < $len && $text[ $i + 1 ] === '*'
		     && ( $i + 2 >= $len || $text[ $i + 2 ] !== '!' ) ) {
			$end  = strpos( $text, '*/', $i + 2 );
			$stop = $end === false ? $len : $end + 2; // Unterminated comment runs to EOF.
			for ( $k = $i; $k < $stop; $k++ ) {
				if ( $out[ $k ] !== "\n" ) {
					$out[ $k ] = ' ';
				}
			}
			$i = $stop;
			continue;
		}

		// Line comment `-- ` (MySQL requires whitespace/EOL after the dashes) to EOL.
		// The required whitespace may be a literal byte (a real newline in a
		// multi-line/heredoc string) or a two-character escape sequence (\n, \r, \t)
		// only when PHP will interpret escapes for this string — see
		// cd_dashes_open_line_comment().
		if ( $ch === '-' && $i + 1 < $len && $text[ $i + 1 ] === '-'
		     && cd_dashes_open_line_comment( $text, $i + 2, $escapes_active ) ) {
			$i = cd_mask_to_end_of_line( $out, $text, $i, $escapes_active );
			continue;
		}

		// Line comment `#` (MySQL extension) to EOL.
		if ( $ch === '#' ) {
			$i = cd_mask_to_end_of_line( $out, $text, $i, $escapes_active );
			continue;
		}

		// Opening a SQL string literal.
		if ( $ch === "'" || $ch === '"' ) {
			$quote = $ch;
			$out[ $i ] = ' ';
		}

		$i++;
	}

	return $out;
}

/**
 * Does a `--` whose dashes end at offset $p begin a MySQL line comment? MySQL
 * requires the dashes to be followed by whitespace (or end-of-input); otherwise
 * `a--b` is arithmetic, not a comment.
 *
 * The "whitespace" can be a literal byte — a real newline in a heredoc or a
 * source-spanning string — or, only when $escapes_active is true, a two-character
 * escape sequence (\n, \r, \t) as it appears verbatim in a PHP double-quoted
 * literal. Single-quoted strings and nowdocs keep those bytes literally, so
 * `--\t` is not a MySQL comment opener there.
 */
function cd_dashes_open_line_comment( string $text, int $p, bool $escapes_active ): bool {
	$len = strlen( $text );

	if ( $p >= $len ) {
		return true; // `--` at end of string.
	}

	if ( ctype_space( $text[ $p ] ) ) {
		return true; // Literal whitespace/newline.
	}

	// Whitespace escape sequence (\n, \r, \t) written verbatim in the source.
	return $escapes_active
	       && $text[ $p ] === '\\'
	       && $p + 1 < $len
	       && in_array( $text[ $p + 1 ], [ 'n', 'r', 't' ], true );
}

/**
 * Blank out $out from $start up to (not including) the end of the current line in
 * $text, and return the index where it stopped (the newline position or the
 * string length). Helper for the two line-comment forms in cd_mask_sql_comments().
 *
 * The line ends at the first literal newline OR, only when $escapes_active is
 * true, the first escape-sequence newline (\n or \r written verbatim as a
 * backslash pair in a PHP double-quoted literal). The escape sequence itself is
 * left intact — it is not part of the comment — so a reserved word on the
 * following logical line is still scanned.
 */
function cd_mask_to_end_of_line( string &$out, string $text, int $start, bool $escapes_active ): int {
	$len  = strlen( $text );
	$stop = $len;

	for ( $k = $start; $k < $len; $k++ ) {
		$ch = $text[ $k ];

		if ( $ch === "\n" ) {
			$stop = $k; // Literal newline ends the line.
			break;
		}

		// Escape-sequence newline (\n or \r) written verbatim in the source: stop
		// before the backslash so the sequence is preserved for the next line.
		if ( $escapes_active
		     && $ch === '\\'
		     && $k + 1 < $len
		     && ( $text[ $k + 1 ] === 'n' || $text[ $k + 1 ] === 'r' ) ) {
			$stop = $k;
			break;
		}
	}

	for ( $k = $start; $k < $stop; $k++ ) {
		$out[ $k ] = ' ';
	}

	return $stop;
}

/**
 * Find every offset in $text where $word appears as a bare (unquoted, non-backticked)
 * SQL identifier.
 *
 * $text is the inner content of a string already known to be SQL, so the rules
 * concern SQL identifier boundaries. SQL comment spans are masked out first (see
 * cd_mask_sql_comments) so a reserved word inside `/* … *​/`, `-- …`, `# …`, or
 * a quoted SQL value is never flagged. An occurrence is rejected when the word is:
 *  - Preceded by a word char (part of a longer name: rank_math, page_rank),
 *    a backtick (already SQL-safe: `rank`), or a quote (a SQL string-literal
 *    value such as status='lead', not a column). $ and > are also rejected to be
 *    safe against interpolation artifacts.
 *  - Followed by a word char (longer name: rank_score), ( (function call:
 *    RANK(...)), a backtick, or a quote (string-literal value).
 *
 * @return int[] 0-based byte offsets of unquoted occurrences within $text.
 */
function cd_find_unquoted_occurrences( string $text, string $word, bool $escapes_active ): array {
	$text    = cd_mask_sql_comments( $text, $escapes_active );
	$pattern = '/(?<![\$\w>`\'"])' . preg_quote( $word, '/' ) . '(?![\w(`\'"])/i';
	$offsets = [];

	if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $match ) {
			$offsets[] = $match[1];
		}
	}

	return $offsets;
}

/**
 * Convert an absolute byte offset in $content to a 1-based [line, column] pair.
 *
 * @return array{0: int, 1: int}
 */
function cd_offset_to_line_col( string $content, int $offset ): array {
	$before  = substr( $content, 0, $offset );
	$line    = substr_count( $before, "\n" ) + 1;
	$last_nl = strrpos( $before, "\n" );
	$column  = $last_nl === false ? $offset + 1 : $offset - $last_nl;

	return [ $line, $column ];
}

/**
 * Scan a single PHP source string for reserved words used as unquoted SQL identifiers.
 *
 * This is the pure, testable entry point: no filesystem, no env, no globals.
 *
 * @param string                                                                              $content        PHP source.
 * @param array<int, array{word: string, reason: string, since: string, ticket: string, type: string}>|null $reserved_words Defaults to cd_db_reserved_words().
 *
 * @return array<int, array<string, mixed>> PHPCS-style message structures, sorted by line/column.
 */
function cd_scan_source( string $content, ?array $reserved_words = null ): array {
	$reserved_words = $reserved_words ?? cd_db_reserved_words();
	$sql_tokens     = cd_extract_sql_string_tokens( $content );

	if ( empty( $sql_tokens ) ) {
		return [];
	}

	$lines    = explode( "\n", $content );
	$messages = [];

	foreach ( $sql_tokens as $sql_token ) {
		foreach ( $reserved_words as $reserved ) {
			$word = $reserved['word'];

			if ( stripos( $sql_token['text'], $word ) === false ) {
				continue;
			}

			foreach ( cd_find_unquoted_occurrences( $sql_token['text'], $word, $sql_token['escapes_active'] ) as $rel_offset ) {
				[ $line, $column ] = cd_offset_to_line_col( $content, $sql_token['offset'] + $rel_offset );

				$messages[] = [
					'message'      => sprintf(
						'Unquoted SQL identifier "%s" is reserved in %s. %s Wrap it in backticks: `%s`.',
						$word,
						$reserved['since'],
						$reserved['reason'],
						$word
					),
					'source'       => 'QITStandard.DB.ReservedWordInSQL',
					'severity'     => 5,
					'fixable'      => false,
					'type'         => $reserved['type'] ?? 'ERROR',
					'line'         => $line,
					'column'       => $column,
					'codeFragment' => $lines[ $line - 1 ] ?? '',
				];
			}
		}
	}

	usort(
		$messages,
		static function ( array $a, array $b ): int {
			return [ $a['line'], $a['column'] ] <=> [ $b['line'], $b['column'] ];
		}
	);

	return $messages;
}

/**
 * Scan a plugin directory and build the PHPCS-compatible result structure.
 *
 * @param string                                                                              $plugin_dir
 * @param array<int, array{word: string, reason: string, since: string, ticket: string, type: string}>|null $reserved_words Defaults to cd_db_reserved_words().
 *
 * @return array<string, mixed>
 */
function cd_scan_plugin_dir( string $plugin_dir, ?array $reserved_words = null ): array {
	$result = [
		'totals' => [
			'errors'   => 0,
			'warnings' => 0,
			'fixable'  => 0,
		],
		'files'  => [],
	];

	$php_files = cd_get_php_files( $plugin_dir, cd_skip_dirs() );
	echo sprintf( "Scanning %d PHP files in %s\n", count( $php_files ), $plugin_dir );

	$reserved_words = $reserved_words ?? cd_db_reserved_words();

	foreach ( $php_files as $file_path ) {
		// An unreadable file would make file_get_contents() return false; passing
		// that into cd_scan_source( string ) aborts the whole scan and yields no
		// JSON. Skip such files (with a notice) instead. is_readable() is checked
		// first so we don't emit a warning, and the false return is still guarded
		// as defense-in-depth (e.g. a read that races with a permission change).
		if ( ! is_readable( $file_path ) ) {
			echo sprintf( "Skipping unreadable file: %s\n", $file_path );
			continue;
		}

		$content = file_get_contents( $file_path );

		if ( $content === false ) {
			echo sprintf( "Skipping unreadable file: %s\n", $file_path );
			continue;
		}

		$messages = cd_scan_source( $content, $reserved_words );

		if ( empty( $messages ) ) {
			continue;
		}

		$error_count   = count( array_filter( $messages, static fn( $m ) => $m['type'] === 'ERROR' ) );
		$warning_count = count( $messages ) - $error_count;

		$result['files'][ $file_path ] = [
			'errors'   => $error_count,
			'warnings' => $warning_count,
			'messages' => $messages,
		];

		$result['totals']['errors']   += $error_count;
		$result['totals']['warnings'] += $warning_count;
	}

	return $result;
}

// ──────────────────────────────────────────────────────────────────────────────
// Main — only runs when this file is executed directly, not when required by tests.
// ──────────────────────────────────────────────────────────────────────────────

$cd_is_direct_invocation = isset( $_SERVER['argv'][0] )
                           && realpath( $_SERVER['argv'][0] ) === realpath( __FILE__ );

if ( $cd_is_direct_invocation ) {
	$env = getenv();

	$required_envs = [
		'CD_PLUGIN_DIR',
		'GITHUB_WORKSPACE',
	];

	foreach ( $required_envs as $required_env ) {
		if ( ! isset( $env[ $required_env ] ) ) {
			echo "Missing required env: $required_env\n";
			die( 1 );
		}
	}

	if ( isset( $env['GITHUB_WORKSPACE'] ) ) {
		$plugin_dir = rtrim( $env['GITHUB_WORKSPACE'], '/' ) . '/' . $env['CD_PLUGIN_DIR'];
	} else {
		$plugin_dir = $env['CD_PLUGIN_DIR'];
	}

	$result_file = $env['GITHUB_WORKSPACE'] . '/ci/tests/php-compatibility/db_reserved_words_result.json';

	$result = cd_scan_plugin_dir( $plugin_dir );

	$written = file_put_contents( $result_file, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	if ( ! $written ) {
		echo "Failed to write result file: $result_file\n";
		die( 1 );
	}

	echo sprintf(
		"Scan complete. Found %d error(s) and %d warning(s) across %d file(s).\n",
		$result['totals']['errors'],
		$result['totals']['warnings'],
		count( $result['files'] )
	);
}
