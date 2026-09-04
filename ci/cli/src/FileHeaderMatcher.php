<?php

namespace CI_CLI;

final class FileHeaderMatcher {
	/**
	 * Mirrors the comment-prefix handling of WordPress core's get_file_data(), so any
	 * plugin header WordPress itself would load is recognized here.
	 */
	private const PLUGIN_HEADER_REGEX = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Plugin Name:(.*)$/mi';

	/**
	 * Mirrors the comment-prefix handling of WordPress core's get_file_data(), so any
	 * theme header WordPress itself would load is recognized here.
	 */
	private const THEME_HEADER_REGEX = '/^[ \t\/*#@]*Theme Name:(.*)$/mi';

	public static function contains_plugin_header( string $contents ): bool {
		return self::contains_file_header( $contents, self::PLUGIN_HEADER_REGEX );
	}

	public static function contains_theme_header( string $contents ): bool {
		return self::contains_file_header( $contents, self::THEME_HEADER_REGEX );
	}

	private static function contains_file_header( string $contents, string $pattern ): bool {
		// WordPress core normalizes CR-only and CRLF line endings before parsing file
		// headers. Match that behavior so all callers accept the same files.
		$contents = str_replace( "\r", "\n", $contents );

		if ( preg_match( $pattern, $contents, $match ) !== 1 || empty( $match[1] ) ) {
			return false;
		}

		// Mirrors WordPress core's _cleanup_header_comment() so a value containing
		// only a closing comment or PHP tag is not treated as an extension name.
		$header_value = preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] );

		return is_string( $header_value ) && trim( $header_value ) !== '';
	}
}
