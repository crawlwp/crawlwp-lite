<?php
/**
 * Promotional block rendered above the "enable SEO features" checkbox.
 *
 * @var array  $features       Feature cards: icon (raw inline SVG), title, desc.
 * @var string $illustration_url URL of the admin-screen mock-up illustration.
 *
 * @package mihdan-index-now
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="cwp-fg-wrap">

	<div class="cwp-fg-hero">
		<span class="cwp-fg-hero__eyebrow"><?php esc_html_e('On-page SEO', 'mihdan-index-now'); ?></span>
		<h2 class="cwp-fg-hero__title">
			<?php esc_html_e('Complete on-page SEO, built into CrawlWP', 'mihdan-index-now'); ?>
		</h2>
		<p class="cwp-fg-hero__intro">
			<?php esc_html_e(
				'Everything you need to rank — title & meta templates, Open Graph, Schema/JSON-LD, breadcrumbs, sitemaps, redirects and robots.txt — without installing a separate SEO plugin.',
				'mihdan-index-now'
			); ?>
		</p>
		<div class="cwp-fg-hero__badges">
			<span class="cwp-fg-badge"><?php esc_html_e('Zero extra plugins needed', 'mihdan-index-now'); ?></span>
			<span class="cwp-fg-badge"><?php esc_html_e('Multilingual ready', 'mihdan-index-now'); ?></span>
		</div>
	</div>

	<div class="cwp-fg-hero__illustration">
		<img src="<?php echo esc_url($illustration_url); ?>" alt="" aria-hidden="true"/>
	</div>

	<div class="cwp-fg-section-head">
		<h3 class="cwp-fg-section-head__title"><?php esc_html_e('What you get', 'mihdan-index-now'); ?></h3>
	</div>

	<div class="cwp-fg-grid">
		<?php foreach ($features as $feature): ?>
			<div class="cwp-fg-card">
				<span class="cwp-fg-card__icon" aria-hidden="true"><?php echo $feature['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div class="cwp-fg-card__body">
					<h3 class="cwp-fg-card__title"><?php echo esc_html($feature['title']); ?></h3>
					<p class="cwp-fg-card__desc"><?php echo esc_html($feature['desc']); ?></p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="cwp-fg-note">
		<svg class="cwp-fg-note__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
		<div>
			<strong><?php esc_html_e('Migrating from another SEO plugin?', 'mihdan-index-now'); ?></strong>
			<?php esc_html_e(
				'Enable the features below first — this unlocks the Title & Meta, Social Networks, Site Information and all other settings tabs. Configure everything to your liking, use the migration tool and then safely deactivate your old SEO plugin.',
				'mihdan-index-now'
			); ?>
		</div>
	</div>

	<div class="cwp-fg-cta">
		<span class="cwp-fg-cta__icon" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
		</span>
		<div class="cwp-fg-cta__body">
			<h3 class="cwp-fg-cta__title"><?php esc_html_e('Turn on the SEO features', 'mihdan-index-now'); ?></h3>
			<p class="cwp-fg-cta__text">
				<?php esc_html_e(
					'Tick the box below and save to activate everything listed above. Nothing is rewritten in your content.',
					'mihdan-index-now'
				); ?>
			</p>
			<div style="margin-top: 14px;">
				<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crawlwp_enable_and_launch_wizard'), 'crawlwp_enable_wizard')); ?>" class="button button-primary button-hero">
					<?php esc_html_e('Enable & Launch Setup Wizard →', 'mihdan-index-now'); ?>
				</a>
			</div>
		</div>
	</div>

</div>
