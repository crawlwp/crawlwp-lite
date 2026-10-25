<?php

namespace Mihdan\IndexNow\SEOCore\CLI;

use Mihdan\IndexNow\Indexing;
use Mihdan\IndexNow\IndexNowAbstract;
use Mihdan\IndexNow\Main;
use Mihdan\IndexNow\Providers\Bing\BingIndexNow;
use Mihdan\IndexNow\Providers\IndexNow\IndexNow;
use Mihdan\IndexNow\Providers\Naver\NaverIndexNow;
use Mihdan\IndexNow\Providers\Seznam\SeznamIndexNow;
use Mihdan\IndexNow\Providers\Yandex\YandexIndexNow;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\WebmasterAbstract;
use WP_CLI;

/**
 * WP-CLI commands: `wp crawlwp submit|meta|redirect|import`.
 */
class Commands
{
	/** URLs per IndexNow request (the protocol allows 10,000). */
	private const INDEXNOW_BATCH = 1000;

	/** @var Main */
	private $main;

	public function __construct(Main $main)
	{
		$this->main = $main;
	}

	/**
	 * Registered on `crawlwp/init`, once the plugin's services exist.
	 */
	public static function register(Main $main): void
	{
		WP_CLI::add_command('crawlwp submit', [new self($main), 'submit']);
		WP_CLI::add_command('crawlwp meta', MetaCommand::class);
		WP_CLI::add_command('crawlwp redirect', RedirectCommand::class);
		WP_CLI::add_command('crawlwp import', ImportCommand::class);
	}

	/**
	 * Submit URLs to search engines for indexing.
	 *
	 * Uses the IndexNow search engine chosen in Indexing > IndexNow, or a
	 * webmaster API with --engine. Posts go through the same checks as
	 * automatic submissions (published, enabled post type, not noindex).
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : URLs on this site to submit.
	 *
	 * [--post=<ids>]
	 * : Comma-separated post IDs to submit.
	 *
	 * [--post_type=<types>]
	 * : Submit every published post of these comma-separated post types.
	 *
	 * [--engine=<engine>]
	 * : Where to send the URLs.
	 * ---
	 * default: indexnow
	 * options:
	 *   - indexnow
	 *   - google
	 *   - bing
	 *   - yandex
	 * ---
	 *
	 * [--removed]
	 * : Tell the engine the URLs were removed (IndexNow and Google only).
	 *
	 * [--dry-run]
	 * : List the URLs that would be submitted without sending them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Submit two pages with IndexNow
	 *     $ wp crawlwp submit https://example.com/pricing/ https://example.com/about/
	 *
	 *     # Resubmit every published page after a migration
	 *     $ wp crawlwp submit --post_type=page
	 *
	 *     # Ask Google to recrawl a post
	 *     $ wp crawlwp submit --post=42 --engine=google
	 *
	 * @param string[]             $args       URLs.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function submit(array $args, array $assoc_args): void
	{
		$engine  = (string) ($assoc_args['engine'] ?? 'indexnow');
		$removed = ! empty($assoc_args['removed']);
		$urls    = [];
		$asked   = $args !== [] || isset($assoc_args['post']) || isset($assoc_args['post_type']);

		foreach ($args as $url) {
			$url = esc_url_raw(trim($url));

			if (! self::is_site_url($url)) {
				WP_CLI::warning(sprintf('Skipped %s: not a URL on this site.', $url ?: '(empty)'));
				continue;
			}

			$urls[$url] = 0;
		}

		$ids = isset($assoc_args['post']) ? wp_parse_id_list((string) $assoc_args['post']) : [];

		if (isset($assoc_args['post_type'])) {
			$ids = array_merge($ids, get_posts([
				'post_type'      => array_map('trim', explode(',', (string) $assoc_args['post_type'])),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]));
		}

		foreach (array_unique($ids) as $id) {
			$reason = Indexing::get_post_skip_reason($id);

			if ($reason !== '' && ! $removed) {
				WP_CLI::warning(sprintf('Skipped post %d: %s', $id, $reason));
				continue;
			}

			$url = $removed ? (string) get_permalink($id) : Utils::normalized_get_permalink($id);

			if ($url === '') {
				WP_CLI::warning(sprintf('Skipped post %d: post not found.', $id));
				continue;
			}

			$urls[$url] = $id;
		}

		if ($urls === []) {
			WP_CLI::error($asked ? 'Nothing left to submit.' : 'Nothing to submit. Pass URLs, --post or --post_type.');
		}

		if (! empty($assoc_args['dry-run'])) {
			foreach (array_keys($urls) as $url) {
				WP_CLI::line($url);
			}

			WP_CLI::success(sprintf('%d URL(s) would be sent to %s.', count($urls), $engine));

			return;
		}

		$result = $engine === 'indexnow'
			? $this->submit_indexnow(array_keys($urls))
			: $this->submit_webmaster($engine, array_keys($urls), $removed);

		/* Same "last submitted" bookkeeping as automatic submissions (webmaster APIs do their own). */
		if (! $removed && empty($result['logged'])) {
			foreach ($result['sent'] as $url) {
				if (! empty($urls[$url])) {
					do_action('crawlwp/index_pinged', 'post', $urls[$url]);
				}
			}
		}

