<?php
/**
 * Settings class.
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\Views;

use Mihdan\IndexNow\Utils;

class UpsellAdminPages
{
	/** User meta: the admin hid the dashboard upsell widget. */
	public const HIDE_DASHBOARD_META = '_crawlwp_hide_dashboard_upsell';

	/**
	 * Analytics upsell page (Premium feature).
	 *
	 * @var WPOSA $analytics_wposa
	 */
	public $analytics_wposa;

	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa
	 */
	public $wposa;
	/**
	 * WP_OSA instance.
	 *
	 * @var WPOSA $wposa2
	 */
	public $wposa2;

	public function __construct()
	{
		$this->wposa = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_SEO_INDEX_SLUG
		);

		$this->wposa->enable_blank_mode()->setup_hooks();

		$this->wposa2 = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_SEO_STAT_SLUG
		);

		$this->wposa2->enable_blank_mode()->setup_hooks();

		add_action('init', [$this, 'setup_fields'], 110);
		add_action('wp_dashboard_setup', [$this, 'dashboard_widget']);
		add_action('admin_init', [$this, 'hide_dashboard_widget']);

		$this->analytics_wposa = new WPOSA(
			Utils::get_plugin_name(),
			Utils::get_plugin_version(),
			Utils::get_plugin_slug(),
			'crawlwp_upsell',
			CRAWLWP_PRO_ANALYTICS_SLUG
		);

		$this->analytics_wposa->enable_blank_mode()->setup_hooks();
	}


	/**
	 * A blurred preview of a Premium screen with an upgrade card on top.
	 *
	 * @param array $o image (file in assets/images), blur (true when the image isn't blurred already),
	 *                 title, text, points (string, or [string, true] for a "New" badge; may hold <b>),
	 *                 note (optional) and campaign (utm_campaign).
	 */
	public static function locked_preview(array $o): string
	{
		$points = '';

		foreach ($o['points'] as $point) {
			$is_new  = is_array($point) && ! empty($point[1]);
			$points .= '<li>' . wp_kses(is_array($point) ? $point[0] : $point, ['b' => []]);
			$points .= $is_new ? ' <span class="crawlwp-locked__new">' . esc_html__('New', 'mihdan-index-now') . '</span>' : '';
			$points .= '</li>';
		}

		return sprintf(
			'<div class="crawlwp-locked"><img class="crawlwp-locked__preview%1$s" src="%2$s" alt="" aria-hidden="true"><div class="crawlwp-locked__card"><span class="crawlwp-locked__tag">%3$s</span><h2>%4$s</h2><p>%5$s</p><ul>%6$s</ul><a class="crawlwp-locked__button" target="_blank" href="%7$s">%8$s</a>%9$s</div></div>',
			empty($o['blur']) ? '' : ' is-blurred',
			esc_url(Utils::get_plugin_asset_url('images/' . $o['image'])),
			esc_html__('CrawlWP Premium', 'mihdan-index-now'),
			esc_html($o['title']),
			esc_html($o['text']),
			$points,
			esc_url('https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=' . $o['campaign']),
			esc_html__('Upgrade to CrawlWP Premium', 'mihdan-index-now'),
			empty($o['note']) ? '' : '<span class="crawlwp-locked__note">' . esc_html($o['note']) . '</span>'
		);
	}

	/**
	 * Lite's "CrawlWP Stats" dashboard widget: a blurred preview of Premium's, for admins, until they hide it.
	 */
	public function dashboard_widget(): void
	{
		if (! current_user_can('manage_options') || get_user_meta(get_current_user_id(), self::HIDE_DASHBOARD_META, true)) {
			return;
		}

		wp_add_dashboard_widget('crawlwp_dashboard_upsell', esc_html__('CrawlWP Stats', 'mihdan-index-now'), [$this, 'render_dashboard_widget']);
	}

	public function render_dashboard_widget(): void
	{
		$hide = wp_nonce_url(add_query_arg('crawlwp_hide_dashboard_upsell', '1', admin_url('index.php')), 'crawlwp_hide_dashboard_upsell');
		?>
		<style>
			#crawlwp_dashboard_upsell .inside{margin:0;padding:0}
			.crawlwp-dash-locked{position:relative;min-height:300px;overflow:hidden}
			.crawlwp-dash-locked img{display:block;width:100%;height:auto;user-select:none;pointer-events:none}
			.crawlwp-dash-locked__card{position:absolute;top:50%;left:50%;width:min(340px,calc(100% - 32px));box-sizing:border-box;padding:20px 22px;transform:translate(-50%,-50%);background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.14);text-align:center}
			.crawlwp-dash-locked__card h3{margin:0 0 6px;font-size:15px;font-weight:600}
			.crawlwp-dash-locked__card p{margin:0 0 14px;color:#50575e}
			.crawlwp-dash-locked__card .button-primary{background:#3858e9;border-color:#3858e9}
			.crawlwp-dash-locked__hide{display:block;margin-top:10px;color:#787c82;font-size:12px}
		</style>
		<div class="crawlwp-dash-locked">
			<img src="<?php echo esc_url(Utils::get_plugin_asset_url('images/upsell-dash-preview.jpg')); ?>" alt="" aria-hidden="true">
			<div class="crawlwp-dash-locked__card">
				<h3><?php esc_html_e('Your search traffic, right here', 'mihdan-index-now'); ?></h3>
				<p><?php esc_html_e('Clicks, impressions and top pages from Google, Bing and Yandex on your dashboard.', 'mihdan-index-now'); ?></p>
				<a class="button button-primary" target="_blank" href="<?php echo esc_url('https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-dashboard-widget'); ?>"><?php esc_html_e('Upgrade to CrawlWP Premium', 'mihdan-index-now'); ?></a>
				<a class="crawlwp-dash-locked__hide" href="<?php echo esc_url($hide); ?>"><?php esc_html_e('Hide this box', 'mihdan-index-now'); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * "Hide this box" on the dashboard widget, saved per user.
	 */
	public function hide_dashboard_widget(): void
	{
		if (empty($_GET['crawlwp_hide_dashboard_upsell']) || ! current_user_can('manage_options')) {
			return;
		}

		check_admin_referer('crawlwp_hide_dashboard_upsell');
		update_user_meta(get_current_user_id(), self::HIDE_DASHBOARD_META, 1);
		wp_safe_redirect(admin_url('index.php'));
		exit;
	}

	public function setup_fields()
	{
		if (wp_doing_ajax()) return;

		$seo_index_upsell = self::locked_preview([
			'image'    => 'upsell-index-preview.jpg',
			'title'    => __('Find pages Google hasn’t indexed, and fix them', 'mihdan-index-now'),
			'text'     => __('CrawlWP checks every page with Google and Bing and sends the ones that are missing.', 'mihdan-index-now'),
			'points'   => [
				__('<b>Index status of every page</b>, checked automatically', 'mihdan-index-now'),
				[__('<b>Why a page isn’t indexed</b>, with steps to fix it', 'mihdan-index-now'), true],
				__('<b>Automatic resubmission</b> of pages that aren’t indexed', 'mihdan-index-now'),
				__('<b>Index history</b>: when each page was sent and indexed', 'mihdan-index-now'),
			],
			'note'     => __('Works with the Google and Bing connection you already set up.', 'mihdan-index-now'),
			'campaign' => 'crawlwp-seo-index-upsell-page',
		]);

		$seo_stat_upsell = self::locked_preview([
			'image'    => 'upsell-stats-preview.jpg',
			'title'    => __('See how people find your site in search', 'mihdan-index-now'),
			'text'     => __('Your Google, Bing and Yandex numbers inside WordPress, without opening Search Console.', 'mihdan-index-now'),
			'points'   => [
				__('<b>Clicks, impressions, CTR and position</b>, with the change from the last period', 'mihdan-index-now'),
				__('<b>Top keywords and pages</b>, with winners and losers', 'mihdan-index-now'),
				__('<b>Countries and devices</b> your visitors search from', 'mihdan-index-now'),
				[__('<b>Opportunities</b>: keywords close to page 1 and pages losing clicks', 'mihdan-index-now'), true],
			],
			'note'     => __('Connect Google, Bing or Yandex in a few clicks after upgrading.', 'mihdan-index-now'),
			'campaign' => 'crawlwp-seo-stats-upsell-page',
		]);

		$analytics_upsell = self::locked_preview([
			'image'    => 'upsell-analytics-page.png',
			'blur'     => true,
			'title'    => __('See your Google Analytics stats in WordPress', 'mihdan-index-now'),
			'text'     => __('CrawlWP Premium shows who visits your site, where they come from and which pages they read, right next to your search data.', 'mihdan-index-now'),
			'points'   => [
				__('Visitors, views and time on page, compared with the previous period', 'mihdan-index-now'),
				__('Top pages, landing pages, traffic sources, countries and devices', 'mihdan-index-now'),
				__('Click any page to see its own numbers, or check them in the post editor', 'mihdan-index-now'),
				__('Connect with one click, or use your service account', 'mihdan-index-now'),
			],
			'campaign' => 'crawlwp-analytics-upsell-page',
		]);

		$this->wposa->sub_page_title  = esc_html__('SEO Indexing', 'mihdan-index-now');
		$this->wposa2->sub_page_title = esc_html__('SEO Stats', 'mihdan-index-now');

		$this->wposa->add_section([
			'id' => 'seo_index_upsell',
		]);

		$this->wposa->add_field(
			'seo_index_upsell',
			[
				'id'   => 'pages',
				'type' => 'html',
				'desc' => $seo_index_upsell
			]
		);

		$this->wposa2->add_section([
			'id' => 'seo_stat_upsell',
		]);

		$this->wposa2->add_field(
			'seo_stat_upsell',
			[
				'id'   => 'pages2',
				'type' => 'html',
				'desc' => $seo_stat_upsell
			]
		);

		$this->analytics_wposa->sub_page_title = esc_html__('Analytics', 'mihdan-index-now');

		$this->analytics_wposa->add_section([
			'id' => 'analytics_upsell',
		]);

		$this->analytics_wposa->add_field(
			'analytics_upsell',
			[
				'id'   => 'analytics_page',
				'type' => 'html',
				'desc' => $analytics_upsell
			]
		);
	}
}
