<?php

namespace Mihdan\IndexNow\SEOCore\Integrations;

use Mihdan\IndexNow\SEOCore\MetaBox\Assets;
use Mihdan\IndexNow\SEOCore\MetaBox\FieldProcessor;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;

/**
 * CrawlWP SEO fields and interactive diagnostic bridge inside Elementor.
 */
class Elementor
{
	/** Elementor's page-settings post meta key. */
	private const PAGE_SETTINGS_META = '_elementor_page_settings';

	/** Re-entrancy guard for the page-settings overlay filter. */
	private static bool $reading_raw = false;

	public function __construct()
	{
		add_action('elementor/documents/register_controls', [$this, 'register_controls']);
		add_action('elementor/document/after_save', [$this, 'save'], 10, 2);
		add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_scripts']);
		add_action('elementor/editor/after_enqueue_styles', [$this, 'enqueue_editor_styles']);
		add_filter('get_post_metadata', [$this, 'overlay_page_settings'], 10, 4);
	}

	/**
	 * Static control defaults. Elementor drops values equal to a control's
	 * default when saving, so defaults must be fixed (not the current meta)
	 * for an omitted key to have a known meaning.
	 *
	 * @return array<string, mixed>
	 */
	private static function control_defaults(): array
	{
		return [
			MetaFields::SEO_TITLE           => '',
			MetaFields::SEO_DESCRIPTION     => '',
			MetaFields::FOCUS_KEYWORD       => '',
			MetaFields::CANONICAL_URL       => ['url' => ''],
			MetaFields::PRIMARY_CATEGORY    => '0',
			MetaFields::OG_SYNC             => 'yes',
			MetaFields::OG_TITLE            => '',
			MetaFields::OG_DESCRIPTION      => '',
			MetaFields::OG_IMAGE            => ['id' => '', 'url' => ''],
			MetaFields::OG_IMAGE_ALT        => '',
			MetaFields::X_SYNC              => 'yes',
			MetaFields::X_TITLE             => '',
			MetaFields::X_DESCRIPTION       => '',
			MetaFields::X_IMAGE             => ['id' => '', 'url' => ''],
			MetaFields::X_CARD_TYPE         => '',
			MetaFields::X_CREATOR           => '',
			MetaFields::SCHEMA_PAGE_TYPE    => '',
			MetaFields::SCHEMA_ARTICLE_TYPE => '',
			MetaFields::SCHEMA_HEADLINE     => '',
			MetaFields::SCHEMA_SECTION      => '',
			MetaFields::SCHEMA_BREADCRUMB   => '',
			MetaFields::SCHEMA_CUSTOM       => '',
			MetaFields::ROBOTS_INDEX        => '',
			MetaFields::ROBOTS_FOLLOW       => '',
			MetaFields::ROBOTS_ADVANCED     => [],
			MetaFields::MAX_SNIPPET         => '',
			MetaFields::MAX_IMAGE           => 'large',
			MetaFields::REDIRECT_URL        => ['url' => ''],
			MetaFields::REDIRECT_TYPE       => '301',
		];
	}

	/**
	 * @param string $key Control / meta key.
	 * @return mixed
	 */
	private static function control_default(string $key)
	{
		return self::control_defaults()[$key] ?? '';
	}

	/**
	 * Current post meta value, shaped as the Elementor control expects it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Control / meta key.
	 * @return mixed
	 */
	private static function control_value(int $post_id, string $key)
	{
		switch ($key) {
			case MetaFields::CANONICAL_URL:
			case MetaFields::REDIRECT_URL:
				return [
					'url'               => (string) MetaFields::get($post_id, $key),
					'is_external'       => '',
					'nofollow'          => '',
					'custom_attributes' => '',
				];

			case MetaFields::OG_IMAGE:
			case MetaFields::X_IMAGE:
				$image_id = (int) MetaFields::get($post_id, $key, 0);

				return [
					'id'  => $image_id > 0 ? $image_id : '',
					'url' => $image_id > 0 ? (string) wp_get_attachment_image_url($image_id, 'full') : '',
				];

			case MetaFields::OG_SYNC:
			case MetaFields::X_SYNC:
				return (string) MetaFields::get($post_id, $key, '1') === '1' ? 'yes' : '';

			case MetaFields::ROBOTS_ADVANCED:
				$value = MetaFields::get($post_id, $key, []);

				return is_array($value) ? array_values(array_map('strval', $value)) : [];

			default:
				$default = self::control_default($key);

				return (string) MetaFields::get($post_id, $key, is_string($default) ? $default : '');
		}
	}

