<?php

namespace Mihdan\IndexNow\SEOCore\Redirects;

use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Admin UI for the CrawlWP Redirects feature.
 *
 * Registers a "Redirects" section under the "Advanced" settings tab.
 * Renders a custom AJAX-powered management table with add/edit modal,
 * bulk actions, search, and type filter.
 *
 * AJAX actions (all require manage_options + nonce):
 *   crawlwp_redirect_list   — fetch table HTML
 *   crawlwp_redirect_save   — insert or update a redirect
 *   crawlwp_redirect_delete — delete by ID
 *   crawlwp_redirect_toggle — toggle enabled state
 *   crawlwp_redirect_bulk   — bulk action (delete / enable / disable)
 */
class RedirectsSettings
{
	/** Section / option id (without the crawlwp_ prefix). */
	const SECTION = 'redirects';

	/** @var RedirectsManager */
	private RedirectsManager $manager;

	public function __construct(RedirectsManager $manager)
	{
		$this->manager = $manager;

		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 25, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		// AJAX handlers.
		add_action('wp_ajax_crawlwp_redirect_list', [$this, 'ajax_list']);
		add_action('wp_ajax_crawlwp_redirect_save', [$this, 'ajax_save']);
		add_action('wp_ajax_crawlwp_redirect_delete', [$this, 'ajax_delete']);
		add_action('wp_ajax_crawlwp_redirect_toggle', [$this, 'ajax_toggle']);
		add_action('wp_ajax_crawlwp_redirect_bulk', [$this, 'ajax_bulk']);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Redirects', 'mihdan-index-now'),
			'desc'           => __('Manage URL redirects. Add manual redirects, and CrawlWP will also automatically create 301 redirects whenever you change a post\'s permalink.', 'mihdan-index-now'),
		]);

		$wposa->add_field(self::SECTION, [
			'id'   => 'manager_ui',
			'type' => 'html',
			'name' => '',
			'desc' => $this->render_ui(),
		]);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue JS and CSS on CrawlWP admin pages only.
	 */
	public function enqueue_assets(string $hook): void
	{
		$screen = get_current_screen();

		if (!$screen || strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		$assets_url = CRAWLWP_PLUGIN_URL . 'src/SEOCore/Redirects/assets/';

		wp_enqueue_style(
			'crawlwp-redirects',
			$assets_url . 'redirects.css',
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'crawlwp-redirects',
			$assets_url . 'redirects.js',
			['jquery'],
			'1.0.0',
			true
		);

		wp_localize_script('crawlwp-redirects', 'crawlwpRedirects', [
			'ajaxUrl'  => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('crawlwp_redirects_nonce'),
			'i18n'     => [
				'confirmDelete' => __('Delete this redirect? This action cannot be undone.', 'mihdan-index-now'),
				'confirmBulk'   => __('Apply this action to the selected redirects?', 'mihdan-index-now'),
				'saving'        => __('Saving…', 'mihdan-index-now'),
				'noItems'       => __('No redirects found.', 'mihdan-index-now'),
				'addTitle'      => __('Add Redirect', 'mihdan-index-now'),
				'editTitle'     => __('Edit Redirect', 'mihdan-index-now'),
				'addBtn'        => __('Add Redirect', 'mihdan-index-now'),
				'updateBtn'     => __('Update Redirect', 'mihdan-index-now'),
			],
		]);
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Return the rendered redirects table HTML.
	 */
	public function ajax_list(): void
	{
		check_ajax_referer('crawlwp_redirects_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$args = [
			'search'   => sanitize_text_field($_POST['search'] ?? ''),
			'status'   => sanitize_text_field($_POST['status'] ?? 'all'),
			'type'     => (int) ($_POST['type'] ?? 0),
			'per_page' => max(1, (int) ($_POST['per_page'] ?? 20)),
			'page'     => max(1, (int) ($_POST['page'] ?? 1)),
			'orderby'  => sanitize_key($_POST['orderby'] ?? 'id'),
			'order'    => sanitize_text_field($_POST['order'] ?? 'DESC'),
		];

		$redirects = $this->manager->get_all($args);
		$total     = $this->manager->get_count($args);

		wp_send_json_success([
			'html'  => $this->render_table_rows($redirects),
			'total' => $total,
			'page'  => $args['page'],
			'pages' => max(1, (int) ceil($total / $args['per_page'])),
		]);
	}

	/**
	 * Insert or update a redirect.
	 */
	public function ajax_save(): void
	{
		check_ajax_referer('crawlwp_redirects_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$id   = (int) ($_POST['id'] ?? 0);
		$data = [
			'from_url'            => $_POST['from_url'] ?? '',
			'to_url'              => $_POST['to_url'] ?? '',
			'redirect_type'       => (int) ($_POST['redirect_type'] ?? 301),
			'match_type'          => $_POST['match_type'] ?? 'exact',
			'note'                => $_POST['note'] ?? '',
			'ignore_query_string' => (int) ($_POST['ignore_query_string'] ?? 1),
			'enabled'             => (int) ($_POST['enabled'] ?? 1),
		];

		$data['priority']       = (int) ($_POST['priority'] ?? RedirectsManager::DEFAULT_PRIORITY);
		$data['allow_external'] = (int) ($_POST['allow_external'] ?? 0);

		if (empty($data['from_url'])) {
			wp_send_json_error(['message' => __('From URL is required.', 'mihdan-index-now')]);
		}

		// For 410/451 types, to_url is not needed.
		$type = (int) $data['redirect_type'];
		if ($type !== 410 && $type !== 451 && empty($data['to_url'])) {
			wp_send_json_error(['message' => __('To URL is required for this redirect type.', 'mihdan-index-now')]);
		}

		// Surface field-level validation errors (invalid regex, unsafe To URL, …).
		$validation = $this->manager->validate($data);
		if (is_wp_error($validation)) {
			wp_send_json_error(['message' => $validation->get_error_message()]);
		}

		// Refuse a second rule for the same source (the row being edited is
		// excluded so updating it in place still works).
		if ($this->manager->exists_from_url((string) $data['from_url'], $id, (string) $data['match_type'])) {
			wp_send_json_error(['message' => __('A redirect for this From URL already exists. Edit the existing rule instead.', 'mihdan-index-now')]);
		}

		// Refuse loops and long chains (A→B, B→A, …).
		if ($type !== 410 && $type !== 451) {
			$chain = $this->manager->check_redirect_chain((string) $data['from_url'], (string) $data['to_url'], $id);

			if (is_wp_error($chain)) {
				wp_send_json_error(['message' => $chain->get_error_message()]);
			}
		}

		if ($id > 0) {
			$result = $this->manager->update($id, $data);
			$redirect = $this->manager->get($id);
		} else {
			$id = $this->manager->insert($data);
			$result = $id !== false;
			$redirect = $id ? $this->manager->get($id) : null;
		}

		if (!$result || !$redirect) {
			wp_send_json_error(['message' => __('Failed to save redirect.', 'mihdan-index-now')]);
		}

		wp_send_json_success([
			'message'  => __('Redirect saved.', 'mihdan-index-now'),
			'redirect' => $redirect,
			'row_html' => $this->render_table_rows([$redirect]),
		]);
	}

	/**
	 * Delete a redirect by ID.
	 */
	public function ajax_delete(): void
	{
		check_ajax_referer('crawlwp_redirects_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$id = (int) ($_POST['id'] ?? 0);

		if (!$id || !$this->manager->delete($id)) {
			wp_send_json_error(['message' => __('Failed to delete redirect.', 'mihdan-index-now')]);
		}

		wp_send_json_success(['message' => __('Redirect deleted.', 'mihdan-index-now'), 'id' => $id]);
	}

	/**
	 * Toggle the enabled state of a redirect.
	 */
	public function ajax_toggle(): void
	{
		check_ajax_referer('crawlwp_redirects_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$id = (int) ($_POST['id'] ?? 0);
		$redirect = $this->manager->get($id);

		if (!$redirect) {
			wp_send_json_error(['message' => __('Redirect not found.', 'mihdan-index-now')]);
		}

		$new_state = (int) !((bool) $redirect->enabled);
		$this->manager->update($id, ['enabled' => $new_state]);

		wp_send_json_success(['id' => $id, 'enabled' => $new_state]);
	}

	/**
	 * Bulk action: delete, enable, or disable selected redirects.
	 */
	public function ajax_bulk(): void
	{
		check_ajax_referer('crawlwp_redirects_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'mihdan-index-now')]);
		}

		$action = sanitize_key($_POST['bulk_action'] ?? '');
		$ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
		$ids    = array_filter($ids);

		if (empty($ids) || !in_array($action, ['delete', 'enable', 'disable'], true)) {
			wp_send_json_error(['message' => __('Invalid bulk action.', 'mihdan-index-now')]);
		}

		$count = 0;
		foreach ($ids as $id) {
			if ($action === 'delete') {
				if ($this->manager->delete($id)) {
					$count++;
				}
			} elseif ($action === 'enable') {
				if ($this->manager->update($id, ['enabled' => 1])) {
					$count++;
				}
			} elseif ($action === 'disable') {
				if ($this->manager->update($id, ['enabled' => 0])) {
					$count++;
				}
			}
		}

		/* translators: %d: number of redirects affected. */
		wp_send_json_success(['message' => sprintf(__('%d redirect(s) updated.', 'mihdan-index-now'), $count), 'count' => $count]);
	}

	// -------------------------------------------------------------------------
	// HTML rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full Redirects manager UI (toolbar + table shell + modal).
	 *
	 * @return string HTML.
	 */
	private function render_ui(): string
	{
		ob_start();
		?>
		<div class="cwp-redirect-wrap" id="cwp-redirect-wrap">

			<?php echo $this->render_notices(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_notices(). ?>

			<?php /* ---- Toolbar ---- */ ?>
			<div class="cwp-redirect-toolbar">
				<button type="button" class="cwp-redirect-btn" id="cwp-redirect-add-btn">
					<?php esc_html_e('Add Redirect', 'mihdan-index-now'); ?>
				</button>

				<div class="cwp-redirect-bulk-wrap">
					<select id="cwp-redirect-bulk-action" class="cwp-redirect-select">
						<option value=""><?php esc_html_e('Bulk actions', 'mihdan-index-now'); ?></option>
						<option value="enable"><?php esc_html_e('Enable', 'mihdan-index-now'); ?></option>
						<option value="disable"><?php esc_html_e('Disable', 'mihdan-index-now'); ?></option>
						<option value="delete"><?php esc_html_e('Delete', 'mihdan-index-now'); ?></option>
					</select>
					<button type="button" class="cwp-redirect-btn cwp-redirect-btn--outline" id="cwp-redirect-bulk-apply">
						<?php esc_html_e('Apply', 'mihdan-index-now'); ?>
					</button>
				</div>

				<select id="cwp-redirect-type-filter" class="cwp-redirect-select">
					<option value=""><?php esc_html_e('All redirect types', 'mihdan-index-now'); ?></option>
					<option value="301">301</option>
					<option value="302">302</option>
					<option value="307">307</option>
					<option value="410">410</option>
					<option value="451">451</option>
				</select>

				<div class="cwp-redirect-toolbar__right">
					<input type="search" id="cwp-redirect-search" class="cwp-redirect-search"
					       placeholder="<?php esc_attr_e('Search…', 'mihdan-index-now'); ?>" />
				</div>
			</div>

			<?php /* ---- Table ---- */ ?>
			<table class="cwp-redirect-table" id="cwp-redirect-table">
				<thead>
					<tr>
						<th class="cwp-redirect-col-cb"><input type="checkbox" id="cwp-redirect-check-all" /></th>
						<th class="cwp-redirect-col-type"><?php esc_html_e('Type', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-from"><?php esc_html_e('From URL', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-to"><?php esc_html_e('To URL', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-note"><?php esc_html_e('Note', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-hits"><?php esc_html_e('Hits', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-enable"><?php esc_html_e('Enable', 'mihdan-index-now'); ?></th>
						<th class="cwp-redirect-col-actions"><?php esc_html_e('Actions', 'mihdan-index-now'); ?></th>
					</tr>
				</thead>
				<tbody id="cwp-redirect-tbody">
					<tr class="cwp-redirect-loading-row">
						<td colspan="8"><?php esc_html_e('Loading…', 'mihdan-index-now'); ?></td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<th class="cwp-redirect-col-cb"><input type="checkbox" /></th>
						<th><?php esc_html_e('Type', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('From URL', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('To URL', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('Note', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('Hits', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('Enable', 'mihdan-index-now'); ?></th>
						<th><?php esc_html_e('Actions', 'mihdan-index-now'); ?></th>
					</tr>
				</tfoot>
			</table>

			<?php /* ---- Pagination ---- */ ?>
			<div class="cwp-redirect-pagination" id="cwp-redirect-pagination">
				<label>
					<select id="cwp-redirect-per-page" class="cwp-redirect-select cwp-redirect-select--sm">
						<option value="20">20</option>
						<option value="50">50</option>
						<option value="100">100</option>
					</select>
					<?php esc_html_e('items per page', 'mihdan-index-now'); ?>
				</label>
				<span id="cwp-redirect-total-info"></span>
				<span class="cwp-redirect-page-links" id="cwp-redirect-page-links"></span>
			</div>

			<?php /* ---- Modal backdrop ---- */ ?>
			<div id="cwp-redirect-backdrop" class="cwp-redirect-backdrop" hidden></div>

			<?php /* ---- Add / Edit modal ---- */ ?>
			<div id="cwp-redirect-modal" class="cwp-redirect-modal" hidden role="dialog" aria-modal="true"
			     aria-labelledby="cwp-redirect-modal-title">
				<div class="cwp-redirect-modal__header">
					<h2 id="cwp-redirect-modal-title" class="cwp-redirect-modal__title">
						<?php esc_html_e('Add Redirect', 'mihdan-index-now'); ?>
					</h2>
					<button type="button" class="cwp-redirect-modal__close" id="cwp-redirect-modal-close"
					        aria-label="<?php esc_attr_e('Close', 'mihdan-index-now'); ?>">&#x2715;</button>
				</div>

				<div class="cwp-redirect-modal__body">
					<input type="hidden" id="cwp-redirect-id" value="" />

					<div class="cwp-redirect-field">
						<label for="cwp-redirect-type"><?php esc_html_e('Type', 'mihdan-index-now'); ?></label>
						<select id="cwp-redirect-type" class="cwp-redirect-select cwp-redirect-select--full">
							<option value="301"><?php esc_html_e('301 Moved Permanently', 'mihdan-index-now'); ?></option>
							<option value="302"><?php esc_html_e('302 Found (Temporary)', 'mihdan-index-now'); ?></option>
							<option value="307"><?php esc_html_e('307 Temporary Redirect', 'mihdan-index-now'); ?></option>
							<option value="410"><?php esc_html_e('410 Content Deleted', 'mihdan-index-now'); ?></option>
							<option value="451"><?php esc_html_e('451 Content Unavailable for Legal Reasons', 'mihdan-index-now'); ?></option>
						</select>
					</div>

					<div class="cwp-redirect-field">
						<label for="cwp-redirect-from-url"><?php esc_html_e('From URL', 'mihdan-index-now'); ?></label>
						<div class="cwp-redirect-from-row">
							<select id="cwp-redirect-match-type" class="cwp-redirect-select">
								<option value="exact"><?php esc_html_e('Exact Match', 'mihdan-index-now'); ?></option>
								<option value="contains"><?php esc_html_e('Contains', 'mihdan-index-now'); ?></option>
								<option value="starts_with"><?php esc_html_e('Starts With', 'mihdan-index-now'); ?></option>
								<option value="ends_with"><?php esc_html_e('Ends With', 'mihdan-index-now'); ?></option>
								<option value="regex"><?php esc_html_e('Regex', 'mihdan-index-now'); ?></option>
							</select>
							<input type="text" id="cwp-redirect-from-url" class="cwp-redirect-input"
							       placeholder="<?php esc_attr_e('/old-page/', 'mihdan-index-now'); ?>" />
						</div>
					</div>

					<div class="cwp-redirect-field" id="cwp-redirect-to-field">
						<label for="cwp-redirect-to-url"><?php esc_html_e('To URL', 'mihdan-index-now'); ?></label>
						<input type="text" id="cwp-redirect-to-url" class="cwp-redirect-input"
						       placeholder="<?php esc_attr_e('/new-page/', 'mihdan-index-now'); ?>" />
					</div>

					<div class="cwp-redirect-field">
						<label for="cwp-redirect-note"><?php esc_html_e('Note', 'mihdan-index-now'); ?></label>
						<input type="text" id="cwp-redirect-note" class="cwp-redirect-input"
						       placeholder="<?php esc_attr_e('Optional note', 'mihdan-index-now'); ?>" />
					</div>

					<div class="cwp-redirect-field">
							<label for="cwp-redirect-priority"><?php esc_html_e('Priority', 'mihdan-index-now'); ?></label>
							<input type="number" id="cwp-redirect-priority" class="cwp-redirect-input"
							       min="0" max="999" step="1"
							       value="<?php echo esc_attr((string) RedirectsManager::DEFAULT_PRIORITY); ?>" />
							<p class="description"><?php esc_html_e('Lower numbers are matched first. Default is 10.', 'mihdan-index-now'); ?></p>
						</div>

						<div class="cwp-redirect-field cwp-redirect-field--toggle">
							<label class="cwp-redirect-toggle-label" for="cwp-redirect-allow-external">
								<input type="checkbox" id="cwp-redirect-allow-external" />
								<span class="cwp-redirect-toggle-switch"></span>
								<?php esc_html_e('Allow redirecting to an external site', 'mihdan-index-now'); ?>
							</label>
							<p class="description"><?php esc_html_e('Required when the To URL points at another domain. Leave off to keep visitors on this site.', 'mihdan-index-now'); ?></p>
						</div>

					<div class="cwp-redirect-field cwp-redirect-field--toggle">
						<label class="cwp-redirect-toggle-label" for="cwp-redirect-ignore-qs">
							<input type="checkbox" id="cwp-redirect-ignore-qs" checked />
							<span class="cwp-redirect-toggle-switch"></span>
							<?php esc_html_e('Ignore query string parameters', 'mihdan-index-now'); ?>
						</label>
					</div>

					<div class="cwp-redirect-field cwp-redirect-field--toggle">
						<label class="cwp-redirect-toggle-label" for="cwp-redirect-enabled">
							<input type="checkbox" id="cwp-redirect-enabled" checked />
							<span class="cwp-redirect-toggle-switch"></span>
							<?php esc_html_e('Enable', 'mihdan-index-now'); ?>
						</label>
					</div>
				</div>

				<div class="cwp-redirect-modal__footer">
					<button type="button" class="cwp-redirect-btn" id="cwp-redirect-modal-save">
						<?php esc_html_e('Add Redirect', 'mihdan-index-now'); ?>
					</button>
				</div>
			</div>

		</div><!-- .cwp-redirect-wrap -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Render admin notices shown above the redirects table.
	 *
	 * @return string Escaped HTML.
	 */
	private function render_notices(): string
	{
		$html = '';

		$active = $this->manager->get_count(['status' => 'active']);

		if ($active > RedirectsManager::LARGE_RULESET) {
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(
				/* translators: %s: number of active redirects. */
				__('This site has %s active redirects. Every enabled rule is loaded on the frontend, so very large rule sets increase memory use — consider replacing repetitive rules with a single regex or pattern rule.', 'mihdan-index-now'),
				number_format_i18n($active)
			)) . '</p></div>';
		}

		$errors = $this->manager->get_runtime_errors();

		if (!empty($errors)) {
			$html .= '<div class="notice notice-error inline"><p>'
				. esc_html__('Some redirects were disabled automatically because they could not be executed safely:', 'mihdan-index-now')
				. '</p><ul>';

			foreach ($errors as $rule_id => $error) {
				$html .= '<li>' . esc_html(sprintf(
					/* translators: 1: rule ID, 2: pattern, 3: reason. */
					__('Rule #%1$d (%2$s): %3$s', 'mihdan-index-now'),
					(int) $rule_id,
					(string) ($error['pattern'] ?? ''),
					(string) ($error['reason'] ?? '')
				)) . '</li>';
			}

			$html .= '</ul></div>';
		}

		return $html;
	}

	/**
	 * Render table row HTML for an array of redirect objects.
	 *
	 * @param object[] $redirects Array of DB row objects.
	 * @return string HTML <tr> elements.
	 */
	private function render_table_rows(array $redirects): string
	{
		if (empty($redirects)) {
			return '<tr class="cwp-redirect-no-items"><td colspan="8">' . esc_html__('No redirects found.', 'mihdan-index-now') . '</td></tr>';
		}

		$type_labels = [
			301 => '301',
			302 => '302',
			307 => '307',
			410 => '410',
			451 => '451',
		];

		$match_labels = [
			'exact'       => __('Exact Match', 'mihdan-index-now'),
			'contains'    => __('Contains', 'mihdan-index-now'),
			'starts_with' => __('Starts With', 'mihdan-index-now'),
			'ends_with'   => __('Ends With', 'mihdan-index-now'),
			'regex'       => __('Regex', 'mihdan-index-now'),
		];

		$html = '';
		foreach ($redirects as $r) {
			$id          = (int) $r->id;
			$type        = (int) $r->redirect_type;
			$type_label  = $type_labels[$type] ?? $type;
			$match_label = $match_labels[$r->match_type] ?? esc_html($r->match_type);
			$enabled     = (bool) $r->enabled;

			$priority = (int) ($r->priority ?? RedirectsManager::DEFAULT_PRIORITY);

			// Encode all row data as a JSON data attribute for JS to read.
			$row_data = esc_attr(wp_json_encode([
				'id'                  => $id,
				'redirect_type'       => $type,
				'match_type'          => $r->match_type,
				'from_url'            => $r->from_url,
				'to_url'              => $r->to_url,
				'note'                => $r->note,
				'ignore_query_string' => (int) $r->ignore_query_string,
				'enabled'             => (int) $r->enabled,
				'priority'            => $priority,
				'allow_external'      => (int) ($r->allow_external ?? 0),
			]));

			$html .= '<tr data-id="' . $id . '" data-redirect=\'' . $row_data . '\'>';

			// Checkbox.
			$html .= '<td><input type="checkbox" class="cwp-redirect-row-cb" value="' . $id . '" /></td>';

			// Type + match type + priority.
			$html .= '<td><span class="cwp-redirect-badge cwp-redirect-badge--' . $type . '">' . esc_html($type_label) . '</span>'
				. '<br><small>' . esc_html($match_label) . '</small>';

			$html .= '<br><small class="cwp-redirect-muted">' . esc_html(sprintf(
					/* translators: %d: rule priority. */
					__('Priority: %d', 'mihdan-index-now'),
					$priority
				)) . '</small>';

			$html .= '</td>';

			// From URL.
			$html .= '<td class="cwp-redirect-col-url"><span class="cwp-redirect-url">' . esc_html($r->from_url) . '</span></td>';

			// To URL.
			if ($type === 410 || $type === 451) {
				$html .= '<td class="cwp-redirect-muted">—</td>';
			} else {
				$html .= '<td class="cwp-redirect-col-url"><span class="cwp-redirect-url">' . esc_html($r->to_url) . '</span></td>';
			}

			// Note.
			$html .= '<td class="cwp-redirect-muted">' . esc_html($r->note) . '</td>';

			// Hits.
			$html .= '<td class="cwp-redirect-muted">' . number_format_i18n((int) $r->hits) . '</td>';

			// Enable toggle.
			$checked = $enabled ? 'checked' : '';
			$html .= '<td><label class="cwp-redirect-toggle-label cwp-redirect-toggle-label--table">'
				. '<input type="checkbox" class="cwp-redirect-row-toggle" data-id="' . $id . '" ' . $checked . ' />'
				. '<span class="cwp-redirect-toggle-switch"></span>'
				. '</label></td>';

			// Actions.
			$html .= '<td class="cwp-redirect-actions">'
				. '<button type="button" class="cwp-redirect-action-btn cwp-redirect-edit-btn" data-id="' . $id . '" title="' . esc_attr__('Edit', 'mihdan-index-now') . '">'
				. '<span class="dashicons dashicons-edit"></span>'
				. '</button>'
				. '<button type="button" class="cwp-redirect-action-btn cwp-redirect-delete-btn" data-id="' . $id . '" title="' . esc_attr__('Delete', 'mihdan-index-now') . '">'
				. '<span class="dashicons dashicons-trash"></span>'
				. '</button>'
				. '</td>';

			$html .= '</tr>';
		}

		return $html;
	}
}
