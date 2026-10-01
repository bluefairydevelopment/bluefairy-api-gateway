<?php
/**
 * Tab navigation.
 *
 * @var array<string, array{label:string, page:object}> $tabs     Tabs in display order.
 * @var string                                          $current  The active tab id.
 * @var string                                          $base_url The page URL without a tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<nav class="nav-tab-wrapper bfag-tabs">
	<?php foreach ( $tabs as $bfag_id => $bfag_tab ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'tab', $bfag_id, $base_url ) ); ?>" class="nav-tab<?php echo $bfag_id === $current ? ' nav-tab-active' : ''; ?>">
			<?php echo esc_html( $bfag_tab['label'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>
