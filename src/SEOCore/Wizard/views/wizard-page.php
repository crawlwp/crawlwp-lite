<?php
/**
 * Getting Started Wizard View.
 *
 * @var array  $inventory
 * @var array  $active_sources
 * @var array  $avail_sources
 * @var array  $site_info
 * @var array  $home_tm
 * @var array  $separators
 * @var string $current_sep
 * @var array  $post_types
 * @var string $current_step
 * @var bool   $index_now_enabled
 * @var string $api_key
 * @var string $current_engine
 * @var array  $search_engines
 * @var bool   $ping_on_post
 * @var bool   $ping_on_post_updated
 * @var array  $sub_post_types
 * @var string $key_location
 *
 * @package mihdan-index-now
 */

if (!defined('ABSPATH')) {
	exit;
}

use Mihdan\IndexNow\SEOCore\Importer\Runner;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;

$site_name        = $site_info['site_name'] ?? get_bloginfo('name');
$site_desc        = $site_info['site_description'] ?? get_bloginfo('description');
$site_type        = $site_info['site_type'] ?? 'organization';
$site_logo        = $site_info['logo'] ?? '';
$home_title       = $home_tm['title'] ?? '{{ site_title }} {{ sep }} {{ tagline }}';
$home_description = $home_tm['description'] ?? '{{ tagline }}';

// Check which active source to highlight by default (first active one)
$primary_active = !empty($active_sources) ? reset($active_sources) : (!empty($avail_sources) ? reset($avail_sources) : null);
?>

