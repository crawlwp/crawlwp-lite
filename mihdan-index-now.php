<?php
/**
 * Plugin Name: CrawlWP SEO - WordPress SEO, Instant Indexing & Search Performance
 * Description: Complete WordPress SEO: meta titles, schema, redirects and sitemaps, plus instant indexing.
 * Version: 3.1.0
 * Author: CrawlWP SEO Team
 * Author URI: https://crawlwp.com/
 * Plugin URI: https://crawlwp.com/
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Mihdan\IndexNow;

if ( ! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/vendor-prefixed/autoload.php';

define('CRAWLWP_VERSION', '3.1.0');
define('CRAWLWP_SLUG', 'crawlwp');
define('CRAWLWP_PREFIX', 'crawlwp');
define('CRAWLWP_NAME', 'CrawlWP');
define('CRAWLWP_FILE', __FILE__);
define('CRAWLWP_DIR', __DIR__);
define('CRAWLWP_BASENAME', plugin_basename(__FILE__));
define('CRAWLWP_PLUGIN_URL', plugin_dir_url(__FILE__));

define('CRAWLWP_SETTINGS_URL', admin_url('admin.php?page=' . CRAWLWP_SLUG));
define('CRAWLWP_API_SETTINGS_URL', add_query_arg(['wposa-menu' => Utils::get_plugin_prefix() . '_api_settings'], CRAWLWP_SETTINGS_URL));
define('CRAWLWP_ADVANCED_SETTINGS_URL', add_query_arg(['wposa-menu' => Utils::get_plugin_prefix() . '_advanced_settings'], CRAWLWP_SETTINGS_URL));

define('CRAWLWP_PRO_SEO_INDEX_SLUG', 'crawlwp-seo-index');
define('CRAWLWP_PRO_SEO_STAT_SLUG', 'crawlwp-seo-stats');
define('CRAWLWP_PRO_AUTO_INDEX_PAGE', admin_url('admin.php?page=' . CRAWLWP_PRO_SEO_INDEX_SLUG));
define('CRAWLWP_PRO_AUTO_INDEX_PAGE_RECENT_MOVEMENT', add_query_arg(['section' => 'recent-movement'], CRAWLWP_PRO_AUTO_INDEX_PAGE));
define('CRAWLWP_PRO_SEO_STAT_PAGE', admin_url('admin.php?page=' . CRAWLWP_PRO_SEO_STAT_SLUG));

do_action('crawlwp_lite_before_init');

(new Main(new Container()))->init();
