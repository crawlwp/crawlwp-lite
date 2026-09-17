<?php

namespace Mihdan\IndexNow\SEOCore\Redirects;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Logs 404 hits and offers one-click redirect creation.
 *
 * The log is bounded: `url` carries a UNIQUE index so a 404 costs a single
 * INSERT … ON DUPLICATE KEY UPDATE, and a daily cron prunes entries older
 * than the configured retention (plus a hard row cap).
 */
class Monitor404
{
	const SECTION    = 'monitor_404';

	/** Cron hook pruning the log. */
	const PRUNE_HOOK = 'crawlwp_404_prune';

	/** Default retention in days (0 = keep forever). */
	const DEFAULT_RETENTION_DAYS = 30;

	/** Hard ceiling on stored rows, enforced by the prune cron. */
	const DEFAULT_MAX_ROWS = 10000;

	/** @var RedirectsManager */
	private RedirectsManager $manager;

	private string $table;

	public function __construct(RedirectsManager $manager)
	{
		global $wpdb;

		$this->manager = $manager;
		$this->table   = $wpdb->prefix . 'crawlwp_404_log';

		add_action('template_redirect', [$this, 'log'], 0);
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 26, 2);

		add_action(self::PRUNE_HOOK, [$this, 'prune']);

		if (! wp_next_scheduled(self::PRUNE_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
		}

		add_action('wp_ajax_crawlwp_404_list', [$this, 'ajax_list']);
		add_action('wp_ajax_crawlwp_404_redirect', [$this, 'ajax_redirect']);
		add_action('wp_ajax_crawlwp_404_delete', [$this, 'ajax_delete']);
		add_action('wp_ajax_crawlwp_404_clear', [$this, 'ajax_clear']);
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	public function log(): void
	{
		if (is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_404()) {
			return;
		}

		if (self::get('enabled', 'on') === 'off') {
			return;
		}

		$uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

		if ($uri === '' || preg_match('#\.(css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|mp4|zip)$#i', $uri)) {
			return;
		}

		$path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: $uri);
		$path = '/' . ltrim($path, '/');
		$path = substr($path, 0, 2048);

		$referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
		$referer = substr($referer, 0, 2048);

		global $wpdb;

		$now = current_time('mysql', true);

		$wpdb->query($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$this->table} (url, referer, hits, last_seen, created_at)
			VALUES (%s, %s, 1, %s, %s)
			ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referer = VALUES(referer)",
			$path,
			$referer,
			$now,
			$now
		));
	}

	// -------------------------------------------------------------------------
	// Pruning
	// -------------------------------------------------------------------------

	/**
	 * Delete log entries older than the configured retention and enforce the
	 * row cap. Hooked to the daily cron.
	 */
	public function prune(): void
	{
		global $wpdb;

		$days = (int) self::get('retention_days', self::DEFAULT_RETENTION_DAYS);

		/**
		 * Filters how many days of 404 log entries are kept (0 = forever).
		 *
		 * @param int $days Retention in days.
		 */
		$days = (int) apply_filters('crawlwp_404_retention_days', $days);

		if ($days > 0) {
			$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

			$wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->table} WHERE last_seen < %s",
				$cutoff
			));
		}

		/**
		 * Filters the maximum number of 404 log rows kept (0 = unlimited).
		 *
		 * @param int $max_rows Maximum rows.
		 */
		$max_rows = (int) apply_filters('crawlwp_404_max_rows', self::DEFAULT_MAX_ROWS);

		if ($max_rows > 0) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");

			if ($total > $max_rows) {
				$wpdb->query($wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$this->table} ORDER BY last_seen ASC, id ASC LIMIT %d",
					$total - $max_rows
				));
			}
		}
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('404 Monitor', 'mihdan-index-now'),
			'desc'           => __('Log 404 hits and create a redirect in one click.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'enabled',
			'type'    => 'switch',
			'name'    => __('Log 404 errors', 'mihdan-index-now'),
			'default' => 'on',
		]);

		$wposa->add_field(self::SECTION, [
			'id'      => 'retention_days',
			'type'    => 'number',
			'name'    => __('Keep entries for (days)', 'mihdan-index-now'),
			'desc'    => __('Entries whose last hit is older than this are deleted daily. Use 0 to keep them forever.', 'mihdan-index-now'),
			'default' => self::DEFAULT_RETENTION_DAYS,
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'manager_ui',
			'type' => 'html',
			'name' => '',
			'desc' => $this->render_ui(),
		]);
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function ajax_list(): void
	{
		$this->guard();

		global $wpdb;

		$page     = max(1, (int) ($_POST['page'] ?? 1));
		$per_page = min(200, max(5, (int) ($_POST['per_page'] ?? 20)));
		$search   = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));

		$where  = '';
		$values = [];

		if ($search !== '') {
			$like   = '%' . $wpdb->esc_like($search) . '%';
			$where  = ' WHERE (url LIKE %s OR referer LIKE %s)';
			$values = [$like, $like];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$this->table}{$where}";
		$total     = (int) ($values
			? $wpdb->get_var($wpdb->prepare($count_sql, $values))
			: $wpdb->get_var($count_sql));

		$pages  = max(1, (int) ceil($total / $per_page));
		$page   = min($page, $pages);
		$offset = ($page - 1) * $per_page;

		$rows = $wpdb->get_results($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$this->table}{$where} ORDER BY hits DESC, last_seen DESC, id DESC LIMIT %d OFFSET %d",
			array_merge($values, [$per_page, $offset])
		));

		ob_start();
		echo '<table class="widefat striped cwp-404-table"><thead><tr>';
		echo '<th class="cwp-404-col-url">' . esc_html__('URL', 'mihdan-index-now') . '</th>';
		echo '<th class="cwp-404-col-hits">' . esc_html__('Hits', 'mihdan-index-now') . '</th>';
		echo '<th class="cwp-404-col-date">' . esc_html__('Last seen', 'mihdan-index-now') . '</th>';
		echo '<th class="cwp-404-col-redirect">' . esc_html__('Redirect to', 'mihdan-index-now') . '</th>';
		echo '</tr></thead><tbody>';

		if (! $rows) {
			echo '<tr><td colspan="4" class="cwp-404-no-items">' . esc_html__('No 404s logged yet.', 'mihdan-index-now') . '</td></tr>';
		} else {
			foreach ($rows as $row) {
				echo '<tr data-id="' . esc_attr((string) $row->id) . '">';
				echo '<td class="cwp-404-col-url"><code title="' . esc_attr($row->url) . '">' . esc_html($row->url) . '</code></td>';
				echo '<td class="cwp-404-col-hits">' . esc_html(number_format_i18n((int) $row->hits)) . '</td>';
				echo '<td class="cwp-404-col-date">' . esc_html((string) $row->last_seen) . '</td>';
				echo '<td class="cwp-404-col-redirect"><div class="cwp-404-action-group">';
				echo '<input type="text" class="cwp-404-to" placeholder="/new-url"> ';
				echo '<button type="button" class="button button-small cwp-404-save">' . esc_html__('Redirect', 'mihdan-index-now') . '</button> ';
				echo '<button type="button" class="button-link-delete cwp-404-del">' . esc_html__('Delete', 'mihdan-index-now') . '</button>';
				echo '</div></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		if ($total > 0) {
			echo '<p><button type="button" class="button" id="cwp404Clear">' . esc_html__('Clear log', 'mihdan-index-now') . '</button></p>';
		}

		wp_send_json_success([
			'html'  => ob_get_clean(),
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		]);
	}

	public function ajax_redirect(): void
	{
		$this->guard();

		$id  = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$to  = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';

		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));

		if (! $row || $to === '') {
			wp_send_json_error(['message' => __('Missing URL.', 'mihdan-index-now')]);
		}

		if ($this->manager->exists_from_url((string) $row->url)) {
			wp_send_json_error(['message' => __('A redirect for this URL already exists.', 'mihdan-index-now')]);
		}

		$chain = $this->manager->check_redirect_chain((string) $row->url, $to);

		if (is_wp_error($chain)) {
			wp_send_json_error(['message' => $chain->get_error_message()]);
		}

		$ok = $this->manager->insert([
			'from_url'            => $row->url,
			'to_url'              => $to,
			'redirect_type'       => 301,
			'match_type'          => 'exact',
			'note'                => __('Created from 404 monitor', 'mihdan-index-now'),
			'ignore_query_string' => 1,
			'enabled'             => 1,
		]);

		if (! $ok) {
			wp_send_json_error(['message' => __('Could not create redirect.', 'mihdan-index-now')]);
		}

		$wpdb->delete($this->table, ['id' => $id], ['%d']);
		wp_send_json_success();
	}

	public function ajax_delete(): void
	{
		$this->guard();
		global $wpdb;
		$wpdb->delete($this->table, ['id' => absint($_POST['id'] ?? 0)], ['%d']);
		wp_send_json_success();
	}

	public function ajax_clear(): void
	{
		$this->guard();
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query("TRUNCATE TABLE {$this->table}");
		wp_send_json_success();
	}

	private function guard(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')], 403);
		}
		check_ajax_referer('crawlwp_404_nonce', 'nonce');
	}

	private function render_ui(): string
	{
		$nonce = wp_create_nonce('crawlwp_404_nonce');
		ob_start();
		?>
		<div id="cwp404" data-nonce="<?php echo esc_attr($nonce); ?>">
			<p class="cwp-404-toolbar">
				<button type="button" class="button" id="cwp404Refresh"><?php esc_html_e('Load 404 log', 'mihdan-index-now'); ?></button>
				<input type="search" id="cwp404Search" class="cwp-404-search"
				       placeholder="<?php esc_attr_e('Search URL…', 'mihdan-index-now'); ?>" />
				<label>
					<select id="cwp404PerPage">
						<option value="20">20</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
					<?php esc_html_e('items per page', 'mihdan-index-now'); ?>
				</label>
			</p>
			<div id="cwp404Table"></div>
			<p class="cwp-404-pagination">
				<span id="cwp404Total"></span>
				<span id="cwp404PageLinks"></span>
			</p>
		</div>
		<script>
		(function ($) {
			var state = { page: 1, per_page: 20, search: '' };
			var searchTimer = null;

			function load() {
				var $btn = $('#cwp404Refresh');
				$btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'crawlwp_404_list',
					nonce: $('#cwp404').data('nonce'),
					page: state.page,
					per_page: state.per_page,
					search: state.search
				}).done(function (res) {
					if (res && res.success) {
						$('#cwp404Table').html(res.data.html);
						$('#cwp404Total').text(res.data.total + ' ' + '<?php echo esc_js(__('entries', 'mihdan-index-now')); ?>');
						renderPages(res.data.page, res.data.pages);
					}
				}).always(function () {
					$btn.prop('disabled', false);
				});
			}

			function renderPages(current, pages) {
				var html = '';
				if (pages > 1) {
					if (current > 1) {
						html += '<button type="button" class="button cwp404Page" data-page="' + (current - 1) + '">&laquo;</button> ';
					}
					html += '<span class="cwp-404-page-info">' + current + ' / ' + pages + '</span> ';
					if (current < pages) {
						html += '<button type="button" class="button cwp404Page" data-page="' + (current + 1) + '">&raquo;</button>';
					}
				}
				$('#cwp404PageLinks').html(html);
			}

			$(document).on('click', '#cwp404Refresh', function () {
				state.page = 1;
				load();
			});
			$(document).on('click', '.cwp404Page', function () {
				state.page = parseInt($(this).data('page'), 10) || 1;
				load();
			});
			$(document).on('change', '#cwp404PerPage', function () {
				state.per_page = parseInt($(this).val(), 10) || 20;
				state.page = 1;
				load();
			});
			$(document).on('input', '#cwp404Search', function () {
				var val = $(this).val();
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () {
					state.search = val;
					state.page = 1;
					load();
				}, 300);
			});
			$(document).on('keydown', '.cwp-404-to', function (e) {
				if (e.which === 13) {
					e.preventDefault();
					$(this).closest('tr').find('.cwp-404-save').trigger('click');
				}
			});
			$(document).on('click', '.cwp-404-save', function () {
				var $btn = $(this);
				var $tr = $btn.closest('tr');
				var toUrl = $tr.find('.cwp-404-to').val();
				if (!toUrl) {
					$tr.find('.cwp-404-to').focus();
					return;
				}
				$btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'crawlwp_404_redirect',
					nonce: $('#cwp404').data('nonce'),
					id: $tr.data('id'),
					to: toUrl
				}).done(function (res) {
					if (res && !res.success && res.data && res.data.message) {
						window.alert(res.data.message);
						$btn.prop('disabled', false);
						return;
					}
					load();
				});
			});
			$(document).on('click', '.cwp-404-del', function () {
				var $tr = $(this).closest('tr');
				$.post(ajaxurl, { action: 'crawlwp_404_delete', nonce: $('#cwp404').data('nonce'), id: $tr.data('id') }).done(load);
			});
			$(document).on('click', '#cwp404Clear', function () {
				if (!window.confirm('<?php echo esc_js(__('Clear the 404 log?', 'mihdan-index-now')); ?>')) return;
				$.post(ajaxurl, { action: 'crawlwp_404_clear', nonce: $('#cwp404').data('nonce') }).done(function () {
					state.page = 1;
					load();
				});
			});
		}(jQuery));
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);
		return is_array($options) ? ($options[$field] ?? $default) : $default;
	}
}
