<?php

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Container;
use Mihdan\IndexNow\IndexNowAbstract;
use Mihdan\IndexNow\Providers\Bing\BingIndexNow;
use Mihdan\IndexNow\Providers\Bing\BingWebmaster;
use Mihdan\IndexNow\Providers\Google\GoogleWebmaster;
use Mihdan\IndexNow\Providers\IndexNow\IndexNow;
use Mihdan\IndexNow\Providers\Naver\NaverIndexNow;
use Mihdan\IndexNow\Providers\Seznam\SeznamIndexNow;
use Mihdan\IndexNow\Providers\Yandex\YandexIndexNow;
use Mihdan\IndexNow\Providers\Yandex\YandexWebmaster;
use Mihdan\IndexNow\Utils;

/**
 * Settings > Submit URLs: send URLs to search engines by hand.
 */
class SubmitUrls
{
	const MENU = 'submit_urls';

	/** URLs a user can submit at once. */
	const MAX_URLS = 100;

	/** URLs sent per AJAX request, so a request never runs into a PHP time limit. */
	const CHUNK = 10;

	/** @var Container */
	private $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	public function setup_hooks(): void
	{
		add_action('crawlwp_setup_fields_before_log', [$this, 'add_menu']);
		add_action('crawlwp_setup_fields', [$this, 'add_page']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('wp_ajax_crawlwp_submit_urls', [$this, 'ajax_submit']);
	}

	public function add_menu(WPOSA $wposa): void
	{
		$wposa->add_header_menu(['id' => self::MENU, 'title' => __('Submit URLs', 'mihdan-index-now')]);
	}

	private static function is_page(): bool
	{
		return is_admin() && sanitize_key($_GET['page'] ?? '') === CRAWLWP_SLUG // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& sanitize_key($_GET['wposa-menu'] ?? '') === Utils::get_plugin_prefix() . '_' . self::MENU; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function add_page(WPOSA $wposa): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_' . self::MENU) return;

		$wposa->enable_blank_mode();

		// The settings page adds its sidebar cards after this hook runs.
		add_action('admin_head', function () use ($wposa) {
			$wposa->remove_all_sidebar_cards();
		});
		$wposa->add_section(['header_menu_id' => self::MENU, 'id' => self::MENU, 'title' => __('Submit URLs', 'mihdan-index-now')]);
		$wposa->add_field(self::MENU, ['id' => 'form', 'type' => 'html', 'name' => 'form', 'desc' => function () {
			return $this->render();
		}]);
	}

	/**
	 * The IndexNow engine submissions go to, if IndexNow is on.
	 */
	private function indexnow(): ?IndexNowAbstract
	{
		foreach ([IndexNow::class, BingIndexNow::class, YandexIndexNow::class, SeznamIndexNow::class, NaverIndexNow::class] as $class) {
			$engine = $this->container->make($class);
			if ($engine instanceof IndexNowAbstract && $engine->is_current()) return $engine;
		}

		return null;
	}

	/**
	 * Services URLs can be sent to: id => [label, connected, note, connect link].
	 *
	 * @return array<string, array{label: string, connected: bool, note: string, link: string, removed: bool}>
	 */
	private function services(bool $with_quota = true): array
	{
		$indexing = add_query_arg('wposa-menu', Utils::get_plugin_prefix() . '_index_settings', CRAWLWP_SETTINGS_URL);
		$google   = $this->container->make(GoogleWebmaster::class);
		$bing     = $this->container->make(BingWebmaster::class);
		$yandex   = $this->container->make(YandexWebmaster::class);
		$bing_on  = $bing->is_connected();
		$left     = $with_quota && $bing_on ? $bing->remaining_today() : null;

		return [
			'indexnow' => [
				'label'     => __('IndexNow', 'mihdan-index-now'),
				'connected' => $this->indexnow() !== null,
				'note'      => __('Shared with Bing, Yandex, Seznam, Naver and Yep.', 'mihdan-index-now'),
				'link'      => $indexing,
				'removed'   => true,
			],
			'google'   => [
				'label'     => __('Google Indexing API', 'mihdan-index-now'),
				'connected' => $google->is_connected(),
				/* translators: 1: daily limit, 2: requests sent today */
				'note'      => sprintf(__('Up to %1$s a day. %2$s sent today.', 'mihdan-index-now'), number_format_i18n(GoogleWebmaster::DAILY_LIMIT), number_format_i18n(GoogleWebmaster::sent_today())),
				'link'      => $indexing,
				'removed'   => true,
			],
			'bing'     => [
				'label'     => __('Bing Webmaster API', 'mihdan-index-now'),
				'connected' => $bing_on,
				/* translators: %s: number of URLs */
				'note'      => $left !== null ? sprintf(__('%s left today (from Bing).', 'mihdan-index-now'), number_format_i18n($left)) : '',
				'link'      => $indexing,
				'removed'   => false,
			],
			'yandex'   => [
				'label'     => __('Yandex Webmaster API', 'mihdan-index-now'),
				'connected' => $yandex->is_connected(),
				'note'      => '',
				'link'      => $indexing,
				'removed'   => false,
			],
		];
	}

	private function render(): string
	{
		ob_start();
		?>
		<div class="cwps" data-cwps>
			<div class="cwps-card" data-cwps-form>
				<h2><?php esc_html_e('Submit URLs', 'mihdan-index-now'); ?></h2>
				<p class="cwps-lead"><?php esc_html_e('Tell search engines about pages right away, for example after a big update or for pages CrawlWP didn\'t send automatically.', 'mihdan-index-now'); ?></p>
				<div class="cwps-grid">
					<div>
						<label class="cwps-label" for="cwps-urls"><?php esc_html_e('URLs, one per line', 'mihdan-index-now'); ?></label>
						<textarea id="cwps-urls" rows="8" spellcheck="false" placeholder="<?php echo esc_attr(trailingslashit(home_url()) . 'example-page/'); ?>" data-cwps-urls></textarea>
						<div class="cwps-count" data-cwps-count aria-live="polite"></div>

						<p class="cwps-label"><?php esc_html_e('These pages were', 'mihdan-index-now'); ?></p>
						<label class="cwps-opt"><input type="radio" name="cwps-kind" value="updated" checked><span><b><?php esc_html_e('Added or updated', 'mihdan-index-now'); ?></b><span><?php esc_html_e('Asks search engines to crawl them again.', 'mihdan-index-now'); ?></span></span></label>
						<label class="cwps-opt"><input type="radio" name="cwps-kind" value="removed"><span><b><?php esc_html_e('Removed', 'mihdan-index-now'); ?></b><span><?php esc_html_e('Tells search engines the pages are gone. Use it after deleting pages.', 'mihdan-index-now'); ?></span></span></label>
					</div>
					<div>
						<p class="cwps-label"><?php esc_html_e('Send to', 'mihdan-index-now'); ?></p>
						<?php foreach ($this->services() as $id => $service) : ?>
							<label class="cwps-opt<?php echo $service['connected'] ? '' : ' is-off'; ?>" data-cwps-service="<?php echo esc_attr($id); ?>" data-removed="<?php echo $service['removed'] ? '1' : '0'; ?>">
								<input type="checkbox" value="<?php echo esc_attr($id); ?>" <?php checked($service['connected']); ?> <?php disabled( ! $service['connected']); ?>>
								<span>
									<b><?php echo esc_html($service['label']); ?></b>
									<?php if ( ! $service['connected']) : ?>
										<span><?php esc_html_e('Not set up.', 'mihdan-index-now'); ?> <a href="<?php echo esc_url($service['link']); ?>"><?php esc_html_e('Set it up', 'mihdan-index-now'); ?></a></span>
									<?php else : ?>
										<span data-cwps-note><?php echo esc_html($service['note']); ?></span>
										<span class="cwps-removed-note" style="display:none"><?php esc_html_e('Removed pages reach it through IndexNow.', 'mihdan-index-now'); ?></span>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="cwps-foot">
					<button type="button" class="button button-primary" data-cwps-submit disabled><?php esc_html_e('Submit URLs', 'mihdan-index-now'); ?></button>
					<span><?php esc_html_e('Results are also saved in the Log.', 'mihdan-index-now'); ?></span>
				</div>
			</div>

			<div class="cwps-card" data-cwps-results style="display:none">
				<h2><?php esc_html_e('Results', 'mihdan-index-now'); ?></h2>
				<div class="cwps-sum" data-cwps-sum aria-live="polite"></div>
				<table class="cwps-table"><thead data-cwps-head></thead><tbody data-cwps-rows></tbody></table>
				<div class="cwps-foot">
					<button type="button" class="button" data-cwps-again style="display:none"><?php esc_html_e('Submit more URLs', 'mihdan-index-now'); ?></button>
					<a href="<?php echo esc_url(add_query_arg('wposa-menu', Utils::get_plugin_prefix() . '_log', CRAWLWP_SETTINGS_URL)); ?>"><?php esc_html_e('Open the Log', 'mihdan-index-now'); ?></a>
				</div>
			</div>
		</div>
		<?php
		return (string)ob_get_clean();
	}

	public function assets(): void
	{
		if ( ! self::is_page()) return;

		$url = CRAWLWP_PLUGIN_URL . 'src/Views/assets/';
		$ver = defined('CRAWLWP_VERSION') ? CRAWLWP_VERSION : false;

		wp_enqueue_style('crawlwp-submit-urls', $url . 'submit-urls.css', [], $ver);
		wp_enqueue_script('crawlwp-submit-urls', $url . 'submit-urls.js', ['jquery'], $ver, true);
		wp_localize_script('crawlwp-submit-urls', 'crawlwpSubmitUrls', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('crawlwp_submit_urls'),
			'host'    => strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST)),
			'max'     => self::MAX_URLS,
			'chunk'   => self::CHUNK,
			'labels'  => ['indexnow' => 'IndexNow', 'google' => 'Google', 'bing' => 'Bing', 'yandex' => 'Yandex'],
			'i18n'    => [
				/* translators: %d: number of URLs */
				'count'      => __('%d URLs', 'mihdan-index-now'),
				'countOne'   => __('1 URL', 'mihdan-index-now'),
				/* translators: %d: maximum number of URLs */
				'max'        => sprintf(__('up to %d at a time', 'mihdan-index-now'), self::MAX_URLS),
				/* translators: %d: number of URLs */
				'tooMany'    => sprintf(__('Too many: send up to %d at a time.', 'mihdan-index-now'), self::MAX_URLS),
				/* translators: %d: number of lines */
				'foreign'    => __('%d not on this site, will be skipped', 'mihdan-index-now'),
				/* translators: %d: number of lines */
				'invalid'    => __('%d not a web address, will be skipped', 'mihdan-index-now'),
				/* translators: %d: number of URLs */
				'submit'     => __('Submit %d URLs', 'mihdan-index-now'),
				'submitOne'  => __('Submit 1 URL', 'mihdan-index-now'),
				'submitNone' => __('Submit URLs', 'mihdan-index-now'),
				'url'        => __('URL', 'mihdan-index-now'),
				'sent'       => __('Sent', 'mihdan-index-now'),
				'notSent'    => __('Not sent', 'mihdan-index-now'),
				'skipped'    => __('Skipped', 'mihdan-index-now'),
				'waiting'    => __('Waiting…', 'mihdan-index-now'),
				'notOnSite'  => __('Not on this site. Search engines only accept URLs from the site that sends them.', 'mihdan-index-now'),
				'notUrl'     => __('Not a web address.', 'mihdan-index-now'),
				'failed'     => __('The request failed. Reload the page and try again.', 'mihdan-index-now'),
				/* translators: 1: URLs done, 2: total URLs */
				'progress'   => __('Sending %1$d of %2$d…', 'mihdan-index-now'),
				/* translators: %d: number of URLs */
				'sumSent'    => __('%d sent', 'mihdan-index-now'),
				/* translators: %d: number of URLs */
				'sumProblem' => __('%d with a problem', 'mihdan-index-now'),
				/* translators: %d: number of URLs */
				'sumSkipped' => __('%d skipped', 'mihdan-index-now'),
				'noService'  => __('Choose at least one service.', 'mihdan-index-now'),
			],
		]);
	}

	/**
	 * Whether a URL is a web address on this site. Search engines reject others.
	 */
	public static function is_site_url(string $url): bool
	{
		$parts = wp_parse_url($url);

		if ( ! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
			return false;
		}

		return strtolower($parts['host']) === strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));
	}

	public function ajax_submit(): void
	{
		check_ajax_referer('crawlwp_submit_urls', 'nonce');

		if ( ! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('You are not allowed to do this.', 'mihdan-index-now')], 403);
		}

		$urls     = array_slice(array_values(array_unique(array_map('esc_url_raw', array_map('trim', (array)wp_unslash($_POST['urls'] ?? []))))), 0, self::CHUNK); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw.
		$removed  = ! empty($_POST['removed']);
		$wanted   = array_map('sanitize_key', (array)wp_unslash($_POST['services'] ?? []));
		$services = $this->services(false);
		$results  = [];
		$valid    = [];

		foreach ($urls as $url) {
			if ( ! self::is_site_url($url)) {
				$results[$url] = ['skipped' => __('Not on this site. Search engines only accept URLs from the site that sends them.', 'mihdan-index-now')];
				continue;
			}

			$valid[]       = $url;
			$results[$url] = ['engines' => []];
		}

		foreach ($wanted as $id) {

			if (empty($services[$id]['connected']) || $valid === []) continue;

			if ($removed && ! $services[$id]['removed']) {
				continue;
			}

			if ($id === 'indexnow') {
				$engine = $this->indexnow();
				if ($engine === null) continue;
				$result = $engine->submit_urls($valid);
				foreach ($valid as $url) $results[$url]['engines'][$id] = self::outcome($result);
				continue;
			}

			$provider = $this->container->make(['google' => GoogleWebmaster::class, 'bing' => BingWebmaster::class, 'yandex' => YandexWebmaster::class][$id]);

			foreach ($valid as $url) {
				$results[$url]['engines'][$id] = self::outcome($provider->submit_url($url, $removed));
			}
		}

		wp_send_json_success(['results' => $results]);
	}

	/**
	 * @param array{status: int, message: string} $result
	 */
	private static function outcome(array $result): array
	{
		return ['ok' => Utils::is_response_code_success($result['status']), 'message' => $result['message']];
	}
}
