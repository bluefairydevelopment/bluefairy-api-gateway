<?php
/**
 * The one pager every paged admin list uses.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An admin list whose rows have no cap — the delivery log here, the Pro
 * add-on's API keys and blocked addresses — shows one page of rows and pages
 * through the rest, so every row can be seen and acted on (WPMAP-141,
 * WPMAP-144: those lists showed the newest 50 or 200 rows and nothing else).
 *
 * The page number travels in a query argument of the list's own (so two lists
 * on one screen keep their own page), is read with absint() and clamped to
 * the pages there are. A form that acts on a row carries the page back
 * (hidden_field()) so the action returns to it.
 *
 * The markup is WordPress's own list-table pager: `.tablenav-pages`,
 * `.displaying-num`, First / Previous / *Page X of Y* / Next / Last.
 */
final class Pager {

	/**
	 * The page asked for in the query string, 1 or more.
	 *
	 * @param string $arg Query argument naming the page.
	 */
	public static function requested( string $arg ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page number for a listing.
		$raw = isset( $_GET[ $arg ] ) && is_scalar( $_GET[ $arg ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $arg ] ) ) : '';
		return max( 1, absint( $raw ) );
	}

	/**
	 * The page to show of $total rows at $per_page a page.
	 *
	 * @param string $arg      Query argument naming the page.
	 * @param int    $total    Rows in the whole list.
	 * @param int    $per_page Rows a page (1 or more).
	 * @return array{paged:int, pages:int, offset:int, per_page:int}
	 */
	public static function window( string $arg, int $total, int $per_page ): array {
		$per_page = max( 1, $per_page );
		$pages    = max( 1, (int) ceil( max( 0, $total ) / $per_page ) );
		$paged    = min( $pages, self::requested( $arg ) );
		return [
			'paged'    => $paged,
			'pages'    => $pages,
			'offset'   => ( $paged - 1 ) * $per_page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Print the pager when there is more than one page; nothing otherwise.
	 *
	 * @param string $arg        Query argument naming the page.
	 * @param string $base_url   The list's URL without the page argument.
	 * @param int    $paged      The page shown.
	 * @param int    $pages      Pages in all.
	 * @param string $count_text The row count to show, e.g. "1,005 active blocks" (escaped here).
	 * @param string $css_class  Extra class on the wrapper.
	 */
	public static function render( string $arg, string $base_url, int $paged, int $pages, string $count_text, string $css_class = '' ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$link = static fn( int $n ): string => add_query_arg( [ $arg => $n ], $base_url );
		echo '<div class="tablenav top ' . esc_attr( trim( 'bfag-pager ' . $css_class ) ) . '"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html( $count_text ) . '</span><span class="pagination-links">';
		if ( $paged > 1 ) {
			echo '<a class="first-page button" href="' . esc_url( $link( 1 ) ) . '"><span class="screen-reader-text">' . esc_html__( 'First page', 'bluefairy-api-gateway' ) . '</span><span aria-hidden="true">&laquo;</span></a> ';
			echo '<a class="prev-page button" href="' . esc_url( $link( $paged - 1 ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Previous page', 'bluefairy-api-gateway' ) . '</span><span aria-hidden="true">&lsaquo;</span></a> ';
		}
		/* translators: 1: current page, 2: number of pages */
		echo '<span class="paging-input" data-bfag-page="' . esc_attr( (string) $paged ) . '">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'bluefairy-api-gateway' ), $paged, $pages ) ) . '</span>';
		if ( $paged < $pages ) {
			echo ' <a class="next-page button" href="' . esc_url( $link( $paged + 1 ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Next page', 'bluefairy-api-gateway' ) . '</span><span aria-hidden="true">&rsaquo;</span></a>';
			echo ' <a class="last-page button" href="' . esc_url( $link( $pages ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Last page', 'bluefairy-api-gateway' ) . '</span><span aria-hidden="true">&raquo;</span></a>';
		}
		echo '</span></div></div>';
	}

	/**
	 * A hidden field carrying the page back with a row action's form.
	 *
	 * @param string $arg   Query argument naming the page.
	 * @param int    $paged The page shown.
	 */
	public static function hidden_field( string $arg, int $paged ): void {
		echo '<input type="hidden" name="' . esc_attr( $arg ) . '" value="' . esc_attr( (string) max( 1, $paged ) ) . '" />';
	}
}