		foreach ($result['failed'] as $url => $message) {
			WP_CLI::warning(sprintf('%s: %s', $url, $message));
		}

		if ($result['sent'] === []) {
			WP_CLI::error('Nothing was submitted.');
		}

		WP_CLI::success(sprintf('Submitted %d of %d URL(s).', count($result['sent']), count($urls)));
	}

	/**
	 * @param string[] $urls
	 *
	 * @return array{sent: string[], failed: array<string, string>}
	 */
	private function submit_indexnow(array $urls): array
	{
		if (Indexing::get_option('enable', 'index_now') !== 'on') {
			WP_CLI::error('IndexNow is turned off in Indexing > IndexNow. Turn it on, or use --engine.');
		}

		$provider = $this->indexnow_provider();
		$sent     = [];
		$failed   = [];
		$batches  = array_chunk($urls, self::INDEXNOW_BATCH);
		$progress = WP_CLI\Utils\make_progress_bar(sprintf('Submitting to %s', $provider->get_name()), count($batches));

		foreach ($batches as $batch) {
			/* submit_urls() (newer versions) also explains a failure. */
			$answer = method_exists($provider, 'submit_urls')
				? $provider->submit_urls($batch)
				: ['status' => $provider->push($batch) ? 200 : 0, 'message' => ''];

			if (Utils::is_response_code_success((int) $answer['status'])) {
				$sent = array_merge($sent, $batch);
			} else {
				foreach ($batch as $url) {
					$failed[$url] = (string) $answer['message'] ?: 'Rejected by the search engine. See Indexing > Logs.';
				}
			}

			$progress->tick();
		}

		$progress->finish();

		return ['sent' => $sent, 'failed' => $failed];
	}

	/**
	 * Google, Bing or Yandex webmaster API, one URL per request. Each request is
	 * logged, and a daily limit pauses the rest, as in Settings > Submit URLs.
	 *
	 * @param string[] $urls
	 *
	 * @return array{sent: string[], failed: array<string, string>, logged: bool}
	 */
	private function submit_webmaster(string $engine, array $urls, bool $removed): array
	{
		$globals = ['google' => 'CRAWLWP_GOOGLE_WEBMASTER', 'bing' => 'CRAWLWP_BING_WEBMASTER', 'yandex' => 'CRAWLWP_YANDEX_WEBMASTER'];
		$service = $GLOBALS[$globals[$engine]] ?? null;

		/* WebmasterAbstract::submit_url() arrives with Settings > Submit URLs. */
		if (! $service instanceof WebmasterAbstract || ! method_exists($service, 'submit_url')) {
			WP_CLI::error(sprintf('Submitting to %s from the command line needs a newer version of CrawlWP.', ucfirst($engine)));
		}

		if ($removed && $engine !== 'google') {
			WP_CLI::error(sprintf('%s does not accept removed URLs. Use IndexNow or Google.', ucfirst($engine)));
		}

		$sent     = [];
		$failed   = [];
		$progress = WP_CLI\Utils\make_progress_bar(sprintf('Submitting to %s', ucfirst($engine)), count($urls));

		foreach ($urls as $url) {
			$answer = $service->submit_url($url, $removed);

			if (Utils::is_response_code_success((int) ($answer['status'] ?? 0))) {
				$sent[] = $url;
			} else {
				$failed[$url] = (string) ($answer['message'] ?? '') ?: 'Not submitted.';
			}

			$progress->tick();
		}

		$progress->finish();

		return ['sent' => $sent, 'failed' => $failed, 'logged' => true];
	}

	/**
	 * The IndexNow engine chosen in the settings.
	 */
	private function indexnow_provider(): IndexNowAbstract
	{
		$engine = (string) Indexing::get_option('search_engine', 'index_now');

		foreach ([IndexNow::class, YandexIndexNow::class, BingIndexNow::class, SeznamIndexNow::class, NaverIndexNow::class] as $class) {
			$provider = $this->main->make($class);

			if ($provider instanceof IndexNowAbstract && $provider->get_slug() === $engine) {
				return $provider;
			}
		}

		WP_CLI::error(sprintf('Unknown IndexNow search engine "%s". Choose one in Indexing > IndexNow.', $engine));
	}

	private static function is_site_url(string $url): bool
	{
		$host = wp_parse_url($url, PHP_URL_HOST);
		$home = wp_parse_url(Utils::normalized_home_url(), PHP_URL_HOST);

		return is_string($host) && is_string($home)
			&& preg_replace('/^www\./', '', strtolower($host)) === preg_replace('/^www\./', '', strtolower($home));
	}
}
