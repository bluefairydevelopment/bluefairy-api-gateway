<?php
/**
 * Admin-module test helpers: build a verified admin request, catch the exit.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting

/**
 * Populate the superglobals as WordPress would for a form POST (or a nonce
 * link) carrying `bfag_action=$action` and the matching `bfag_<action>` nonce.
 *
 * @param string               $action The action, without the `bfag_` prefix.
 * @param array<string, mixed> $fields `$_POST['bfag']`.
 * @param array<string, mixed> $query  Top-level query arguments (`id`, `enabled`, …).
 * @param bool                 $nonce  False to send a wrong nonce.
 */
function bfag_test_admin_request( string $action, array $fields = [], array $query = [], bool $nonce = true ): void {
	$_GET     = $query;
	$_POST    = array_merge( [ 'bfag_action' => $action ], [] === $fields ? [] : [ 'bfag' => $fields ] );
	$_REQUEST = array_merge( $_GET, $_POST, [ '_wpnonce' => $nonce ? wp_create_nonce( 'bfag_' . $action ) : 'nonce-wrong' ] );
}

/**
 * Populate the superglobals for an admin-ajax call.
 *
 * @param string               $action The AJAX action, without the `bfag_ajax_` prefix.
 * @param array<string, mixed> $fields POST fields.
 * @param bool                 $nonce  False to send a wrong nonce.
 */
function bfag_test_ajax_request( string $action, array $fields = [], bool $nonce = true ): void {
	$_POST    = array_merge( $fields, [ 'nonce' => $nonce ? wp_create_nonce( 'bfag_ajax_' . $action ) : 'nonce-wrong' ] );
	$_GET     = [];
	$_REQUEST = $_POST;
}

/**
 * Run $callback and return the BfagTestExit it threw (a redirect, wp_die or
 * wp_send_json_*), or null when it returned normally. Output is captured and
 * appended to $GLOBALS['bfag_test_output'].
 *
 * @param callable $callback What to run.
 */
function bfag_test_catch_exit( callable $callback ): ?BfagTestExit {
	ob_start();
	try {
		$callback();
		return null;
	} catch ( BfagTestExit $e ) {
		return $e;
	} finally {
		$out = (string) ob_get_clean();
		if ( '' !== $out ) {
			$GLOBALS['bfag_test_output'][] = $out;
		}
	}
}

/**
 * Run $callback with output buffering and return everything it printed.
 *
 * @param callable $callback What to run.
 */
function bfag_test_render( callable $callback ): string {
	ob_start();
	try {
		$callback();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}
