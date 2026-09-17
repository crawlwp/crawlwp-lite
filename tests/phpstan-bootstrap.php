<?php
/**
 * Constants PHPStan needs to analyse the plugin outside a WordPress runtime.
 *
 * @package mihdan-index-now
 */

define('CRAWLWP_FILE', __DIR__ . '/../mihdan-index-now.php');
define('CRAWLWP_VERSION', '0.0.0');
define('CRAWLWP_SLUG', 'crawlwp');
define('CRAWLWP_PREFIX', 'crawlwp');
define('CRAWLWP_PLUGIN_URL', 'https://example.com/wp-content/plugins/mihdan-index-now/');
define('CRAWLWP_PLUGIN_DIR', __DIR__ . '/../');
define('CRAWLWP_SETTINGS_URL', 'https://example.com/wp-admin/admin.php?page=crawlwp');
define('CRAWLWP_API_SETTINGS_URL', CRAWLWP_SETTINGS_URL);
define('CRAWLWP_ADVANCED_SETTINGS_URL', CRAWLWP_SETTINGS_URL);

$crawlwp_pro_autoload = dirname(__DIR__, 2) . '/mihdan-index-now-pro/Libsodium/vendor/autoload.php';
if (file_exists($crawlwp_pro_autoload)) {
	require_once $crawlwp_pro_autoload;
}

if (! class_exists('Elementor\Controls_Manager')) {
	eval('namespace Elementor; class Controls_Manager {
		public const TAB_SETTINGS = "settings";
		public const TEXT = "text";
		public const TEXTAREA = "textarea";
		public const SELECT = "select";
		public const SELECT2 = "select2";
		public const URL = "url";
		public const SWITCHER = "switcher";
		public const MEDIA = "media";
		public const RAW_HTML = "raw_html";
		public const CODE = "code";
		public const HIDDEN = "hidden";
		public const HEADING = "heading";
		public const DIVIDER = "divider";
	}');
}

if (! class_exists('Elementor\Core\DocumentTypes\Document')) {
	eval('namespace Elementor\Core\DocumentTypes; class Document {
		public int $id = 0;
		public array $controls = [];
		public array $sections = [];
		public function get_main_id(): int { return $this->id; }
		public function start_controls_section(string $section_id, array $args = []): void {}
		public function end_controls_section(): void {}
		public function add_control(string $id, array $args = []): void {}
	}');
}
