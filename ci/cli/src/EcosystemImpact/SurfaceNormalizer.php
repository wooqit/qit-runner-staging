<?php

namespace CI_CLI\EcosystemImpact;

final class SurfaceNormalizer {
	public static function surface_key( string $surface, string $kind = 'class' ): string {
		$surface = trim( $surface );
		if ( in_array( $kind, [ 'hook', 'selector' ], true ) ) {
			return $surface;
		}

		return strtolower( ltrim( $surface, '\\' ) );
	}

	public static function member_key( string $member, string $kind = 'class' ): string {
		$member = trim( $member );
		if ( $member === '' || in_array( $kind, [ 'hook', 'selector' ], true ) ) {
			return $member;
		}

		return strtolower( $member );
	}
}