<div class="wrap cwp-wizard-wrap">
	<div class="cwp-wizard-container">

		<!-- Header -->
		<header class="cwp-wizard-header">
			<div class="cwp-wizard-brand">
				<span class="cwp-wizard-brand__badge"><?php esc_html_e('CrawlWP', 'mihdan-index-now'); ?></span>
				<h1 class="cwp-wizard-brand__title"><?php esc_html_e('Getting Started with On-Page SEO', 'mihdan-index-now'); ?></h1>
			</div>
			<a href="<?php echo esc_url(admin_url('admin.php?page=crawlwp')); ?>" class="cwp-wizard-exit" title="<?php esc_attr_e('Exit to CrawlWP Settings', 'mihdan-index-now'); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e('Exit Wizard', 'mihdan-index-now'); ?></span>
			</a>
		</header>

		<!-- Progress Nav -->
		<nav class="cwp-wizard-nav" aria-label="<?php esc_attr_e('Setup Steps', 'mihdan-index-now'); ?>">
			<ol class="cwp-wizard-steps">
				<li class="cwp-wizard-step <?php echo $current_step === 'welcome' ? 'is-active' : ''; ?>" data-step="welcome">
					<span class="cwp-wizard-step__number">1</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Welcome', 'mihdan-index-now'); ?></span>
				</li>
				<li class="cwp-wizard-step <?php echo $current_step === 'import' ? 'is-active' : ''; ?>" data-step="import">
					<span class="cwp-wizard-step__number">2</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Import SEO Data', 'mihdan-index-now'); ?></span>
				</li>
				<li class="cwp-wizard-step <?php echo $current_step === 'site_info' ? 'is-active' : ''; ?>" data-step="site_info">
					<span class="cwp-wizard-step__number">3</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Site Identity', 'mihdan-index-now'); ?></span>
				</li>
				<li class="cwp-wizard-step <?php echo $current_step === 'search_appearance' ? 'is-active' : ''; ?>" data-step="search_appearance">
					<span class="cwp-wizard-step__number">4</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Search Defaults', 'mihdan-index-now'); ?></span>
				</li>
				<li class="cwp-wizard-step <?php echo $current_step === 'index_now' ? 'is-active' : ''; ?>" data-step="index_now">
					<span class="cwp-wizard-step__number">5</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Instant Indexing', 'mihdan-index-now'); ?></span>
				</li>
				<li class="cwp-wizard-step <?php echo $current_step === 'ready' ? 'is-active' : ''; ?>" data-step="ready">
					<span class="cwp-wizard-step__number">6</span>
					<span class="cwp-wizard-step__label"><?php esc_html_e('Complete', 'mihdan-index-now'); ?></span>
				</li>
			</ol>
		</nav>

		<!-- Step Content Cards -->
		<main class="cwp-wizard-card">

			<!-- STEP 1: WELCOME -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'welcome' ? 'is-active' : ''; ?>" id="cwp-step-welcome">
				<div class="cwp-wizard-panel__head">
					<span class="cwp-wizard-eyebrow"><?php esc_html_e('Quick Setup', 'mihdan-index-now'); ?></span>
					<h2><?php esc_html_e('Optimize your site for search engines in minutes', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'CrawlWP provides complete on-page SEO out of the box — metadata, schema, XML sitemaps, Open Graph, and redirects. This wizard guides you through essential setup and imports your data from existing SEO plugins.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<div class="cwp-wizard-features-list">
					<div class="cwp-wizard-feat-item">
						<span class="dashicons dashicons-download cwp-wizard-feat-icon" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('One-Click SEO Migration', 'mihdan-index-now'); ?></strong>
							<p><?php esc_html_e('Seamlessly transfer existing titles, descriptions, and settings from other active SEO plugins without losing rankings.', 'mihdan-index-now'); ?></p>
						</div>
					</div>
					<div class="cwp-wizard-feat-item">
						<span class="dashicons dashicons-networking cwp-wizard-feat-icon" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('Structured Schema & Knowledge Graph', 'mihdan-index-now'); ?></strong>
							<p><?php esc_html_e('Represent your brand as an Organization or Person with official name and logo for rich search snippets.', 'mihdan-index-now'); ?></p>
						</div>
					</div>
					<div class="cwp-wizard-feat-item">
						<span class="dashicons dashicons-search cwp-wizard-feat-icon" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('Search Engine Indexing Control', 'mihdan-index-now'); ?></strong>
							<p><?php esc_html_e('Configure title separators, homepage metadata, and decide exactly which content types search engines should index.', 'mihdan-index-now'); ?></p>
						</div>
					</div>
					<div class="cwp-wizard-feat-item">
						<span class="dashicons dashicons-update cwp-wizard-feat-icon" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('Instant Index Submission via IndexNow', 'mihdan-index-now'); ?></strong>
							<p><?php esc_html_e('Automatically notify Bing, Yandex, and other search engines the second you publish or update content.', 'mihdan-index-now'); ?></p>
						</div>
					</div>
				</div>

				<div class="cwp-wizard-panel__footer">
					<button type="button" class="button button-primary button-hero cwp-btn-next" data-goto="import">
						<?php esc_html_e('Get Started →', 'mihdan-index-now'); ?>
					</button>
					<a href="<?php echo esc_url(admin_url('admin.php?page=crawlwp')); ?>" class="cwp-wizard-skip-link">
						<?php esc_html_e('Skip to CrawlWP Settings', 'mihdan-index-now'); ?>
					</a>
				</div>
			</section>

			<!-- STEP 2: IMPORT -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'import' ? 'is-active' : ''; ?>" id="cwp-step-import">
				<div class="cwp-wizard-panel__head">
					<span class="cwp-wizard-eyebrow"><?php esc_html_e('Step 1 of 4', 'mihdan-index-now'); ?></span>
					<h2><?php esc_html_e('Import from Existing SEO Plugin', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'If you were using another WordPress SEO plugin, you can transfer your metadata right now so you do not have to rewrite anything.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<?php if (!empty($active_sources)): ?>
					<!-- Active Source Detected Card -->
					<div class="cwp-import-alert is-active-detected">
						<div class="cwp-import-alert__badge">
							<span class="cwp-indicator-dot"></span>
							<?php esc_html_e('Active SEO Plugin Detected', 'mihdan-index-now'); ?>
						</div>
						<h3 class="cwp-import-alert__title">
							<?php
							/* translators: %s: plugin name */
							printf(esc_html__('%s is currently running on this website', 'mihdan-index-now'), esc_html($primary_active['label']));
							?>
						</h3>
						<p class="cwp-import-alert__desc">
							<?php esc_html_e(
								'We found SEO settings and metadata in your database. Importing now transfers all your titles, meta descriptions, robots directives, and redirects directly into CrawlWP.',
								'mihdan-index-now'
							); ?>
						</p>

						<div class="cwp-import-stats">
							<span class="cwp-import-stat">
								<strong><?php echo esc_html((string) $primary_active['posts']); ?></strong>
								<?php esc_html_e('Posts/Pages', 'mihdan-index-now'); ?>
							</span>
							<span class="cwp-import-stat">
								<strong><?php echo esc_html((string) $primary_active['terms']); ?></strong>
								<?php esc_html_e('Categories/Terms', 'mihdan-index-now'); ?>
							</span>
							<span class="cwp-import-stat">
								<strong><?php echo esc_html((string) ($primary_active['users'] ?? 0)); ?></strong>
								<?php esc_html_e('Author Profiles', 'mihdan-index-now'); ?>
							</span>
							<span class="cwp-import-stat">
								<strong><?php echo esc_html((string) $primary_active['redirects']); ?></strong>
								<?php esc_html_e('Redirect Rules', 'mihdan-index-now'); ?>
							</span>
						</div>

						<input type="hidden" id="cwpWizardSource" value="<?php echo esc_attr($primary_active['id']); ?>" />
						<input type="hidden" id="cwpWizardActiveSourceFile" value="<?php echo esc_attr(Runner::get_active_plugin_file($primary_active['id']) ?? ''); ?>" />

						<div class="cwp-import-options">
							<label class="cwp-checkbox-label">
								<input type="checkbox" id="cwpWizardOverwrite" value="1" checked="checked" />
								<span><?php esc_html_e('Overwrite any existing CrawlWP values with imported data', 'mihdan-index-now'); ?></span>
							</label>
						</div>

						<!-- Progress Bar Area -->
						<div class="cwp-import-progress-wrap" id="cwpImportProgressWrap" style="display: none;">
							<div class="cwp-import-progress-bar">
								<div class="cwp-import-progress-fill" id="cwpImportProgressFill"></div>
							</div>
							<div class="cwp-import-status-text" id="cwpImportStatusText">
								<?php esc_html_e('Preparing import…', 'mihdan-index-now'); ?>
							</div>
						</div>

						<!-- Action Row -->
						<div class="cwp-import-action-row">
							<button type="button" class="button button-primary button-large" id="cwpStartImportBtn" data-source="<?php echo esc_attr($primary_active['id']); ?>">
								<span class="dashicons dashicons-update cwp-spin-icon" style="display: none;" aria-hidden="true"></span>
								<span class="cwp-btn-text">
									<?php
									/* translators: %s: source label */
									printf(esc_html__('Import from %s', 'mihdan-index-now'), esc_html($primary_active['label']));
									?>
								</span>
							</button>

							<button type="button" class="button button-secondary button-large cwp-btn-next" id="cwpImportContinueBtn" data-goto="site_info" style="display: none;">
								<?php esc_html_e('Continue to Site Identity →', 'mihdan-index-now'); ?>
							</button>
						</div>

						<!-- Import Logs -->
						<div class="cwp-import-log" id="cwpImportLog" style="display: none;"></div>
					</div>

					<!-- Option to choose other detected source if multiple exist -->
					<?php if (count($avail_sources) > 1): ?>
						<div class="cwp-other-sources-toggle">
							<button type="button" class="button-link" id="cwpToggleOtherSources">
								<?php esc_html_e('Import from a different SEO plugin instead ▼', 'mihdan-index-now'); ?>
							</button>
							<div class="cwp-other-sources-list" id="cwpOtherSourcesList" style="display: none;">
								<?php foreach ($avail_sources as $src): ?>
									<?php if ($src['id'] === $primary_active['id']) continue; ?>
									<div class="cwp-other-source-item">
										<div class="cwp-other-source-info">
											<strong><?php echo esc_html($src['label']); ?></strong>
											<span>
												<?php echo esc_html($src['posts'] . ' ' . __('posts', 'mihdan-index-now') . ', ' . $src['terms'] . ' ' . __('terms', 'mihdan-index-now') . ', ' . $src['redirects'] . ' ' . __('redirects', 'mihdan-index-now')); ?>
											</span>
										</div>
										<button type="button" class="button button-secondary cwp-select-source-btn" data-source="<?php echo esc_attr($src['id']); ?>" data-label="<?php echo esc_attr($src['label']); ?>" data-posts="<?php echo esc_attr((string) $src['posts']); ?>" data-terms="<?php echo esc_attr((string) $src['terms']); ?>" data-users="<?php echo esc_attr((string) ($src['users'] ?? 0)); ?>" data-redirects="<?php echo esc_attr((string) $src['redirects']); ?>">
											<?php esc_html_e('Select', 'mihdan-index-now'); ?>
										</button>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

				<?php elseif (!empty($avail_sources)): ?>
					<!-- Inactive Sources with Data Available -->
					<div class="cwp-import-alert is-data-detected">
						<h3><?php esc_html_e('SEO Data Detected in Database', 'mihdan-index-now'); ?></h3>
						<p><?php esc_html_e('We found leftover metadata from previous SEO plugins:', 'mihdan-index-now'); ?></p>

						<div class="cwp-sources-selection">
							<?php foreach ($avail_sources as $src): ?>
								<label class="cwp-source-card">
									<input type="radio" name="chosen_source" value="<?php echo esc_attr($src['id']); ?>" <?php checked($src['id'], $primary_active['id']); ?> />
									<div class="cwp-source-card__content">
										<strong><?php echo esc_html($src['label']); ?></strong>
										<span><?php echo esc_html($src['posts'] . ' ' . __('posts', 'mihdan-index-now') . ', ' . $src['terms'] . ' ' . __('terms', 'mihdan-index-now') . ', ' . $src['redirects'] . ' ' . __('redirects', 'mihdan-index-now')); ?></span>
									</div>
								</label>
							<?php endforeach; ?>
						</div>

						<input type="hidden" id="cwpWizardSource" value="<?php echo esc_attr($primary_active['id']); ?>" />
						<input type="hidden" id="cwpWizardActiveSourceFile" value="" />

						<div class="cwp-import-options">
							<label class="cwp-checkbox-label">
								<input type="checkbox" id="cwpWizardOverwrite" value="1" checked="checked" />
								<span><?php esc_html_e('Overwrite any existing CrawlWP values with imported data', 'mihdan-index-now'); ?></span>
							</label>
						</div>

						<div class="cwp-import-progress-wrap" id="cwpImportProgressWrap" style="display: none;">
							<div class="cwp-import-progress-bar">
								<div class="cwp-import-progress-fill" id="cwpImportProgressFill"></div>
							</div>
							<div class="cwp-import-status-text" id="cwpImportStatusText"><?php esc_html_e('Preparing import…', 'mihdan-index-now'); ?></div>
						</div>

						<div class="cwp-import-action-row">
							<button type="button" class="button button-primary button-large" id="cwpStartImportBtn" data-source="<?php echo esc_attr($primary_active['id']); ?>">
								<span class="dashicons dashicons-update cwp-spin-icon" style="display: none;" aria-hidden="true"></span>
								<span class="cwp-btn-text"><?php esc_html_e('Import SEO Data', 'mihdan-index-now'); ?></span>
							</button>
							<button type="button" class="button button-secondary button-large cwp-btn-next" id="cwpImportContinueBtn" data-goto="site_info" style="display: none;">
								<?php esc_html_e('Continue to Site Identity →', 'mihdan-index-now'); ?>
							</button>
						</div>

						<div class="cwp-import-log" id="cwpImportLog" style="display: none;"></div>
					</div>

				<?php else: ?>
					<!-- No Existing SEO Plugins Detected -->
					<div class="cwp-import-empty">
						<span class="dashicons dashicons-yes-alt cwp-import-empty-icon" aria-hidden="true"></span>
						<h3><?php esc_html_e('No other SEO plugins detected', 'mihdan-index-now'); ?></h3>
						<p><?php esc_html_e('No previous third-party SEO plugins or legacy meta tags were found on this site. You can start fresh with clean CrawlWP settings!', 'mihdan-index-now'); ?></p>
					</div>
				<?php endif; ?>

				<div class="cwp-wizard-panel__footer">
					<button type="button" class="button button-secondary cwp-btn-prev" data-goto="welcome">
						<?php esc_html_e('← Back', 'mihdan-index-now'); ?>
					</button>
					<button type="button" class="button button-primary cwp-btn-next" data-goto="site_info">
						<?php esc_html_e('Continue without Importing →', 'mihdan-index-now'); ?>
					</button>
				</div>
			</section>

			<!-- STEP 3: SITE INFO -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'site_info' ? 'is-active' : ''; ?>" id="cwp-step-site_info">
				<div class="cwp-wizard-panel__head">
					<span class="cwp-wizard-eyebrow"><?php esc_html_e('Step 2 of 4', 'mihdan-index-now'); ?></span>
					<h2><?php esc_html_e('Site Representation & Schema', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'Structured data helps search engines like Google and Bing understand who owns this website and display rich Knowledge Graph panels.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<form id="cwpSiteInfoForm" class="cwp-wizard-form">
					<!-- Site Type -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_site_type">
							<?php esc_html_e('This website represents a…', 'mihdan-index-now'); ?>
						</label>
						<div class="cwp-radio-pill-group">
							<label class="cwp-radio-pill">
								<input type="radio" name="site_type" value="organization" <?php checked($site_type, 'organization'); ?> />
								<span><?php esc_html_e('Organization / Company', 'mihdan-index-now'); ?></span>
							</label>
							<label class="cwp-radio-pill">
								<input type="radio" name="site_type" value="person" <?php checked($site_type, 'person'); ?> />
								<span><?php esc_html_e('Individual / Person', 'mihdan-index-now'); ?></span>
							</label>
						</div>
						<p class="cwp-field-desc"><?php esc_html_e('Choose "Organization" for businesses and brands, or "Person" for personal portfolios and blogs.', 'mihdan-index-now'); ?></p>
					</div>

					<!-- Name -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_site_name">
							<?php esc_html_e('Name', 'mihdan-index-now'); ?>
						</label>
						<input type="text" id="cwp_site_name" name="site_name" class="regular-text" value="<?php echo esc_attr($site_name); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
						<p class="cwp-field-desc"><?php esc_html_e('The official name of the organization or individual.', 'mihdan-index-now'); ?></p>
					</div>

					<!-- Description / Tagline -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_site_desc">
							<?php esc_html_e('Description / Tagline', 'mihdan-index-now'); ?>
						</label>
						<input type="text" id="cwp_site_desc" name="site_description" class="regular-text" value="<?php echo esc_attr($site_desc); ?>" placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>" />
						<p class="cwp-field-desc"><?php esc_html_e('A concise description used in WebSite schema nodes.', 'mihdan-index-now'); ?></p>
					</div>

					<!-- Logo -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_site_logo">
							<?php esc_html_e('Logo / Profile Image', 'mihdan-index-now'); ?>
						</label>
						<div class="cwp-media-picker">
							<input type="text" id="cwp_site_logo" name="logo" class="regular-text cwp-media-url" value="<?php echo esc_attr($site_logo); ?>" placeholder="https://" />
							<button type="button" class="button cwp-media-upload-btn"><?php esc_html_e('Select Image', 'mihdan-index-now'); ?></button>
						</div>
						<div class="cwp-media-preview" id="cwpLogoPreview" style="<?php echo empty($site_logo) ? 'display: none;' : ''; ?>">
							<img src="<?php echo esc_url($site_logo); ?>" alt="<?php esc_attr_e('Logo preview', 'mihdan-index-now'); ?>" />
						</div>
						<p class="cwp-field-desc"><?php esc_html_e('Recommended: high-resolution square image, at least 112×112 px.', 'mihdan-index-now'); ?></p>
					</div>
				</form>

				<div class="cwp-wizard-panel__footer">
					<button type="button" class="button button-secondary cwp-btn-prev" data-goto="import">
						<?php esc_html_e('← Back', 'mihdan-index-now'); ?>
					</button>
					<button type="button" class="button button-primary cwp-btn-save-step" data-step="site_info" data-goto="search_appearance">
						<?php esc_html_e('Save & Continue →', 'mihdan-index-now'); ?>
					</button>
				</div>
			</section>

			<!-- STEP 4: SEARCH APPEARANCE -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'search_appearance' ? 'is-active' : ''; ?>" id="cwp-step-search_appearance">
				<div class="cwp-wizard-panel__head">
					<span class="cwp-wizard-eyebrow"><?php esc_html_e('Step 3 of 4', 'mihdan-index-now'); ?></span>
					<h2><?php esc_html_e('Search Appearance & Indexing', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'Configure standard title separators and choose which content types search engines should index.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<form id="cwpAppearanceForm" class="cwp-wizard-form">
					<!-- Title Separator -->
					<div class="cwp-form-field">
						<label class="cwp-form-label">
							<?php esc_html_e('Title Separator', 'mihdan-index-now'); ?>
						</label>
						<div class="cwp-separator-selector">
							<?php foreach ($separators as $val => $label): ?>
								<label class="cwp-sep-pill <?php echo $current_sep === $val ? 'is-selected' : ''; ?>">
									<input type="radio" name="separator" value="<?php echo esc_attr($val); ?>" <?php checked($current_sep, $val); ?> />
									<span class="cwp-sep-char"><?php echo esc_html($val); ?></span>
								</label>
							<?php endforeach; ?>
						</div>

						<div class="cwp-snippet-preview">
							<span class="cwp-snippet-url"><?php echo esc_html(home_url()); ?></span>
							<h3 class="cwp-snippet-title">
								<span class="cwp-preview-post"><?php esc_html_e('Article Title', 'mihdan-index-now'); ?></span>
								<span class="cwp-preview-sep"><?php echo esc_html($current_sep); ?></span>
								<span class="cwp-preview-site"><?php echo esc_html($site_name); ?></span>
							</h3>
						</div>
					</div>

					<!-- Homepage Title -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_home_title">
							<?php esc_html_e('Homepage Title Format', 'mihdan-index-now'); ?>
						</label>
						<input type="text" id="cwp_home_title" name="home_title" class="regular-text" value="<?php echo esc_attr($home_title); ?>" />
						<p class="cwp-field-desc"><?php esc_html_e('Supported variables: {{ site_title }}, {{ sep }}, {{ tagline }}.', 'mihdan-index-now'); ?></p>
					</div>

					<!-- Homepage Description -->
					<div class="cwp-form-field">
						<label class="cwp-form-label" for="cwp_home_description">
							<?php esc_html_e('Homepage Meta Description', 'mihdan-index-now'); ?>
						</label>
						<textarea id="cwp_home_description" name="home_description" rows="2" class="large-text"><?php echo esc_textarea($home_description); ?></textarea>
						<p class="cwp-field-desc"><?php esc_html_e('Summary shown under your homepage link in search engine results.', 'mihdan-index-now'); ?></p>
					</div>

					<!-- Content Types Indexing -->
					<div class="cwp-form-field">
						<label class="cwp-form-label">
							<?php esc_html_e('Content Types to Index', 'mihdan-index-now'); ?>
						</label>
						<p class="cwp-field-desc" style="margin-bottom: 8px;">
							<?php esc_html_e('Select which post types should be visible and indexed by Google and Bing:', 'mihdan-index-now'); ?>
						</p>

						<div class="cwp-pt-checkboxes">
							<?php foreach ($post_types as $pt_slug => $pt_obj): ?>
								<?php
								$is_noindex = Options::is_on('pt_' . $pt_slug, 'noindex');
								$checked    = ! $is_noindex;
								?>
								<label class="cwp-pt-checkbox">
									<input type="checkbox" name="indexed_post_types[]" value="<?php echo esc_attr($pt_slug); ?>" <?php checked($checked); ?> />
									<span><?php echo esc_html($pt_obj->label); ?> <code>(<?php echo esc_html($pt_slug); ?>)</code></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</form>

				<div class="cwp-wizard-panel__footer">
					<button type="button" class="button button-secondary cwp-btn-prev" data-goto="site_info">
						<?php esc_html_e('← Back', 'mihdan-index-now'); ?>
					</button>
					<button type="button" class="button button-primary cwp-btn-save-step" data-step="search_appearance" data-goto="index_now">
						<?php esc_html_e('Save & Continue →', 'mihdan-index-now'); ?>
					</button>
				</div>
			</section>

			<!-- STEP 5: INDEXNOW SUBMISSION -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'index_now' ? 'is-active' : ''; ?>" id="cwp-step-index_now">
				<div class="cwp-wizard-panel__head">
					<span class="cwp-wizard-eyebrow"><?php esc_html_e('Step 4 of 4', 'mihdan-index-now'); ?></span>
					<h2><?php esc_html_e('Instant Index Submission via IndexNow', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'IndexNow is an open protocol that instantly alerts participating search engines (like Microsoft Bing, Yandex, Seznam, and Naver) whenever content on your site is published or updated — ensuring rapid indexing without waiting for crawlers.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<form id="cwpIndexNowForm" class="cwp-wizard-form">
					<!-- Enable IndexNow Switch -->
					<div class="cwp-form-field">
						<label class="cwp-toggle-label">
							<span class="cwp-toggle-switch">
								<input type="checkbox" name="index_now_enable" id="cwp_index_now_enable" value="1" <?php checked($index_now_enabled); ?> />
								<span class="cwp-toggle-slider"></span>
							</span>
							<span class="cwp-toggle-text">
								<strong><?php esc_html_e('Enable IndexNow Automated Submission', 'mihdan-index-now'); ?></strong>
								<span class="cwp-field-desc"><?php esc_html_e('When active, search engines will be automatically notified whenever URLs are created or updated.', 'mihdan-index-now'); ?></span>
							</span>
						</label>
					</div>

					<div class="cwp-indexnow-details" id="cwpIndexNowDetails" style="<?php echo $index_now_enabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
						<!-- API Key -->
						<div class="cwp-form-field">
							<label class="cwp-form-label" for="cwp_indexnow_api_key">
								<?php esc_html_e('IndexNow API Key', 'mihdan-index-now'); ?>
							</label>
							<div class="cwp-key-group">
								<input type="text" id="cwp_indexnow_api_key" name="api_key" class="regular-text cwp-code-input" value="<?php echo esc_attr($api_key); ?>" spellcheck="false" autocomplete="off" />
								<button type="button" class="button button-secondary cwp-generate-key-btn" id="cwpGenerateKeyBtn">
									<span class="dashicons dashicons-image-rotate"></span>
									<span class="cwp-btn-label"><?php esc_html_e('Generate New Key', 'mihdan-index-now'); ?></span>
								</button>
								<button type="button" class="button button-secondary cwp-copy-key-btn" id="cwpCopyKeyBtn" title="<?php esc_attr_e('Copy API Key', 'mihdan-index-now'); ?>">
									<span class="dashicons dashicons-admin-page"></span>
									<span class="cwp-btn-label"><?php esc_html_e('Copy', 'mihdan-index-now'); ?></span>
								</button>
							</div>
							<div class="cwp-key-verification-notice">
								<span class="dashicons dashicons-yes-alt cwp-notice-icon"></span>
								<div class="cwp-key-verification-text">
									<strong><?php esc_html_e('Automatic Ownership Verification', 'mihdan-index-now'); ?></strong>
									<p>
										<?php esc_html_e('Search engines verify that you own this site by requesting your verification key file at:', 'mihdan-index-now'); ?>
										<br/>
										<a href="<?php echo esc_url($key_location); ?>" target="_blank" rel="noopener noreferrer" id="cwpKeyVerificationUrl" class="cwp-key-url">
											<code><?php echo esc_html($key_location); ?></code>
											<span class="dashicons dashicons-external"></span>
										</a>
									</p>
									<span class="cwp-badge-subtle"><?php esc_html_e('CrawlWP automatically hosts and serves this file for you.', 'mihdan-index-now'); ?></span>
								</div>
							</div>
						</div>

						<!-- Search Engine Target -->
						<div class="cwp-form-field">
							<label class="cwp-form-label">
								<?php esc_html_e('Primary Search Engine Endpoint', 'mihdan-index-now'); ?>
							</label>
							<div class="cwp-engine-cards">
								<?php foreach ($search_engines as $eng_id => $eng_data): ?>
									<label class="cwp-engine-card <?php echo $current_engine === $eng_id ? 'is-selected' : ''; ?>">
										<input type="radio" name="search_engine" value="<?php echo esc_attr($eng_id); ?>" <?php checked($current_engine, $eng_id); ?> />
										<div class="cwp-engine-card__content">
											<div class="cwp-engine-card__header">
												<strong><?php echo esc_html($eng_data['label']); ?></strong>
												<?php if (!empty($eng_data['badge'])): ?>
													<span class="cwp-badge-pill"><?php echo esc_html($eng_data['badge']); ?></span>
												<?php endif; ?>
											</div>
											<p class="cwp-engine-card__desc"><?php echo esc_html($eng_data['desc']); ?></p>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Content Types to Submit -->
						<div class="cwp-form-field">
							<label class="cwp-form-label">
								<?php esc_html_e('Content Types for Instant Submission', 'mihdan-index-now'); ?>
							</label>
							<p class="cwp-field-desc"><?php esc_html_e('Select which post types should be automatically submitted for indexing upon publishing or update:', 'mihdan-index-now'); ?></p>
							<div class="cwp-checkbox-grid">
								<?php foreach ($post_types as $pt_slug => $pt_obj): ?>
									<?php $checked = !empty($sub_post_types[$pt_slug]); ?>
									<label class="cwp-pt-checkbox">
										<input type="checkbox" name="submission_post_types[]" value="<?php echo esc_attr($pt_slug); ?>" <?php checked($checked); ?> />
										<span><?php echo esc_html($pt_obj->label); ?> <code>(<?php echo esc_html($pt_slug); ?>)</code></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Submission Triggers -->
						<div class="cwp-form-field">
							<label class="cwp-form-label">
								<?php esc_html_e('Automated Notification Triggers', 'mihdan-index-now'); ?>
							</label>
							<div class="cwp-triggers-group">
								<label class="cwp-trigger-checkbox">
									<input type="checkbox" name="ping_on_post" value="1" <?php checked($ping_on_post); ?> />
									<span>
										<strong><?php esc_html_e('Post published', 'mihdan-index-now'); ?></strong>
										<span class="cwp-field-desc"><?php esc_html_e('Notify search engines instantly when new content is published.', 'mihdan-index-now'); ?></span>
									</span>
								</label>
								<label class="cwp-trigger-checkbox">
									<input type="checkbox" name="ping_on_post_updated" value="1" <?php checked($ping_on_post_updated); ?> />
									<span>
										<strong><?php esc_html_e('Post updated', 'mihdan-index-now'); ?></strong>
										<span class="cwp-field-desc"><?php esc_html_e('Notify search engines when existing published content is modified.', 'mihdan-index-now'); ?></span>
									</span>
								</label>
							</div>
						</div>
					</div>
				</form>

				<div class="cwp-wizard-panel__footer">
					<button type="button" class="button button-secondary cwp-btn-prev" data-goto="search_appearance">
						<?php esc_html_e('← Back', 'mihdan-index-now'); ?>
					</button>
					<button type="button" class="button button-primary cwp-btn-save-step" data-step="index_now" data-goto="ready">
						<?php esc_html_e('Save & Finish Setup →', 'mihdan-index-now'); ?>
					</button>
				</div>
			</section>

			<!-- STEP 6: READY / COMPLETION -->
			<section class="cwp-wizard-panel <?php echo $current_step === 'ready' ? 'is-active' : ''; ?>" id="cwp-step-ready">
				<div class="cwp-wizard-panel__head cwp-panel-head-center">
					<div class="cwp-ready-icon" aria-hidden="true">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<h2><?php esc_html_e('You are ready to grow your traffic!', 'mihdan-index-now'); ?></h2>
					<p class="cwp-wizard-lead">
						<?php esc_html_e(
							'CrawlWP On-Page SEO is now configured and active on your website. Your metadata, schema graph, and indexing directives are active.',
							'mihdan-index-now'
						); ?>
					</p>
				</div>

				<div class="cwp-ready-checklist">
					<div class="cwp-ready-item">
						<span class="dashicons dashicons-yes cwp-check-green" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('On-page SEO enabled', 'mihdan-index-now'); ?></strong>
							<span><?php esc_html_e('Meta tags, Schema/JSON-LD, OpenGraph, XML sitemaps, and robots.txt are active.', 'mihdan-index-now'); ?></span>
						</div>
					</div>
					<div class="cwp-ready-item">
						<span class="dashicons dashicons-yes cwp-check-green" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('Site identity configured', 'mihdan-index-now'); ?></strong>
							<span><?php esc_html_e('Structured data schema will represent your brand correctly in search results.', 'mihdan-index-now'); ?></span>
						</div>
					</div>
					<div class="cwp-ready-item">
						<span class="dashicons dashicons-yes cwp-check-green" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('Search defaults established', 'mihdan-index-now'); ?></strong>
							<span><?php esc_html_e('Title separators and content type indexing rules are in effect.', 'mihdan-index-now'); ?></span>
						</div>
					</div>
					<div class="cwp-ready-item">
						<span class="dashicons dashicons-yes cwp-check-green" aria-hidden="true"></span>
						<div>
							<strong><?php esc_html_e('IndexNow submission configured', 'mihdan-index-now'); ?></strong>
							<span><?php esc_html_e('Search engines like Bing and Yandex will be notified immediately when content is published or updated.', 'mihdan-index-now'); ?></span>
						</div>
					</div>
				</div>

				<!-- Deactivate Old Plugin Card (if active plugin is present) -->
				<?php if (!empty($active_sources)): ?>
					<?php foreach ($active_sources as $active_src): ?>
						<div class="cwp-deactivate-card" id="cwpDeactCard-<?php echo esc_attr($active_src['id']); ?>">
							<div class="cwp-deactivate-card__body">
								<span class="dashicons dashicons-warning cwp-warn-icon" aria-hidden="true"></span>
								<div>
									<strong>
										<?php
										/* translators: %s: plugin name */
										printf(esc_html__('Avoid conflict: %s is still active', 'mihdan-index-now'), esc_html($active_src['label']));
										?>
									</strong>
									<p>
										<?php
										/* translators: %s: plugin name */
										printf(
											esc_html__('Running two SEO plugins at once output duplicate meta tags and confuse search engines. Since CrawlWP has imported your %s data, you can now safely deactivate it.', 'mihdan-index-now'),
											esc_html($active_src['label'])
										);
										?>
									</p>
								</div>
							</div>
							<div class="cwp-deactivate-card__action">
								<button type="button" class="button button-secondary cwp-deactivate-btn" data-source="<?php echo esc_attr($active_src['id']); ?>">
									<span class="cwp-btn-text">
										<?php
										/* translators: %s: plugin name */
										printf(esc_html__('Deactivate %s Now', 'mihdan-index-now'), esc_html($active_src['label']));
										?>
									</span>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<!-- Helpful Links / Next Steps -->
				<div class="cwp-next-steps-grid">
					<a href="<?php echo esc_url(admin_url('admin.php?page=crawlwp')); ?>" class="cwp-next-card">
						<span class="dashicons dashicons-admin-generic cwp-next-card__icon" aria-hidden="true"></span>
						<strong><?php esc_html_e('CrawlWP Dashboard', 'mihdan-index-now'); ?></strong>
						<span><?php esc_html_e('Review indexing statistics, logs, and API submission status.', 'mihdan-index-now'); ?></span>
					</a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=crawlwp&wposa-menu=crawlwp_log')); ?>" class="cwp-next-card">
						<span class="dashicons dashicons-list-view cwp-next-card__icon" aria-hidden="true"></span>
						<strong><?php esc_html_e('Indexing Logs', 'mihdan-index-now'); ?></strong>
						<span><?php esc_html_e('Track outbound index submissions and search engine verification requests.', 'mihdan-index-now'); ?></span>
					</a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=crawlwp&wposa-menu=crawlwp_title_meta')); ?>" class="cwp-next-card">
						<span class="dashicons dashicons-edit cwp-next-card__icon" aria-hidden="true"></span>
						<strong><?php esc_html_e('Title & Meta Settings', 'mihdan-index-now'); ?></strong>
						<span><?php esc_html_e('Fine-tune custom title formats for taxonomy archives and individual post types.', 'mihdan-index-now'); ?></span>
					</a>
					<a href="<?php echo esc_url(admin_url('edit.php')); ?>" class="cwp-next-card">
						<span class="dashicons dashicons-media-document cwp-next-card__icon" aria-hidden="true"></span>
						<strong><?php esc_html_e('Edit Posts & Pages', 'mihdan-index-now'); ?></strong>
						<span><?php esc_html_e('Use the CrawlWP SEO meta box in the block editor to craft custom snippets.', 'mihdan-index-now'); ?></span>
					</a>
				</div>

				<div class="cwp-wizard-panel__footer cwp-footer-center">
					<button type="button" class="button button-primary button-hero" id="cwpFinishWizardBtn">
						<?php esc_html_e('Finish & Return to Dashboard', 'mihdan-index-now'); ?>
					</button>
				</div>
			</section>

		</main>

	</div>
</div>
