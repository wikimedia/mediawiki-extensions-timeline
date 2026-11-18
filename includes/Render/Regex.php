<?php

namespace MediaWiki\Extension\Timeline\Render;

use RuntimeException;

/**
 * The preg_* functions, with an exception when one fails.
 *
 * A preg_* function that fails (the input reaches pcre.backtrack_limit or the stack limit of
 * the JIT compiler) gives the same result as "no match". The compiler would then accept a line
 * that it must refuse, or the reverse, and say nothing. The input can cause such a failure,
 * so each regular expression of the renderer goes through this class.
 */
final class Regex {
	/**
	 * @param string $pattern
	 * @param string $subject
	 * @param string[] &$matches @phan-output-reference
	 * @param int $flags
	 * @param int $offset
	 * @return int 1 for a match, 0 for none
	 */
	public static function match(
		string $pattern, string $subject, &$matches = [], int $flags = 0, int $offset = 0
	): int {
		$result = preg_match( $pattern, $subject, $matches, $flags, $offset );
		if ( $result === false ) {
			self::fail();
		}
		return $result;
	}

	/**
	 * @param string $pattern
	 * @param string $subject
	 * @param array &$matches @phan-output-reference
	 * @param int $flags
	 * @param int $offset
	 * @return int The number of matches
	 */
	public static function matchAll(
		string $pattern, string $subject, &$matches = [], int $flags = 0, int $offset = 0
	): int {
		$result = preg_match_all( $pattern, $subject, $matches, $flags, $offset );
		if ( $result === false ) {
			self::fail();
		}
		return $result;
	}

	public static function replace(
		string $pattern, string $replacement, string $subject, int $limit = -1
	): string {
		$result = preg_replace( $pattern, $replacement, $subject, $limit );
		if ( $result === null ) {
			self::fail();
		}
		return $result;
	}

	public static function replaceCallback(
		string $pattern, callable $callback, string $subject, int $limit = -1
	): string {
		$result = preg_replace_callback( $pattern, $callback, $subject, $limit );
		if ( $result === null ) {
			self::fail();
		}
		return $result;
	}

	/**
	 * @param string $pattern
	 * @param string $subject
	 * @param int $limit
	 * @param int $flags
	 * @return string[]
	 */
	public static function split( string $pattern, string $subject, int $limit = -1, int $flags = 0 ): array {
		$result = preg_split( $pattern, $subject, $limit, $flags );
		if ( $result === false ) {
			self::fail();
		}
		return $result;
	}

	private static function fail(): never {
		throw new RuntimeException( 'Regular expression failure: ' . preg_last_error_msg() );
	}
}
