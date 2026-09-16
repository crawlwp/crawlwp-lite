<?php

namespace Mihdan\IndexNow\SEOCore\Notifications;

use Mihdan\IndexNow\SEOCore\CoreSettings\CoreSettings;
use Mihdan\IndexNow\SEOCore\FeatureGate\FeatureGate;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\Utils;

/**
 * CrawlWP SEO Notification Center.
 *
 * Replaces individual admin_notices with a single admin-bar notification bell
 * that shows a badge count and a dropdown panel listing all active SEO issues.
 * Each notice can be dismissed permanently per-site.
 *
 * Checks performed:
 *  1. WordPress "Discourage search engines" setting is enabled.
 *  2. Homepage is set to noindex.
 *  3. A conflicting SEO plugin is active alongside CrawlWP.
 *  4. Homepage has no SEO title configured.
 *  5. Homepage has no meta description configured.
 *  6. WordPress core sitemaps are disabled.
 *  7. A robots.txt file is actively blocking all crawlers.
 *  8. The site is using an HTTP URL (no SSL).
 *  9. A physical robots.txt file exists on the server, overriding WordPress's virtual one.
 * 10. The site is not using a pretty permalink structure.
 * 11. The RSS feed shows full post content instead of excerpts.
 * 12. A new public post type is detected.
 */
class Notifications
{
	/**
	 * WordPress option key for dismissed notices.
	 */
	private const DISMISSED_KEY = 'crawlwp_dismissed_notices';

	/**
	 * WordPress option key for known post types.
	 */
	public const KNOWN_POST_TYPES_KEY = 'crawlwp_known_post_types';

	/**
	 * Transient caching the robots.txt "blocks all crawlers" verdict.
	 *
	 * The check may have to fetch the site's own robots.txt over HTTP, which
	 * is far too expensive to repeat on every admin page load.
	 */
	private const ROBOTS_BLOCK_TRANSIENT = 'crawlwp_robots_txt_blocks_all';

	/**
	 * How long the robots.txt verdict stays cached.
	 */
	private const ROBOTS_BLOCK_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Every notice id this class can produce.
	 *
	 * Used to validate the id sent to the dismiss endpoint so arbitrary
	 * strings never end up in the options table.
	 *
	 * @var string[]
	 */
	private const NOTICE_IDS = [
		'blog_not_public',
		'crawlwp_site_noindex',
		'conflicting_seo_plugin',
		'missing_homepage_title',
		'missing_homepage_description',
		'sitemap_disabled',
		'robots_txt_blocking',
		'no_ssl',
		'physical_robots_txt_exists',
		'no_permalink_structure',
		'rss_full_text',
		'new_post_type',
	];

	/**
	 * Known conflicting SEO plugin basenames.
	 *
	 * @var string[]
	 */
	private const CONFLICTING_PLUGINS = [
		'wordpress-seo/wp-seo.php',
		'wordpress-seo-premium/wp-seo-premium.php',
		'all-in-one-seo-pack/all_in_one_seo_pack.php',
		'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
		'seo-by-rank-math/rank-math.php',
		'seo-by-rank-math-pro/rank-math-pro.php',
		'autodescription/autodescription.php',
		'slim-seo/slim-seo.php',
		'squirrly-seo/squirrly.php',
		'seopress/seopress.php',
		'seopress-pro/seopress-pro.php',
		'wp-seopress/wp-seopress.php',
	];

	/**
	 * Cached list of active (non-dismissed) notices for the current request.
	 *
	 * @var array[]|null
	 */
	private ?array $active_notices = null;

	public function __construct()
	{
		if (did_action('admin_menu')) {
			$this->add_menu_badge();
		} else {
			add_action('admin_menu', [$this, 'add_menu_badge'], 999);
		}

		add_action('admin_bar_menu', [$this, 'add_admin_bar_node'], 999);
		add_action('admin_head', [$this, 'print_styles']);
		add_action('admin_footer', [$this, 'print_panel_html']);
		add_action('admin_footer', [$this, 'print_scripts']);
		add_action('wp_ajax_crawlwp_dismiss_notice', [$this, 'ajax_dismiss_notice']);

		/* The cached robots.txt verdict is stale as soon as the editor is saved. */
		add_action('add_option_crawlwp_robots', [__CLASS__, 'flush_robots_txt_cache']);
		add_action('update_option_crawlwp_robots', [__CLASS__, 'flush_robots_txt_cache']);
	}

	/**
	 * Drop the cached robots.txt verdict so the next admin page load re-checks it.
	 */
	public static function flush_robots_txt_cache(): void
	{
		delete_transient(self::ROBOTS_BLOCK_TRANSIENT);
	}

	/**
	 * Public accessible post types that CrawlWP tracks.
	 *
	 * @return string[]
	 */
	public static function get_accessible_post_types(): array
	{
		$post_types = get_post_types(['public' => true], 'names');
		unset($post_types['attachment']);

		if (function_exists('is_post_type_viewable')) {
			$post_types = array_filter($post_types, 'is_post_type_viewable');
		}

		/**
		 * Filter accessible post types for CrawlWP SEO notifications.
		 *
		 * @param string[] $post_types Array of post type names.
		 */
		$post_types = (array) apply_filters('crawlwp_accessible_post_types', $post_types);

		return array_values(array_unique(array_map('strval', $post_types)));
	}

