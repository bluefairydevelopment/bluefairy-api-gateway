<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Contracts between admin.css and markup the base does not render itself.
 */
final class AdminCssTest extends TestCase {

	/**
	 * Pro's preset fields are a <tbody data-bfag-preset-config>; revealed as
	 * `table-row` its rows collapse into one anonymous cell and the webhook
	 * form's columns stop lining up (found by the Step 3b Pro screenshots).
	 */
	public function test_a_revealed_tbody_preset_block_is_a_row_group(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/admin.css' );
		$this->assertMatchesRegularExpression(
			'/tbody\[data-bfag-preset-config\]\.is-active\s*\{\s*display:\s*table-row-group;\s*\}/',
			$css
		);
	}
}