	/**
	 * Whether CrawlWP SEO controls apply to this post (same post types the
	 * SEO metabox / Title & Meta settings cover; never Elementor templates).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_supported_post(int $post_id): bool
	{
		$post_type = get_post_type($post_id);

		if (! $post_type || $post_type === 'elementor_library') {
			return false;
		}

		foreach (Entities::post_types() as $post_type_object) {
			if ($post_type_object->name === $post_type) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this is the Elementor editor (page load or its AJAX endpoint).
	 *
	 * @return bool
	 */
	private static function is_editor_request(): bool
	{
		if (! is_admin()) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

		return $action === 'elementor' || (wp_doing_ajax() && $action === 'elementor_ajax');
	}

	/**
	 * Read Elementor's stored page settings without the overlay.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function raw_page_settings(int $post_id): array
	{
		self::$reading_raw = true;
		$settings          = get_post_meta($post_id, self::PAGE_SETTINGS_META, true);
		self::$reading_raw = false;

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Feed Elementor's page-settings model the *current* CrawlWP post meta
	 * instead of whatever was saved in _elementor_page_settings last time, so
	 * edits made in the SEO metabox, bulk editor or importers show up (and are
	 * not written back stale) in the Elementor panel.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value requested.
	 * @return mixed
	 */
	public function overlay_page_settings($value, $object_id, $meta_key, $single)
	{
		if ($meta_key !== self::PAGE_SETTINGS_META || self::$reading_raw || $value !== null || ! self::is_editor_request()) {
			return $value;
		}

		$post_id = (int) $object_id;

		// Elementor autosaves are revisions — read the SEO meta of the parent.
		$meta_post_id = $post_id;
		if (get_post_type($post_id) === 'revision') {
			$meta_post_id = (int) wp_get_post_parent_id($post_id);
		}

		if ($meta_post_id <= 0 || ! self::is_supported_post($meta_post_id)) {
			return $value;
		}

		$settings = self::raw_page_settings($post_id);

		foreach (array_keys(self::control_defaults()) as $key) {
			$settings[$key] = self::control_value($meta_post_id, $key);
		}

		// get_metadata() returns $check[0] for single lookups.
		return [$settings];
	}

	/**
	 * Enqueue Elementor editor scripts with SEO localized data.
	 */
	public function enqueue_editor_scripts(): void
	{
		$post_id = 0;
		if (isset($_GET['post'])) {
			$post_id = absint($_GET['post']);
		} elseif (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->editor)) {
			$post_id = (int) \Elementor\Plugin::$instance->editor->get_post_id();
		}

		$post          = get_post($post_id);
		$localize_data = Assets::get_localized_data($post instanceof \WP_Post ? $post : null);

		wp_enqueue_script(
			'crawlwp-elementor-seo',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/Integrations/assets/elementor-seo.js',
			['jquery'],
			CRAWLWP_VERSION,
			true
		);

		wp_add_inline_script(
			'crawlwp-elementor-seo',
			'var crawlwpSEO = ' . wp_json_encode($localize_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
			'before'
		);
	}

	/**
	 * Enqueue styling for preview cards and score meters inside Elementor panel.
	 */
	public function enqueue_editor_styles(): void
	{
		wp_enqueue_style(
			'crawlwp-seo-metabox',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/MetaBox/assets/crawlwp-metabox.css',
			[],
			CRAWLWP_VERSION
		);

		wp_enqueue_style(
			'crawlwp-elementor-seo',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/Integrations/assets/elementor-seo.css',
			['crawlwp-seo-metabox'],
			CRAWLWP_VERSION
		);
	}