	/**
	 * Get newly detected post types that haven't been acknowledged/known yet.
	 *
	 * @return string[] List of new post type names.
	 */
	public static function get_new_post_types(): array
	{
		$known = get_option(self::KNOWN_POST_TYPES_KEY, null);
		$current = self::get_accessible_post_types();

		if ($known === null) {
			update_option(self::KNOWN_POST_TYPES_KEY, $current, false);
			return [];
		}

		if (!is_array($known)) {
			$known = (array) $known;
		}

		return array_values(array_diff($current, $known));
	}

	/**
	 * Update the list of known post types to match the currently accessible ones.
	 */
	public static function update_known_post_types(): void
	{
		update_option(self::KNOWN_POST_TYPES_KEY, self::get_accessible_post_types(), false);
	}

	// -------------------------------------------------------------------------
	// Admin Bar Node
	// -------------------------------------------------------------------------

	/**
	 * Add the CrawlWP notification bell to the WP admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function add_admin_bar_node(\WP_Admin_Bar $wp_admin_bar): void
	{
		if (!current_user_can('manage_options') || !is_admin()) {
			return;
		}

		$notices = $this->get_active_notices();

		if (empty($notices)) {
			return;
		}

		$count = count($notices);
		$has_error = !empty(array_filter($notices, fn($n) => $n['severity'] === 'error'));
		$bell_class = $has_error ? 'cwp-nc-bell cwp-nc-bell--error' : 'cwp-nc-bell cwp-nc-bell--warning';

		/* The bell icon SVG. */
		$bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false">'
			. '<path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>'
			. '</svg>';

		$title = '<span class="' . esc_attr($bell_class) . '">'
			. $bell_svg
			. '<span class="cwp-nc-badge" aria-label="' . esc_attr(sprintf(
			/* translators: %d number of SEO issues */
				_n('%d SEO issue', '%d SEO issues', $count, 'mihdan-index-now'),
				$count
			)) . '">' . esc_html((string)$count) . '</span>'
			. '</span>';

