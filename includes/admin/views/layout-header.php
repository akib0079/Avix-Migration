<?php
/**
 * Shared header: opens .avix-wrap, renders the branded header bar and the
 * tab nav. $current and $screens are provided by Admin_Menu::render().
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="avix-wrap">

	<div class="avix-header">
		<div class="avix-header__brand">
			<img class="avix-header__mark" src="<?php echo esc_url( Avix_Migration_Admin_Menu::brand_mark_url() ); ?>" alt="" aria-hidden="true" width="36" height="36">
			<div>
				<p class="avix-header__title"><?php esc_html_e( 'Avix Migration', 'avix-migration' ); ?></p>
				<p class="avix-header__tagline"><?php esc_html_e( 'Backup, migrate, and transfer this site — full or piece by piece.', 'avix-migration' ); ?></p>
			</div>
		</div>
		<div class="avix-text-muted" style="font-size:12px;">
			<?php echo esc_html( sprintf( 'v%s', AVIX_MIGRATION_VERSION ) ); ?>
		</div>
	</div>

	<nav class="avix-tabs" aria-label="<?php esc_attr_e( 'Avix Migration sections', 'avix-migration' ); ?>">
		<?php foreach ( $screens as $slug => $screen ) : ?>
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
				class="avix-tab<?php echo $slug === $current ? ' is-active' : ''; ?>"
				<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
			><?php echo esc_html( $screen['title'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="avix-main">
