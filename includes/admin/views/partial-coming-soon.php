<?php
/**
 * Shared "not built yet" placeholder for screens whose milestone hasn't
 * landed. Included directly by the individual stub view files (not one of
 * the top-level $screen['view'] targets itself).
 *
 * Expects (via extract()): $milestone (string label), $features (string[]).
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="avix-card">
	<div class="avix-empty">
		<span class="dashicons dashicons-hammer" style="font-size:32px;width:32px;height:32px;"></span>
		<p class="avix-empty__title">
			<?php
			printf(
				/* translators: %s: milestone label, e.g. "Milestone 2" */
				esc_html__( 'Coming in %s', 'avix-migration' ),
				esc_html( $milestone )
			);
			?>
		</p>
		<?php if ( ! empty( $features ) ) : ?>
			<ul style="text-align:left; list-style:disc; padding-left: 20px;">
				<?php foreach ( $features as $feature ) : ?>
					<li><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