		$wp_admin_bar->add_node([
			'id' => 'crawlwp-notifications',
			'title' => $title,
			'href' => '#',
			'meta' => [
				'class' => 'cwp-nc-menu',
				'tabindex' => '0',
			],
		]);
	}

	// -------------------------------------------------------------------------
	// Admin Menu Badge
	// -------------------------------------------------------------------------

	/**
	 * Append notification count badge to the CrawlWP top-level admin menu.
	 */
	public function add_menu_badge(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$notices = $this->get_active_notices();

		global $menu;

		$menu_slug = defined('CRAWLWP_SLUG') ? CRAWLWP_SLUG : 'crawlwp';

		if (empty($notices)) {
			if (is_array($menu)) {
				foreach ($menu as $key => $item) {
					if (isset($item[2]) && $item[2] === $menu_slug && strpos($menu[$key][0], 'cwp-nc-menu-badge') !== false) {
						$menu[$key][0] = preg_replace(
							'/\s*<span class="[^"]*cwp-nc-menu-badge[^"]*">.*?<\/span><\/span>/s',
							'',
							$menu[$key][0]
						);
						break;
					}
				}
			}

			return;
		}

		if (!is_array($menu)) {
			return;
		}

		$count = count($notices);
		$formatted_count = number_format_i18n($count);

		$badge = sprintf(
			' <span class="update-plugins count-%1$d cwp-nc-menu-badge"><span class="plugin-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$count,
			esc_html($formatted_count),
			esc_html(sprintf(
				_n('%s notification', '%s notifications', $count, 'mihdan-index-now'),
				$formatted_count
			))
		);

		foreach ($menu as $key => $item) {
			if (isset($item[2]) && $item[2] === $menu_slug) {
				if (strpos($menu[$key][0], 'cwp-nc-menu-badge') !== false) {
					$menu[$key][0] = preg_replace(
						'/\s*<span class="[^"]*cwp-nc-menu-badge[^"]*">.*?<\/span><\/span>/s',
						$badge,
						$menu[$key][0]
					);
				} else {
					$menu[$key][0] .= $badge;
				}
				break;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Panel HTML (rendered in admin footer, toggled by JS)
	// -------------------------------------------------------------------------

	/**
	 * Render the notification center dropdown panel into the page footer.
	 * Visibility is controlled via JS.
	 */
	public function print_panel_html(): void
	{
		if (!current_user_can('manage_options') || !is_admin()) {
			return;
		}

		$notices = $this->get_active_notices();

		if (empty($notices)) {
			return;
		}

		$nonce = wp_create_nonce('crawlwp_dismiss_notice');
		?>
		<div id="cwp-nc-panel" class="cwp-nc-panel" role="dialog"
		     aria-label="<?php esc_attr_e('CrawlWP SEO Notifications', 'mihdan-index-now'); ?>" hidden>
			<div class="cwp-nc-panel__header">
				<span class="cwp-nc-panel__title">
					<?php
					$count = count($notices);
					printf(
					/* translators: %d number of SEO issues */
						esc_html(_n('%d SEO Issue', '%d SEO Issues', $count, 'mihdan-index-now')),
						(int)$count
					);
					?>
				</span>
				<button type="button" class="cwp-nc-panel__close"
				        aria-label="<?php esc_attr_e('Close notification panel', 'mihdan-index-now'); ?>">&#10005;
				</button>
			</div>
			<ul class="cwp-nc-panel__list">
				<?php foreach ($notices as $notice) : ?>
					<li class="cwp-nc-item cwp-nc-item--<?php echo esc_attr($notice['severity']); ?>"
					    data-notice-id="<?php echo esc_attr($notice['id']); ?>">
						<span class="cwp-nc-item__icon" aria-hidden="true">
							<?php echo $notice['severity'] === 'error' ? '&#9888;' : '&#9432;'; ?>
						</span>
						<span class="cwp-nc-item__message"><?php echo wp_kses_post($notice['message']); ?></span>
						<button type="button"
						        class="cwp-nc-item__dismiss"
						        data-notice-id="<?php echo esc_attr($notice['id']); ?>"
						        data-nonce="<?php echo esc_attr($nonce); ?>"
						        aria-label="<?php esc_attr_e('Dismiss this notice', 'mihdan-index-now'); ?>">
							&#10005;
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Inline Styles
	// -------------------------------------------------------------------------

	/**
	 * Print scoped CSS for the notification center.
	 * Loaded only in the WP admin.
	 */
	public function print_styles(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		if (empty($this->get_active_notices())) {
			return;
		}
		?>
		<style id="cwp-nc-styles">
			/* ---- Admin-bar bell ---- */
			#wp-admin-bar-crawlwp-notifications > .ab-item {
				display: flex !important;
				align-items: center;
				padding: 0 8px;
				cursor: pointer;
			}

			.cwp-nc-bell {
				display: flex;
				align-items: center;
				gap: 4px;
				line-height: 1;
			}

			.cwp-nc-bell svg {
				display: block;
				width: 18px;
				height: 18px;
			}

			/* Error = red tint, Warning = amber tint */
			.cwp-nc-bell--error svg {
				color: #ff8b8b;
			}

			.cwp-nc-bell--warning svg {
				color: #f0c040;
			}

			.cwp-nc-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 18px;
				height: 18px;
				padding: 0 5px;
				border-radius: 9px;
				font-size: 11px;
				font-weight: 700;
				line-height: 1;
				color: #fff;
			}

			.cwp-nc-bell--error .cwp-nc-badge {
				background: #cc1818;
			}

			.cwp-nc-bell--warning .cwp-nc-badge {
				background: #b57800;
			}

			/* ---- Dropdown panel ---- */
			.cwp-nc-panel {
				position: fixed;
				top: 32px; /* below the admin bar */
				right: 16px;
				z-index: 99999;
				width: 420px;
				max-width: calc(100vw - 32px);
				max-height: calc(100vh - 60px);
				overflow-y: auto;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				box-shadow: 0 4px 16px rgba(0, 0, 0, .18);
				font-size: 13px;
			}

			.cwp-nc-panel[hidden] {
				display: none;
			}

			.cwp-nc-panel__header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px 14px 10px;
				border-bottom: 1px solid #e0e0e0;
				background: #f6f7f7;
			}

			.cwp-nc-panel__title {
				font-weight: 600;
				font-size: 13px;
				color: #1d2327;
			}

			.cwp-nc-panel__close {
				background: none;
				border: none;
				cursor: pointer;
				color: #646970;
				font-size: 16px;
				line-height: 1;
				padding: 0 2px;
				border-radius: 3px;
			}

			.cwp-nc-panel__close:hover,
			.cwp-nc-panel__close:focus {
				color: #1d2327;
				background: #e0e0e0;
				outline: none;
			}

			.cwp-nc-panel__list {
				margin: 0;
				padding: 0;
				list-style: none;
			}

			/* ---- Individual notice items ---- */
			.cwp-nc-item {
				display: flex;
				align-items: flex-start;
				gap: 10px;
				padding: 12px 14px;
				border-bottom: 1px solid #f0f0f1;
				transition: background .1s;
			}

			.cwp-nc-item:last-child {
				border-bottom: none;
			}

			.cwp-nc-item:hover {
				background: #fafafa;
			}

			/* Left-border accent by severity */
			.cwp-nc-item--error {
				border-left: 3px solid #d63638;
			}

			.cwp-nc-item--warning {
				border-left: 3px solid #dba617;
			}

			.cwp-nc-item--success {
				border-left: 3px solid #00a32a;
			}

			.cwp-nc-item--info {
				border-left: 3px solid #72aee6;
			}

			.cwp-nc-item__icon {
				flex-shrink: 0;
				font-size: 15px;
				line-height: 1.4;
			}

			.cwp-nc-item--error .cwp-nc-item__icon {
				color: #d63638;
			}

			.cwp-nc-item--warning .cwp-nc-item__icon {
				color: #b57800;
			}

			.cwp-nc-item__message {
				flex: 1;
				line-height: 1.5;
				color: #1d2327;
			}

			.cwp-nc-item__message strong {
				font-weight: 600;
			}

			.cwp-nc-item__message a {
				color: #2271b1;
				text-decoration: none;
			}

			.cwp-nc-item__message a:hover {
				text-decoration: underline;
			}

			.cwp-nc-item__dismiss {
				flex-shrink: 0;
				background: none;
				border: none;
				cursor: pointer;
				color: #c3c4c7;
				font-size: 14px;
				line-height: 1;
				padding: 2px 4px;
				border-radius: 3px;
				align-self: center;
			}

			.cwp-nc-item__dismiss:hover,
			.cwp-nc-item__dismiss:focus {
				color: #646970;
				background: #e0e0e0;
				outline: none;
			}

			/* Slide-out animation on dismiss */
			.cwp-nc-item.is-dismissing {
				opacity: 0;
				max-height: 0;
				padding-top: 0;
				padding-bottom: 0;
				overflow: hidden;
				transition: opacity .2s, max-height .25s .05s, padding .25s .05s;
			}
		</style>
		<?php
	}

	// -------------------------------------------------------------------------
	// Scripts
	// -------------------------------------------------------------------------

	/**
	 * Print inline JS that wires up the bell toggle and dismiss buttons.
	 */
	public function print_scripts(): void
	{
		if (!current_user_can('manage_options') || !is_admin()) {
			return;
		}

		if (empty($this->get_active_notices())) {
			return;
		}
		?>
		<script id="cwp-nc-scripts">
			(function () {
				'use strict';

				var bellBtn = document.getElementById('wp-admin-bar-crawlwp-notifications');
				var panel = document.getElementById('cwp-nc-panel');
				var closeBtn = panel ? panel.querySelector('.cwp-nc-panel__close') : null;

				if (!bellBtn || !panel) return;

				/* Toggle the panel when the bell is clicked. */
				bellBtn.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					togglePanel();
				});

				/* Close via the × button. */
				if (closeBtn) {
					closeBtn.addEventListener('click', function () {
						closePanel();
					});
				}

				/* Close when clicking outside. */
				document.addEventListener('click', function (e) {
					if (!panel.hidden && !panel.contains(e.target) && !bellBtn.contains(e.target)) {
						closePanel();
					}
				});

				/* Close on Escape. */
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' && !panel.hidden) {
						closePanel();
						bellBtn.querySelector('a') && bellBtn.querySelector('a').focus();
					}
				});

				/* Dismiss individual notices. */
				panel.addEventListener('click', function (e) {
					var btn = e.target.closest('.cwp-nc-item__dismiss');
					if (!btn) return;

					var item = btn.closest('.cwp-nc-item');
					var noticeId = btn.dataset.noticeId;
					var nonce = btn.dataset.nonce;

					if (!item || !noticeId) return;

					/* Animate out. */
					item.classList.add('is-dismissing');

					setTimeout(function () {
						item.remove();
						updateBadge();

						/* If no items remain, close and hide the bell. */
						var remaining = panel.querySelectorAll('.cwp-nc-item');
						if (remaining.length === 0) {
							closePanel();
							if (bellBtn) bellBtn.style.display = 'none';
						}
					}, 300);

					/* Persist via AJAX. */
					var data = new FormData();
					data.append('action', 'crawlwp_dismiss_notice');
					data.append('nonce', nonce);
					data.append('notice_id', noticeId);

					fetch(typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php', {
						method: 'POST',
						body: data,
						credentials: 'same-origin'
					});
				});

				/* ---------- helpers ---------- */

				function togglePanel() {
					if (panel.hidden) {
						openPanel();
					} else {
						closePanel();
					}
				}

				function openPanel() {
					panel.hidden = false;
					panel.removeAttribute('hidden');
					bellBtn.setAttribute('aria-expanded', 'true');
					if (closeBtn) closeBtn.focus();
				}

				function closePanel() {
					panel.hidden = true;
					panel.setAttribute('hidden', '');
					bellBtn.setAttribute('aria-expanded', 'false');
				}

				function updateBadge() {
					var badge = bellBtn.querySelector('.cwp-nc-badge');
					var count = panel.querySelectorAll('.cwp-nc-item').length;
					if (badge) badge.textContent = count;

					var menuBadges = document.querySelectorAll('#adminmenu .cwp-nc-menu-badge');
					for (var i = 0; i < menuBadges.length; i++) {
						var mb = menuBadges[i];
						if (count > 0) {
							var countEl = mb.querySelector('.plugin-count') || mb;
							countEl.textContent = count;
							mb.className = mb.className.replace(/count-\d+/, 'count-' + count);
							var srText = mb.querySelector('.screen-reader-text');
							if (srText) {
								srText.textContent = count + ' ' + (count === 1 ? 'notification' : 'notifications');
							}
						} else {
							mb.remove();
						}
					}
				}
			}());
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Dismiss
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler for dismissing a notice permanently.
	 */
	public function ajax_dismiss_notice(): void
	{
		check_ajax_referer('crawlwp_dismiss_notice', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_die('', '', ['response' => 403]);
		}

		$notice_id = isset($_POST['notice_id']) ? sanitize_key($_POST['notice_id']) : '';

		if ($notice_id === '') {
			wp_send_json_error('missing notice_id');
		}

		/* Only ids this plugin actually renders may be stored. */
		if (! in_array($notice_id, $this->known_notice_ids(), true)) {
			wp_send_json_error('unknown notice_id');
		}

		if ($notice_id === 'new_post_type') {
			self::update_known_post_types();
			$dismissed = $this->get_dismissed();
			if (isset($dismissed['new_post_type'])) {
				unset($dismissed['new_post_type']);
				update_option(self::DISMISSED_KEY, $dismissed, false);
			}
		} else {
			$dismissed = $this->get_dismissed();
			$dismissed[$notice_id] = true;
			update_option(self::DISMISSED_KEY, $dismissed, false);
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Notice Collection
	// -------------------------------------------------------------------------

	/**
	 * Return the cached list of active (non-dismissed) notices.
	 *
	 * @return array[]
	 */
	public function get_active_notices(): array
	{
		if ($this->active_notices !== null) {
			return $this->active_notices;
		}

		$dismissed = $this->get_dismissed();
		$notices = $this->collect_notices();

		$this->active_notices = array_values(
			array_filter($notices, fn($n) => !isset($dismissed[$n['id']]))
		);

		return $this->active_notices;
	}

	/**
	 * Return the count of active notices.
	 */
	public function get_notification_count(): int
	{
		return count($this->get_active_notices());
	}

	/**
	 * Reset the cached active notices for testing or dynamic re-evaluations.
	 */
	public function reset_active_notices_cache(): void
	{
		$this->active_notices = null;
	}

	/**
	 * Collect all critical SEO notices that are currently triggered.
	 *
	 * @return array[] Each item: id, severity (error|warning|success|info), message.
	 */
	private function collect_notices(): array
	{
		$notices = [];

		$dismissed = $this->get_dismissed();

		/* Health checks are expensive, so never run one for a dismissed notice. */
		$wanted = fn(string $id): bool => ! isset($dismissed[$id]);

		/* 1. WordPress "Discourage search engines" setting. */
		if ($wanted('blog_not_public') && !get_option('blog_public', 1)) {
			$notices[] = [
				'id' => 'blog_not_public',
				'severity' => 'error',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('Your site is set to <strong>discourage search engines from indexing</strong>. Search engines will not index your pages. %1$sChange this setting%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(admin_url('options-reading.php')) . '">',
					'</a>'
				),
			];
		}

		/* 2. Homepage noindex check. */
		$homepage_noindex = $wanted('crawlwp_site_noindex') ? $this->get_homepage_noindex_info() : null;

		if ($homepage_noindex !== null) {
			$notices[] = [
				'id'       => 'crawlwp_site_noindex',
				'severity' => 'warning',
				'message'  => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					$homepage_noindex['message'],
					'<a href="' . esc_url($homepage_noindex['edit_url']) . '">',
					'</a>'
				),
			];
		}

		/* 3. Conflicting SEO plugin detected (only relevant when our output is active). */
		$conflict = $wanted('conflicting_seo_plugin') ? $this->detect_conflicting_plugin() : null;

		if ($conflict !== null) {
			$notices[] = [
				'id' => 'conflicting_seo_plugin',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: %s: conflicting plugin name */
					__('<strong>%s</strong> is also active on your site. Running two SEO plugins simultaneously can cause duplicate meta tags and conflicting settings. Please deactivate one of them.', 'mihdan-index-now'),
					esc_html($conflict)
				),
			];
		}

		/* 4. Missing homepage SEO title.
		 * When a static page is set as homepage, inspect its metabox data.
		 * Otherwise, the frontend falls back to the registered default template, so only
		 * warn when the stored value AND the default are both empty. */
		$check_homepage_title = $wanted('missing_homepage_title');

		if ($check_homepage_title && CoreSettings::is_static_front_page()) {
			$page_on_front_id = (int) get_option('page_on_front');
			$seo_title        = trim((string) MetaFields::get($page_on_front_id, MetaFields::SEO_TITLE, ''));

			if ($seo_title === '') {
				$edit_url = $this->get_static_front_page_edit_url($page_on_front_id);

				$notices[] = [
					'id'       => 'missing_homepage_title',
					'severity' => 'warning',
					'message'  => sprintf(
					/* translators: 1: link opening tag, 2: link closing tag */
						__('Your homepage has <strong>no SEO title configured</strong>. A descriptive title is critical for search engine rankings. %1$sConfigure now%2$s', 'mihdan-index-now'),
						'<a href="' . esc_url($edit_url) . '">',
						'</a>'
					),
				];
			}
		} elseif ($check_homepage_title && $this->home_template_is_empty('title')) {
			$notices[] = [
				'id' => 'missing_homepage_title',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('Your homepage has <strong>no SEO title template</strong> set. A descriptive title is critical for search engine rankings. %1$sConfigure now%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(add_query_arg(['wposa-menu' => 'crawlwp_tm_home'], CRAWLWP_SETTINGS_URL)) . '">',
					'</a>'
				),
			];
		}

		/* 5. Missing homepage meta description. */
		$check_homepage_description = $wanted('missing_homepage_description');

		if ($check_homepage_description && CoreSettings::is_static_front_page()) {
			$page_on_front_id = (int) get_option('page_on_front');
			$seo_description  = trim((string) MetaFields::get($page_on_front_id, MetaFields::SEO_DESCRIPTION, ''));

			if ($seo_description === '') {
				$edit_url = $this->get_static_front_page_edit_url($page_on_front_id);

				$notices[] = [
					'id'       => 'missing_homepage_description',
					'severity' => 'warning',
					'message'  => sprintf(
					/* translators: 1: link opening tag, 2: link closing tag */
						__('Your homepage has <strong>no meta description configured</strong>. A good description improves click-through rates from search results. %1$sConfigure now%2$s', 'mihdan-index-now'),
						'<a href="' . esc_url($edit_url) . '">',
						'</a>'
					),
				];
			}
		} elseif ($check_homepage_description && $this->home_template_is_empty('description')) {
			$notices[] = [
				'id' => 'missing_homepage_description',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('Your homepage has <strong>no meta description template</strong> set. A good description improves click-through rates from search results. %1$sConfigure now%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(add_query_arg(['wposa-menu' => 'crawlwp_tm_home'], CRAWLWP_SETTINGS_URL)) . '">',
					'</a>'
				),
			];
		}

		/* 6. WordPress core sitemaps disabled. */
		if ($wanted('sitemap_disabled') && !$this->is_sitemap_enabled()) {
			$notices[] = [
				'id' => 'sitemap_disabled',
				'severity' => 'warning',
				'message' => __('The <strong>WordPress XML sitemap is disabled</strong>. Without a sitemap, search engines may have difficulty discovering all your pages.', 'mihdan-index-now'),
			];
		}

		/* 7. robots.txt blocking all crawlers. */
		if ($wanted('robots_txt_blocking') && $this->robots_txt_blocks_all()) {
			$notices[] = [
				'id' => 'robots_txt_blocking',
				'severity' => 'error',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('Your <strong>robots.txt file is blocking all search engine crawlers</strong>. Search engines cannot index any of your pages. %1$sEdit robots.txt%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(CRAWLWP_ADVANCED_SETTINGS_URL . '#crawlwp_robots') . '">',
					'</a>'
				),
			];
		}

		/* 8. No SSL / HTTPS. */
		if ($wanted('no_ssl') && !is_ssl() && !$this->site_uses_https()) {
			$notices[] = [
				'id' => 'no_ssl',
				'severity' => 'warning',
				'message' => __('Your site is <strong>not using HTTPS</strong>. Google gives a ranking advantage to secure (HTTPS) sites. Contact your host to install an SSL certificate.', 'mihdan-index-now'),
			];
		}

		/* 9. Physical robots.txt file on the server overrides WordPress's virtual one. */
		if ($wanted('physical_robots_txt_exists') && $this->physical_robots_txt_exists()) {
			$notices[] = [
				'id' => 'physical_robots_txt_exists',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: %s: absolute path to the physical robots.txt file */
					__('A <strong>physical robots.txt file</strong> was found at in the root folder of your WordPress installation. This file takes precedence over WordPress\'s virtual robots.txt, which means CrawlWP\'s Robots.txt editor (and any other plugin relying on the <code>robots_txt</code> filter) has no effect. Edit or remove that file directly to manage robots.txt through CrawlWP.', 'mihdan-index-now')
				),
			];
		}

		/* 10. Not using a pretty permalink structure. */
		if ($wanted('no_permalink_structure') && !get_option('permalink_structure')) {
			$notices[] = [
				'id' => 'no_permalink_structure',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('You are <strong>not using a pretty permalink structure</strong>. Plain "?p=123" style URLs are less descriptive and can hurt SEO. %1$sFix this%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(admin_url('options-permalink.php')) . '">',
					'</a>'
				),
			];
		}

		/* 11. RSS feed shows full text instead of a summary. */
		if ($wanted('rss_full_text') && !get_option('rss_use_excerpt')) {
			$notices[] = [
				'id' => 'rss_full_text',
				'severity' => 'warning',
				'message' => sprintf(
				/* translators: 1: link opening tag, 2: link closing tag */
					__('Your <strong>RSS feed shows full post content</strong> instead of a summary. This makes it easier for scrapers to republish your content as their own. %1$sFix this%2$s', 'mihdan-index-now'),
					'<a href="' . esc_url(admin_url('options-reading.php')) . '">',
					'</a>'
				),
			];
		}

		/* 12. New post type detected. */
		if ($wanted('new_post_type')) {
			$new_post_types = self::get_new_post_types();

			if (!empty($new_post_types)) {
				$first_post_type = reset($new_post_types);
				$count = count($new_post_types);
				$list = '<code>' . implode('</code>, <code>', array_map('esc_html', $new_post_types)) . '</code>';

				$settings_url = defined('CRAWLWP_SETTINGS_URL') ? CRAWLWP_SETTINGS_URL : admin_url('admin.php?page=' . (defined('CRAWLWP_SLUG') ? CRAWLWP_SLUG : 'crawlwp'));
				$titles_meta_url = add_query_arg(['wposa-menu' => Utils::get_plugin_prefix() . '_title_meta'], $settings_url) . '#crawlwp_tm_pt_' . $first_post_type;
				$sitemap_url = add_query_arg(['wposa-menu' => Utils::get_plugin_prefix() . '_advanced_settings'], $settings_url) . '#crawlwp_sitemap_settings';

				if ($count > 1) {
					/* translators: 1: comma-separated list of post type names, 2: link opening tag, 3: link closing tag, 4: link opening tag, 5: link closing tag */
					$message = __('CrawlWP has detected new post types: %1$s. You may want to check the settings of the %2$sTitles & Meta page%3$s and %4$sthe Sitemap%5$s.', 'mihdan-index-now');
				} else {
					/* translators: 1: post type name, 2: link opening tag, 3: link closing tag, 4: link opening tag, 5: link closing tag */
					$message = __('CrawlWP has detected a new post type: %1$s. You may want to check the settings of the %2$sTitles & Meta page%3$s and %4$sthe Sitemap%5$s.', 'mihdan-index-now');
				}

				/**
				 * Filter the new post type notification message template.
				 *
				 * @param string $message
				 * @param int    $count
				 */
				$message = (string) apply_filters('crawlwp_admin_notice_new_post_type', $message, $count);

				$notices[] = [
					'id'       => 'new_post_type',
					'severity' => 'info',
					'message'  => sprintf(
						$message,
						$list,
						'<a href="' . esc_url($titles_meta_url) . '">',
						'</a>',
						'<a href="' . esc_url($sitemap_url) . '">',
						'</a>'
					),
				];
			}
		}

		/**
		 * Filter the list of critical SEO notices before display.
		 *
		 * @param array[] $notices Array of notice definition arrays.
		 */
		return (array)apply_filters('crawlwp_seo_notices', $notices);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the list of dismissed notice IDs for the current site.
	 *
	 * @return array<string, true>
	 */
	private function get_dismissed(): array
	{
		$stored = get_option(self::DISMISSED_KEY, []);
		return is_array($stored) ? $stored : [];
	}

	/**
	 * Notice ids that may be dismissed (and therefore stored).
	 *
	 * @return string[]
	 */
	private function known_notice_ids(): array
	{
		/**
		 * Filter the notice ids the dismiss endpoint accepts.
		 *
		 * Extensions adding notices through `crawlwp_seo_notices` must register
		 * their ids here for those notices to be dismissible.
		 *
		 * @param string[] $ids Known notice ids.
		 */
		$ids = (array) apply_filters('crawlwp_seo_notice_ids', self::NOTICE_IDS);

		return array_values(array_filter(array_map('strval', $ids)));
	}

	/**
	 * Detect the first active conflicting SEO plugin.
	 *
	 * @return string|null Human-readable plugin name, or null if none found.
	 */
	private function detect_conflicting_plugin(): ?string
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array)get_option('active_plugins', []);

		/* Include network-activated plugins for multisite. */
		if (is_multisite()) {
			$network_active = array_keys((array)get_site_option('active_sitewide_plugins', []));
			$active = array_merge($active, $network_active);
		}

		foreach (self::CONFLICTING_PLUGINS as $basename) {
			if (in_array($basename, $active, true)) {
				$all_plugins = get_plugins();

				if (isset($all_plugins[$basename]['Name'])) {
					return $all_plugins[$basename]['Name'];
				}

				return $basename;
			}
		}

		return null;
	}

	/**
	 * Whether a homepage title/description template resolves to nothing.
	 *
	 * Mirrors FrontendOutput: a stored value wins, otherwise the default
	 * registered in Entities is used. Only when both are empty is the tag
	 * really missing on the frontend.
	 *
	 * @param string $field 'title' or 'description'.
	 * @return bool
	 */
	private function home_template_is_empty(string $field): bool
	{
		$stored = trim((string) Options::get('home', $field, ''));

		if ($stored !== '') {
			return false;
		}

		return trim(Entities::default_value('home', $field, '')) === '';
	}

	/**
	 * Check whether the homepage is configured as noindex.
	 *
	 * When a static page is set as the front page, checks the SEO metabox
	 * setting on that page first (which overrides global settings). If not
	 * overridden, or when the homepage is set to display latest posts, checks
	 * the global CrawlWP Title & Meta setting.
	 *
	 * @return array{message: string, edit_url: string}|null Null if homepage is indexable.
	 */
	private function get_homepage_noindex_info(): ?array
	{
		$is_noindexed = false;
		$edit_url     = '';
		$message      = '';

		if (CoreSettings::is_static_front_page()) {
			$page_on_front_id = (int) get_option('page_on_front');
			$robots_index     = (string) MetaFields::get($page_on_front_id, MetaFields::ROBOTS_INDEX, '');

			if ($robots_index === 'noindex') {
				$is_noindexed = true;
				$edit_url     = $this->get_static_front_page_edit_url($page_on_front_id);
				$message      = __('Your homepage is set to <strong>noindex</strong>. Search engines will not index your homepage. %1$sEdit homepage%2$s', 'mihdan-index-now');
			} elseif ($robots_index !== 'index' && Options::is_on('home', 'noindex')) {
				$is_noindexed = true;
				$edit_url     = add_query_arg(['wposa-menu' => 'crawlwp_tm_home'], CRAWLWP_SETTINGS_URL);
				$message      = __('Your homepage is set to <strong>noindex</strong> in CrawlWP Title &amp; Meta settings. Search engines will not index your homepage. %1$sReview settings%2$s', 'mihdan-index-now');
			}
		} elseif (Options::is_on('home', 'noindex')) {
			$is_noindexed = true;
			$edit_url     = add_query_arg(['wposa-menu' => 'crawlwp_tm_home'], CRAWLWP_SETTINGS_URL);
			$message      = __('Your homepage is set to <strong>noindex</strong> in CrawlWP Title &amp; Meta settings. Search engines will not index your homepage. %1$sReview settings%2$s', 'mihdan-index-now');
		}

		/* Check if developer filters alter the robots directives for the homepage. */
		$directives = [$is_noindexed ? 'noindex' : 'index'];
		$directives = (array) apply_filters('crawlwp_robots_directives', $directives, 'home');

		if (!in_array('noindex', $directives, true)) {
			return null;
		}

		if (!$is_noindexed) {
			// A developer filter added noindex.
			$edit_url = CoreSettings::is_static_front_page()
				? $this->get_static_front_page_edit_url((int) get_option('page_on_front'))
				: add_query_arg(['wposa-menu' => 'crawlwp_tm_home'], CRAWLWP_SETTINGS_URL);
			$message  = __('Your homepage is set to <strong>noindex</strong>. Search engines will not index your homepage. %1$sReview settings%2$s', 'mihdan-index-now');
		}

		return [
			'message'  => $message,
			'edit_url' => $edit_url,
		];
	}

	/**
	 * Get the edit URL for the static front page.
	 *
	 * @param int $post_id Post ID of the front page.
	 * @return string
	 */
	private function get_static_front_page_edit_url(int $post_id): string
	{
		$edit_url = get_edit_post_link($post_id);

		if (!$edit_url && $post_id > 0) {
			$edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
		}

		return (string) $edit_url;
	}

	/**
	 * Whether the WordPress core sitemap is accessible.
	 *
	 * @return bool
	 */
	private function is_sitemap_enabled(): bool
	{
		/** @global \WP_Sitemaps $wp_sitemaps */
		global $wp_sitemaps;

		return is_a($wp_sitemaps, \WP_Sitemaps::class) && $wp_sitemaps->sitemaps_enabled();
	}

	/**
	 * Parse a physical (or virtual) robots.txt to detect a `Disallow: /` for User-agent: *.
	 *
	 * @return bool True if a catch-all Disallow is found.
	 */
	private function robots_txt_blocks_all(): bool
	{
		static $checked = null;

		if ($checked !== null) {
			return $checked;
		}

		/* Remote robots.txt fetches are far too slow to repeat per page load. */
		$cached = get_transient(self::ROBOTS_BLOCK_TRANSIENT);

		if ($cached !== false) {
			$checked = ('1' === $cached);

			return $checked;
		}

		$file_path = $this->get_physical_robots_txt_path();
		$fs = $this->get_filesystem();
		$exists = $fs !== null ? $fs->exists($file_path) : file_exists($file_path);

		if ($exists) {
			$content = $fs !== null ? $fs->get_contents($file_path) : @file_get_contents($file_path);
		} else {
			$robots_url = home_url('/robots.txt');
			$response = wp_remote_get($robots_url, [
				'timeout' => 5,
				'user-agent' => 'CrawlWP-SEO-Checker',
			]);

			$content = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
		}

		$checked = $this->content_blocks_all_crawlers((string)$content);

		set_transient(self::ROBOTS_BLOCK_TRANSIENT, $checked ? '1' : '0', self::ROBOTS_BLOCK_TTL);

		return $checked;
	}

	/**
	 * Parse robots.txt content for a wildcard `Disallow: /`.
	 *
	 * @param string $content Raw robots.txt content.
	 * @return bool
	 */
	private function content_blocks_all_crawlers(string $content): bool
	{
		if ($content === '') {
			return false;
		}

		$lines = explode("\n", str_replace("\r\n", "\n", $content));
		$in_wildcard = false;

		foreach ($lines as $line) {
			$line = trim($line);

			if ($line === '' || $line[0] === '#') {
				continue;
			}

			if (stripos($line, 'user-agent:') === 0) {
				$agent = trim(substr($line, strlen('user-agent:')));
				$in_wildcard = ($agent === '*');
				continue;
			}

			if ($in_wildcard && stripos($line, 'disallow:') === 0) {
				$path = trim(substr($line, strlen('disallow:')));

				if ($path === '/') {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether the site's home URL uses HTTPS.
	 *
	 * @return bool
	 */
	private function site_uses_https(): bool
	{
		return str_starts_with(get_option('home', ''), 'https://');
	}

	/**
	 * Whether a physical robots.txt file exists in the site's root directory.
	 *
	 * When present, this file is served directly by the web server (or matched
	 * before WordPress's own rewrite rules), so WordPress's virtual robots.txt
	 * (and the `robots_txt` filter CrawlWP's Robots.txt editor relies on) never runs.
	 *
	 * Uses the WordPress Filesystem API (`WP_Filesystem`) rather than raw PHP
	 * file functions, so the check honours the filesystem method WordPress is
	 * actually configured to use (direct, ftpext, ssh2, etc.) instead of
	 * assuming local PHP file access is always the right way to reach it.
	 *
	 * @return bool
	 */
	private function physical_robots_txt_exists(): bool
	{
		$path = $this->get_physical_robots_txt_path();
		$fs = $this->get_filesystem();

		if ($fs !== null) {
			return $fs->exists($path);
		}

		// Fall back to a direct PHP check when the Filesystem API can't
		// initialise without prompting for FTP/SSH credentials — this is a
		// read-only existence check, so it must never block on credentials.
		return file_exists($path);
	}

	/**
	 * Absolute filesystem path where a physical robots.txt would live.
	 *
	 * @return string
	 */
	private function get_physical_robots_txt_path(): string
	{
		return get_home_path() . 'robots.txt';
	}

	/**
	 * Get a ready-to-use WordPress Filesystem API instance for read-only checks.
	 *
	 * Only initialises when the "direct" filesystem method is available, so
	 * this never triggers an FTP/SSH credentials prompt on hosts that require
	 * one — callers should fall back to direct PHP file functions when this
	 * returns null.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private function get_filesystem()
	{
		global $wp_filesystem;

		if ($wp_filesystem instanceof \WP_Filesystem_Base) {
			return $wp_filesystem;
		}

		if (!function_exists('WP_Filesystem') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (get_filesystem_method() !== 'direct' || !WP_Filesystem()) {
			return null;
		}

		// @phpstan-ignore-next-line
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
}
