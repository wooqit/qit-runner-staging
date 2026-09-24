<?php

namespace CI_CLI;

final class WorkflowFailure {
	/** @return array{stage:string,code:string,message:string}|null */
	public static function read( string $failure_path ): ?array {
		if ( $failure_path === '' || ! is_readable( $failure_path ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $failure_path ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$stage   = (string) ( $decoded['stage'] ?? '' );
		$code    = (string) ( $decoded['code'] ?? '' );
		$message = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) ( $decoded['message'] ?? '' ) ) ?? '' );
		if ( ! in_array( $stage, [ 'plugin_download', 'setup', 'analysis' ], true ) || ! preg_match( '/^[a-z0-9_]+$/', $code ) || $message === '' ) {
			return null;
		}

		return [
			'stage'   => $stage,
			'code'    => $code,
			'message' => substr( $message, 0, 500 ),
		];
	}
}
