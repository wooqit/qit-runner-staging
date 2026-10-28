<?php

/*
 * Finds plugin code that stores another cart line's key in cart item data, the usual sign of
 * child cart items, and whether the plugin hooks the Store API filter that declares them.
 *
 * Runs on the runner's system PHP, so it must stay PHP 7.4-compatible. PHP 7.4 has no T_NAME_* tokens and
 * tokenizes an attribute's `#[` as a comment.
 */
class QIT_Parent_Item_Key_Scanner {
	const FILTER_NAME = 'woocommerce_store_api_cart_item_parent_item_key';

	const SKIPPED_DIRECTORIES = [ 'vendor', 'node_modules' ];

	const CART_LINE_KEY_VARIABLE = '/(cart_key|item_key|container_key)$/i';

	const DISMISS_MARKER = '/^\/\/.*(?<![\w-])qit-ignore:\s*parent-item-key(?![\w-])/';

	/** @var int */
	private $max_files;

	/** @var int */
	private $max_file_bytes;

	/** @var int */
	private $max_matches;

	/** @var int */
	private $max_directories;

	/** @var string */
	private $plugin_root;

	/** @var callable */
	private $print_line;

	/** @var int */
	private $scanned_files;

	/** @var int */
	private $scanned_directories;

	/** @var bool */
	private $scan_limit_reached;

	/** @var bool */
	private $match_limit_reported;

	/** @var array{uses_filter: bool, matches: array<array{file: string, line: int}>, truncated: bool} */
	private $result;

	// Several times WooCommerce core (3,199 PHP files, 819 directories, largest file 208 KB). The file size bounds
	// memory: WP-CLI runs with memory_limit -1, and tokenizing a 1 MB file peaks near 130 MB (282 MB at 2 MB).
	public function __construct( $max_files = 20000, $max_file_bytes = 1048576, $max_matches = 50, $max_directories = 10000 ) {
		$this->max_files       = $max_files;
		$this->max_file_bytes  = $max_file_bytes;
		$this->max_matches     = $max_matches;
		$this->max_directories = $max_directories;
	}

	/**
	 * @param string   $plugin_root Absolute path of the plugin's directory.
	 * @param callable $print_line  Receives one line of validator output.
	 *
	 * @return array|null The `parent_item_key` results, or null when the scan could not run.
	 */
	public function scan( $plugin_root, callable $print_line ) {
		if ( ! $this->tokenizer_available() ) {
			$print_line( 'Parent item key scan skipped: the PHP tokenizer extension is not available.' );

			return null;
		}

		$this->plugin_root          = rtrim( $plugin_root, '/' );
		$this->print_line           = $print_line;
		$this->scanned_files        = 0;
		$this->scanned_directories  = 0;
		$this->scan_limit_reached   = false;
		$this->match_limit_reported = false;
		$this->result               = [
			'uses_filter' => false,
			'matches'     => [],
			'truncated'   => false,
		];

		try {
			if ( ! is_dir( $this->plugin_root ) ) {
				throw new RuntimeException( "{$this->plugin_root} is not a directory." );
			}

			$this->scan_directory( $this->plugin_root );
		} catch ( Throwable $e ) {
			$print_line( 'Parent item key scan failed: ' . $e->getMessage() );

			return null;
		}

		return $this->result;
	}

	protected function tokenizer_available(): bool {
		return function_exists( 'token_get_all' );
	}

	/** @return string|false */
	protected function read_file( $path ) {
		return is_readable( $path ) ? file_get_contents( $path ) : false;
	}

	private function scan_directory( $directory ) {
		if ( $this->scanned_directories >= $this->max_directories ) {
			$this->stop( "Parent item key scan stopped after {$this->max_directories} directories." );

			return;
		}

		$this->scanned_directories++;

		$entries = scandir( $directory );

		if ( $entries === false ) {
			$this->skip( $directory, 'it could not be read' );

			return;
		}

		foreach ( $entries as $entry ) {
			if ( $this->scan_limit_reached ) {
				return;
			}

			$path = $directory . '/' . $entry;

			if ( $entry === '.' || $entry === '..' || is_link( $path ) ) {
				continue;
			}

			$is_directory = is_dir( $path );

			if ( $is_directory ? in_array( $entry, self::SKIPPED_DIRECTORIES, true ) : substr( $entry, -4 ) !== '.php' ) {
				continue;
			}

			// json_encode() fails on the whole validation result if one reported path is not UTF-8.
			if ( ! preg_match( '//u', $entry ) ) {
				$this->result['truncated'] = true;
				call_user_func( $this->print_line, 'Parent item key scan skipped a file or directory whose name is not UTF-8.' );
				continue;
			}

			if ( $is_directory ) {
				$this->scan_directory( $path );
			} else {
				$this->scan_file( $path );
			}
		}
	}

	private function scan_file( $path ) {
		if ( $this->scanned_files >= $this->max_files ) {
			$this->stop( "Parent item key scan stopped after {$this->max_files} PHP files." );

			return;
		}

		$this->scanned_files++;

		$size = filesize( $path );

		if ( $size === false ) {
			$this->skip( $path, 'it could not be read' );

			return;
		}

		if ( $size > $this->max_file_bytes ) {
			$this->skip( $path, "it is larger than {$this->max_file_bytes} bytes" );

			return;
		}

		$code = $this->read_file( $path );

		if ( $code === false ) {
			$this->skip( $path, 'it could not be read' );

			return;
		}

		$this->scan_code( $code, $this->relative_path( $path ) );
	}

