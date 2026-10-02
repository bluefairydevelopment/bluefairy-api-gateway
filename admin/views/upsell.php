<?php
/**
 * The Pro panel.
 *
 * @var string       $product_url Where to buy.
 * @var list<string> $features    What Pro adds.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="bfag-upsell">
	<h2><?php esc_html_e( 'API Gateway Pro', 'bluefairy-api-gateway' ); ?></h2>
	<p><?php esc_html_e( 'This plugin caches, rate-limits and signs webhooks. Pro adds the parts integrations ask for:', 'bluefairy-api-gateway' ); ?></p>
	<ul>
		<?php foreach ( $features as $bfag_feature ) : ?>
			<li><?php echo esc_html( $bfag_feature ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p>
		<a href="<?php echo esc_url( $product_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php esc_html_e( 'See API Gateway Pro', 'bluefairy-api-gateway' ); ?></a>
	</p>
	<p class="description"><?php esc_html_e( 'Installs alongside this plugin. Your rules and webhooks carry straight over.', 'bluefairy-api-gateway' ); ?></p>
</div>