	/**
	 * Register structured CrawlWP SEO controls inside the Elementor document panel.
	 *
	 * @param \Elementor\Core\DocumentTypes\Document $document
	 */
	public function register_controls($document): void
	{
		if (! is_object($document) || ! method_exists($document, 'get_main_id')) {
			return;
		}

		$post_id = (int) $document->get_main_id();

		if ($post_id <= 0 || ! method_exists($document, 'start_controls_section')) {
			return;
		}

		if (! current_user_can('edit_post', $post_id) || ! self::is_supported_post($post_id)) {
			return;
		}

		// ---------------------------------------------------------------------
		// 1. General
		// ---------------------------------------------------------------------
		$document->start_controls_section('crawlwp_seo_general', [
			'label' => __('CrawlWP: General', 'mihdan-index-now'),
			'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
		]);

		$document->add_control(MetaFields::SEO_TITLE, [
			'label'       => __('SEO title', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'placeholder' => '{{ post.title }} {{ sep }} {{ site.title }}',
			'default'     => self::control_default(MetaFields::SEO_TITLE),
		]);

		$document->add_control(MetaFields::SEO_DESCRIPTION, [
			'label'       => __('Meta description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::SEO_DESCRIPTION),
		]);

		$document->add_control(MetaFields::FOCUS_KEYWORD, [
			'label'       => __('Focus keyword', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'ai'          => ['active' => false],
			'description' => __('Comma-separated list; first keyword is primary for scoring.', 'mihdan-index-now'),
			'default'     => self::control_default(MetaFields::FOCUS_KEYWORD),
		]);

		$document->add_control(MetaFields::CANONICAL_URL, [
			'label'       => __('Canonical URL', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::URL,
			'ai'          => ['active' => false],
			'placeholder' => 'https://...',
			'default'     => self::control_default(MetaFields::CANONICAL_URL),
		]);

		$category_options = [
			'0' => __('Default (First category)', 'mihdan-index-now'),
		];
		$terms = get_the_terms($post_id, 'category');
		if (! empty($terms) && ! is_wp_error($terms)) {
			foreach ($terms as $term) {
				$category_options[(string) $term->term_id] = $term->name;
			}
		}

		$document->add_control(MetaFields::PRIMARY_CATEGORY, [
			'label'       => __('Primary category', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::SELECT,
			'options'     => $category_options,
			'description' => __('Used for breadcrumbs and category permalink tags.', 'mihdan-index-now'),
			'default'     => self::control_default(MetaFields::PRIMARY_CATEGORY),
		]);

		$document->end_controls_section();

		// ---------------------------------------------------------------------
		// 2. Social Networks
		// ---------------------------------------------------------------------
		$document->start_controls_section('crawlwp_seo_social', [
			'label' => __('CrawlWP: Social Networks', 'mihdan-index-now'),
			'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
		]);

		$document->add_control('crawlwp_og_heading', [
			'label'     => __('Facebook / Open Graph', 'mihdan-index-now'),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		]);

		$document->add_control(MetaFields::OG_SYNC, [
			'label'        => __('Sync with SEO title & description', 'mihdan-index-now'),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __('Yes', 'mihdan-index-now'),
			'label_off'    => __('No', 'mihdan-index-now'),
			'return_value' => 'yes',
			'default'      => self::control_default(MetaFields::OG_SYNC),
		]);

		$document->add_control(MetaFields::OG_TITLE, [
			'label'       => __('Open Graph title', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::OG_TITLE),
			'condition'   => [
				MetaFields::OG_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::OG_DESCRIPTION, [
			'label'       => __('Open Graph description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::OG_DESCRIPTION),
			'condition'   => [
				MetaFields::OG_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::OG_IMAGE, [
			'label'   => __('Open Graph image', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::MEDIA,
			'ai'      => ['active' => false],
			'default' => self::control_default(MetaFields::OG_IMAGE),
		]);

		$document->add_control(MetaFields::OG_IMAGE_ALT, [
			'label'   => __('Social image alt text', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'default' => self::control_default(MetaFields::OG_IMAGE_ALT),
		]);

		$document->add_control('crawlwp_x_heading', [
			'label'     => __('X (Twitter)', 'mihdan-index-now'),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		]);

		$document->add_control(MetaFields::X_SYNC, [
			'label'        => __('Sync with Open Graph', 'mihdan-index-now'),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __('Yes', 'mihdan-index-now'),
			'label_off'    => __('No', 'mihdan-index-now'),
			'return_value' => 'yes',
			'default'      => self::control_default(MetaFields::X_SYNC),
		]);

		$document->add_control(MetaFields::X_TITLE, [
			'label'       => __('X title', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::X_TITLE),
			'condition'   => [
				MetaFields::X_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::X_DESCRIPTION, [
			'label'       => __('X description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::X_DESCRIPTION),
			'condition'   => [
				MetaFields::X_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::X_IMAGE, [
			'label'     => __('X image', 'mihdan-index-now'),
			'type'      => \Elementor\Controls_Manager::MEDIA,
			'ai'        => ['active' => false],
			'default'   => self::control_default(MetaFields::X_IMAGE),
			'condition' => [
				MetaFields::X_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::X_CARD_TYPE, [
			'label'   => __('Card type', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				''                    => __('Default', 'mihdan-index-now'),
				'summary_large_image' => __('Large image summary', 'mihdan-index-now'),
				'summary'             => __('Summary', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::X_CARD_TYPE),
		]);

		$document->add_control(MetaFields::X_CREATOR, [
			'label'       => __('Creator (@username)', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'ai'          => ['active' => false],
			'placeholder' => '@username',
			'default'     => self::control_default(MetaFields::X_CREATOR),
		]);

		$document->end_controls_section();

		// ---------------------------------------------------------------------
		// 3. Schema & Structured Data
		// ---------------------------------------------------------------------
		$document->start_controls_section('crawlwp_seo_schema', [
			'label' => __('CrawlWP: Schema & Structured Data', 'mihdan-index-now'),
			'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
		]);

		$page_types = [
			'WebPage'           => 'WebPage',
			'ItemPage'          => 'ItemPage',
			'AboutPage'         => 'AboutPage',
			'FAQPage'           => 'FAQPage',
			'QAPage'            => 'QAPage',
			'ProfilePage'       => 'ProfilePage',
			'ContactPage'       => 'ContactPage',
			'MedicalWebPage'    => 'MedicalWebPage',
			'CollectionPage'    => 'CollectionPage',
			'RealEstateListing' => 'RealEstateListing',
			'none'              => __('None (disable)', 'mihdan-index-now'),
		];

		$article_types = [
			'Article'                  => 'Article',
			'BlogPosting'              => 'BlogPosting',
			'SocialMediaPosting'       => 'SocialMediaPosting',
			'NewsArticle'              => 'NewsArticle',
			'AdvertiserContentArticle' => 'AdvertiserContentArticle',
			'SatiricalArticle'         => 'SatiricalArticle',
			'ScholarlyArticle'         => 'ScholarlyArticle',
			'TechArticle'              => 'TechArticle',
			'Report'                   => 'Report',
			'none'                     => __('None (disable)', 'mihdan-index-now'),
		];

		$document->add_control(MetaFields::SCHEMA_PAGE_TYPE, [
			'label'   => __('Page type', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => ['' => __('Default', 'mihdan-index-now')] + $page_types,
			'default' => self::control_default(MetaFields::SCHEMA_PAGE_TYPE),
		]);

		$document->add_control(MetaFields::SCHEMA_ARTICLE_TYPE, [
			'label'   => __('Article type', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => ['' => __('Default', 'mihdan-index-now')] + $article_types,
			'default' => self::control_default(MetaFields::SCHEMA_ARTICLE_TYPE),
		]);

		$document->add_control(MetaFields::SCHEMA_HEADLINE, [
			'label'       => __('Headline', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'placeholder' => __('Leave empty to use SEO title', 'mihdan-index-now'),
			'default'     => self::control_default(MetaFields::SCHEMA_HEADLINE),
		]);

		$document->add_control(MetaFields::SCHEMA_SECTION, [
			'label'   => __('Article section', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'default' => self::control_default(MetaFields::SCHEMA_SECTION),
		]);

		$document->add_control(MetaFields::SCHEMA_BREADCRUMB, [
			'label'   => __('Breadcrumb title', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'default' => self::control_default(MetaFields::SCHEMA_BREADCRUMB),
		]);

		$document->add_control(MetaFields::SCHEMA_CUSTOM, [
			'label'       => __('Custom JSON-LD schema', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'description' => __('Valid JSON-LD block to merge into page schema.', 'mihdan-index-now'),
			'default'     => self::control_default(MetaFields::SCHEMA_CUSTOM),
		]);

		$document->end_controls_section();

		// ---------------------------------------------------------------------
		// 4. Robots & Redirects
		// ---------------------------------------------------------------------
		$document->start_controls_section('crawlwp_seo_advanced', [
			'label' => __('CrawlWP: Robots & Redirects', 'mihdan-index-now'),
			'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
		]);

		$document->add_control(MetaFields::ROBOTS_INDEX, [
			'label'   => __('Allow Indexing', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				''        => __('Default', 'mihdan-index-now'),
				'index'   => __('Yes — index this post', 'mihdan-index-now'),
				'noindex' => __('No — keep it out of search results', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::ROBOTS_INDEX),
		]);

		$document->add_control(MetaFields::ROBOTS_FOLLOW, [
			'label'   => __('Follow links', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				''         => __('Default', 'mihdan-index-now'),
				'follow'   => __('Yes — follow links on this page', 'mihdan-index-now'),
				'nofollow' => __('No — do not follow links on this page', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::ROBOTS_FOLLOW),
		]);

		$document->add_control(MetaFields::ROBOTS_ADVANCED, [
			'label'       => __('Crawler directives', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => [
				'noimageindex' => __('No image indexing (noimageindex)', 'mihdan-index-now'),
				'noarchive'    => __('No cached copy (noarchive)', 'mihdan-index-now'),
				'nosnippet'    => __('No snippet (nosnippet)', 'mihdan-index-now'),
				'notranslate'  => __('No translated results (notranslate)', 'mihdan-index-now'),
			],
			'default'     => self::control_default(MetaFields::ROBOTS_ADVANCED),
		]);

		$document->add_control(MetaFields::MAX_SNIPPET, [
			'label'   => __('Max snippet', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				''     => __('Default', 'mihdan-index-now'),
				'none' => __('None — no snippet', 'mihdan-index-now'),
				'160'  => __('160 characters', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::MAX_SNIPPET),
		]);

		$document->add_control(MetaFields::MAX_IMAGE, [
			'label'   => __('Max image preview', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				'large'    => __('Large (default)', 'mihdan-index-now'),
				'standard' => __('Standard', 'mihdan-index-now'),
				'none'     => __('None', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::MAX_IMAGE),
		]);

		$document->add_control(MetaFields::REDIRECT_URL, [
			'label'       => __('301 / 302 Redirect URL', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::URL,
			'placeholder' => 'https://...',
			'ai'          => ['active' => false],
			'default'     => self::control_default(MetaFields::REDIRECT_URL),
		]);

		$document->add_control(MetaFields::REDIRECT_TYPE, [
			'label'   => __('Redirect HTTP status', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				'301' => __('301 Permanent', 'mihdan-index-now'),
				'302' => __('302 Found / Temporary', 'mihdan-index-now'),
				'307' => __('307 Temporary Redirect', 'mihdan-index-now'),
				'410' => __('410 Content Deleted', 'mihdan-index-now'),
				'451' => __('451 Unavailable For Legal Reasons', 'mihdan-index-now'),
			],
			'default' => self::control_default(MetaFields::REDIRECT_TYPE),
		]);

		$document->end_controls_section();
	}

	/**
	 * Persist CrawlWP SEO settings through the central FieldProcessor pipeline.
	 *
	 * Elementor omits settings equal to their control default, so only keys
	 * present in the payload are written — except on a full editor save,
	 * where an omitted key means "set back to the (static) default".
	 *
	 * @param \Elementor\Core\DocumentTypes\Document|object $document
	 * @param array $data
	 */
	public function save($document, array $data): void
	{
		if (! is_object($document) || ! method_exists($document, 'get_main_id')) {
			return;
		}

		// Autosaves must never change the live post's SEO meta.
		if (method_exists($document, 'is_autosave') && $document->is_autosave()) {
			return;
		}

		$post_id  = (int) $document->get_main_id();
		$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

		if ($post_id <= 0 || $settings === []) {
			return;
		}

		if (! current_user_can('edit_post', $post_id) || ! self::is_supported_post($post_id)) {
			return;
		}

		$defaults = self::control_defaults();

		// The editor's own save request sends the whole page-settings model
		// minus default-valued keys. Any other caller (programmatic saves)
		// may send a partial array, so only its present keys are touched.
		$is_editor_save = wp_doing_ajax() && self::is_editor_request();

		$submitted = array_intersect_key($settings, $defaults);

		if ($is_editor_save) {
			$submitted += $defaults;
		}

		if ($submitted === []) {
			return;
		}

		$normalized = [];

		// Unpack URL and media picker arrays to plain values expected by FieldProcessor
		foreach ($submitted as $key => $value) {
			if ($key === MetaFields::CANONICAL_URL || $key === MetaFields::REDIRECT_URL) {
				$normalized[$key] = is_array($value) ? (string) ($value['url'] ?? '') : (string) $value;
			} elseif ($key === MetaFields::OG_IMAGE || $key === MetaFields::X_IMAGE) {
				$normalized[$key] = is_array($value) ? (string) ($value['id'] ?? '') : (string) $value;
			} elseif ($key === MetaFields::OG_SYNC || $key === MetaFields::X_SYNC) {
				// Switcher: 'yes'/'1' is on (present key); anything else is off,
				// which the checkbox definition records as '0'.
				if ($value === 'yes' || $value === '1') {
					$normalized[$key] = '1';
				}
			} else {
				$normalized[$key] = $value;
			}
		}

		// Restrict the "always write" checkbox/multi-select definitions to the
		// keys actually submitted, so omitted controls are never reset.
		$definitions = array_intersect_key(MetaFields::field_definitions(true), $submitted);
		// FieldProcessor expects slashed request data; Elementor's payload is
		// already unslashed (JSON-decoded), so re-slash to keep backslashes.
		$values      = FieldProcessor::process($definitions, wp_slash($normalized));

		// External redirect authorization check
		if (
			isset($values[MetaFields::REDIRECT_URL]) &&
			$values[MetaFields::REDIRECT_URL] !== '' &&
			MetaFields::is_external_url($values[MetaFields::REDIRECT_URL]) &&
			! MetaFields::can_redirect_externally($post_id)
		) {
			$values[MetaFields::REDIRECT_URL] = '';
		}

		// Persist verified post meta
		foreach ($values as $meta_key => $meta_val) {
			update_post_meta($post_id, $meta_key, $meta_val);
		}

		// Ensure default values are written for empty keys
		MetaFields::store_defaults($post_id);

		// Post meta is the single source of truth: drop the copies Elementor
		// just stored in its page settings so they can never go stale.
		$this->strip_page_settings_copy($document);

		// Refresh signals cache
		SeoSignals::persist($post_id);
	}

	/**
	 * Remove CrawlWP keys from the document's stored Elementor page settings.
	 *
	 * @param object $document Elementor document.
	 */
	private function strip_page_settings_copy($document): void
	{
		$post = method_exists($document, 'get_post') ? $document->get_post() : null;

		if (! $post instanceof \WP_Post) {
			return;
		}

		$stored   = self::raw_page_settings($post->ID);
		$stripped = array_diff_key($stored, self::control_defaults());

		if ($stripped === $stored) {
			return;
		}

		if ($stripped === []) {
			delete_metadata('post', $post->ID, self::PAGE_SETTINGS_META);
		} else {
			update_metadata('post', $post->ID, self::PAGE_SETTINGS_META, wp_slash($stripped));
		}
	}
}