	private function scan_code( $code, $relative_path ) {
		$tokens          = [];
		$dismissed_lines = [];

		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && $token[0] === T_COMMENT ) {
				if ( preg_match( self::DISMISS_MARKER, $token[1] ) ) {
					$dismissed_lines[ $token[2] ] = true;
				}
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			if ( is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $this->unquote( $token[1] ) === self::FILTER_NAME ) {
				$this->result['uses_filter'] = true;
			}

			$tokens[] = $token;
		}

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || $token[0] !== T_VARIABLE ) {
				continue;
			}

			$line = $token[2];

			if ( isset( $dismissed_lines[ $line ] ) || ! $this->is_cart_line_key_stored( $tokens, $index ) ) {
				continue;
			}

			$match = [
				'file' => $relative_path,
				'line' => $line,
			];

			if ( end( $this->result['matches'] ) === $match ) {
				continue;
			}

			if ( count( $this->result['matches'] ) >= $this->max_matches ) {
				$this->report_match_limit();
				continue;
			}

			$this->result['matches'][] = $match;
		}
	}

	/**
	 * Whether the variable at $index is a lone cart line key stored under a literal or
	 * constant key: `K => $v` in an array, or `[K] = $v`.
	 */
	private function is_cart_line_key_stored( array $tokens, $index ) {
		$variable = strtolower( substr( $tokens[ $index ][1], 1 ) );

		if ( ! preg_match( self::CART_LINE_KEY_VARIABLE, $variable ) ) {
			return false;
		}

		$next     = $tokens[ $index + 1 ] ?? null;
		$previous = $tokens[ $index - 1 ] ?? null;

		if ( $this->is_token( $previous, T_DOUBLE_ARROW ) && in_array( $next, [ ',', ']', ')' ], true ) ) {
			$key_end    = $index - 2;
			$key_opener = [ '[', '(', ',' ];
		} elseif (
			$previous === '='
			&& ( $tokens[ $index - 2 ] ?? null ) === ']'
			&& ( in_array( $next, [ ';', ',', ')', ']' ], true ) || $this->is_token( $next, T_CLOSE_TAG ) )
		) {
			$key_end    = $index - 3;
			$key_opener = [ '[' ];
		} else {
			return false;
		}

		$key = $this->read_key( $tokens, $key_end, $key_opener );

		if ( ! $this->is_token( $tokens[ $key_end ], T_CONSTANT_ENCAPSED_STRING ) ) {
			return $key !== null;
		}

		// `'key'` is core's own cart line key, and a key passed on under its own name is not a parent.
		return $key !== null && $key !== 'key' && $key !== $variable;
	}

	/**
	 * The lowercased key ending at $key_end when it is a string literal, or a constant's name
	 * (`NAME`, `\Ns\NAME`, `Class::NAME`), directly after one of $openers, null otherwise.
	 */
	private function read_key( array $tokens, $key_end, array $openers ) {
		$key_token = $tokens[ $key_end ] ?? null;

		if ( $this->is_token( $key_token, T_CONSTANT_ENCAPSED_STRING ) ) {
			$key_start = $key_end;
			$key       = $this->unquote( $key_token[1] );
		} elseif ( $this->is_name( $key_token ) ) {
			$key_start = $key_end;
			while ( $key_start > 0 && $this->is_name_part( $tokens[ $key_start - 1 ] ) ) {
				$key_start--;
			}
			$segments = explode( '\\', $key_token[1] );
			$key      = end( $segments );
		} else {
			return null;
		}

		$opener = $tokens[ $key_start - 1 ] ?? null;

		return in_array( $opener, $openers, true ) ? strtolower( $key ) : null;
	}

	private function is_name( $token ) {
		return is_array( $token ) && in_array( token_name( $token[0] ), [ 'T_STRING', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ], true );
	}

	/** PHP 7.4 splits `\Ns\NAME` into separator and string tokens; PHP 8 gives one name token. */
	private function is_name_part( $token ) {
		return $this->is_name( $token )
			|| $this->is_token( $token, T_NS_SEPARATOR )
			|| $this->is_token( $token, T_DOUBLE_COLON )
			|| $this->is_token( $token, T_STATIC )
			|| $this->is_token( $token, T_NAMESPACE );
	}

	private function is_token( $token, $id ) {
		return is_array( $token ) && $token[0] === $id;
	}

	private function unquote( $literal ) {
		return substr( $literal, 1, -1 );
	}

	private function report_match_limit() {
		if ( $this->match_limit_reported ) {
			return;
		}

		$this->match_limit_reported = true;
		$this->result['truncated']  = true;
		call_user_func( $this->print_line, "Parent item key scan reports only the first {$this->max_matches} locations." );
	}

	private function stop( $line ) {
		$this->scan_limit_reached  = true;
		$this->result['truncated'] = true;
		call_user_func( $this->print_line, $line );
	}

	private function skip( $path, $reason ) {
		$this->result['truncated'] = true;
		call_user_func( $this->print_line, 'Parent item key scan skipped ' . $this->relative_path( $path ) . ": {$reason}." );
	}

	private function relative_path( $path ) {
		return ltrim( substr( $path, strlen( $this->plugin_root ) ), '/' );
	}
}
