<?php

declare(strict_types=1);

namespace Mihdan\IndexNow\SEOCore\Integrations;

use Mihdan\IndexNow\SEOCore\MetaBox\Assets;
use Mihdan\IndexNow\SEOCore\MetaBox\FieldProcessor;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\MetaBox\SeoSignals;

/**
 * CrawlWP SEO fields and interactive diagnostic bridge inside Elementor.
 */
class Elementor
{
	public function __construct()
	{
		add_action('elementor/documents/register_controls', [$this, 'register_controls']);
		add_action('elementor/document/after_save', [$this, 'save'], 10, 2);
		add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_scripts']);
		add_action('elementor/editor/after_enqueue_styles', [$this, 'enqueue_editor_styles']);
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

		if (! current_user_can('edit_post', $post_id)) {
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
			'dynamic'     => ['active' => true],
			'placeholder' => '{{ post.title }} {{ sep }} {{ site.title }}',
			'default'     => (string) MetaFields::get($post_id, MetaFields::SEO_TITLE),
		]);

		$document->add_control(MetaFields::SEO_DESCRIPTION, [
			'label'       => __('Meta description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => (string) MetaFields::get($post_id, MetaFields::SEO_DESCRIPTION),
		]);

		$document->add_control(MetaFields::FOCUS_KEYWORD, [
			'label'       => __('Focus keyword', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'description' => __('Comma-separated list; first keyword is primary for scoring.', 'mihdan-index-now'),
			'default'     => (string) MetaFields::get($post_id, MetaFields::FOCUS_KEYWORD),
		]);

		$document->add_control(MetaFields::CANONICAL_URL, [
			'label'       => __('Canonical URL', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::URL,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'placeholder' => 'https://...',
			'default'     => ['url' => (string) MetaFields::get($post_id, MetaFields::CANONICAL_URL)],
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
			'default'     => (string) MetaFields::get($post_id, MetaFields::PRIMARY_CATEGORY, '0'),
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
			'default'      => (string) MetaFields::get($post_id, MetaFields::OG_SYNC, '1') === '1' ? 'yes' : '',
		]);

		$document->add_control(MetaFields::OG_TITLE, [
			'label'       => __('Open Graph title', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => (string) MetaFields::get($post_id, MetaFields::OG_TITLE),
			'condition'   => [
				MetaFields::OG_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::OG_DESCRIPTION, [
			'label'       => __('Open Graph description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => (string) MetaFields::get($post_id, MetaFields::OG_DESCRIPTION),
			'condition'   => [
				MetaFields::OG_SYNC => '',
			],
		]);

		$og_img_id  = (int) MetaFields::get($post_id, MetaFields::OG_IMAGE, 0);
		$og_img_url = $og_img_id > 0 ? (string) wp_get_attachment_image_url($og_img_id, 'full') : '';

		$document->add_control(MetaFields::OG_IMAGE, [
			'label'   => __('Open Graph image', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::MEDIA,
			'ai'      => ['active' => false],
			'default' => [
				'id'  => $og_img_id > 0 ? $og_img_id : '',
				'url' => $og_img_url,
			],
		]);

		$document->add_control(MetaFields::OG_IMAGE_ALT, [
			'label'   => __('Social image alt text', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'default' => (string) MetaFields::get($post_id, MetaFields::OG_IMAGE_ALT),
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
			'default'      => (string) MetaFields::get($post_id, MetaFields::X_SYNC, '1') === '1' ? 'yes' : '',
		]);

		$document->add_control(MetaFields::X_TITLE, [
			'label'       => __('X title', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => (string) MetaFields::get($post_id, MetaFields::X_TITLE),
			'condition'   => [
				MetaFields::X_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::X_DESCRIPTION, [
			'label'       => __('X description', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => (string) MetaFields::get($post_id, MetaFields::X_DESCRIPTION),
			'condition'   => [
				MetaFields::X_SYNC => '',
			],
		]);

		$x_img_id  = (int) MetaFields::get($post_id, MetaFields::X_IMAGE, 0);
		$x_img_url = $x_img_id > 0 ? (string) wp_get_attachment_image_url($x_img_id, 'full') : '';

		$document->add_control(MetaFields::X_IMAGE, [
			'label'     => __('X image', 'mihdan-index-now'),
			'type'      => \Elementor\Controls_Manager::MEDIA,
			'ai'        => ['active' => false],
			'default'   => [
				'id'  => $x_img_id > 0 ? $x_img_id : '',
				'url' => $x_img_url,
			],
			'condition' => [
				MetaFields::X_SYNC => '',
			],
		]);

		$document->add_control(MetaFields::X_CARD_TYPE, [
			'label'   => __('Card type', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				'summary_large_image' => __('Large image summary', 'mihdan-index-now'),
				'summary'             => __('Summary', 'mihdan-index-now'),
			],
			'default' => (string) MetaFields::get($post_id, MetaFields::X_CARD_TYPE, 'summary_large_image'),
		]);

		$document->add_control(MetaFields::X_CREATOR, [
			'label'       => __('Creator (@username)', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'ai'          => ['active' => false],
			'placeholder' => '@username',
			'default'     => (string) MetaFields::get($post_id, MetaFields::X_CREATOR),
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
			'options' => $page_types,
			'default' => (string) MetaFields::get($post_id, MetaFields::SCHEMA_PAGE_TYPE, 'WebPage'),
		]);

		$document->add_control(MetaFields::SCHEMA_ARTICLE_TYPE, [
			'label'   => __('Article type', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => $article_types,
			'default' => (string) MetaFields::get($post_id, MetaFields::SCHEMA_ARTICLE_TYPE, 'Article'),
		]);

		$document->add_control(MetaFields::SCHEMA_HEADLINE, [
			'label'       => __('Headline', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'label_block' => true,
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'placeholder' => __('Leave empty to use SEO title', 'mihdan-index-now'),
			'default'     => (string) MetaFields::get($post_id, MetaFields::SCHEMA_HEADLINE),
		]);

		$document->add_control(MetaFields::SCHEMA_SECTION, [
			'label'   => __('Article section', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'dynamic' => ['active' => true],
			'default' => (string) MetaFields::get($post_id, MetaFields::SCHEMA_SECTION),
		]);

		$document->add_control(MetaFields::SCHEMA_BREADCRUMB, [
			'label'   => __('Breadcrumb title', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::TEXT,
			'ai'      => ['active' => false],
			'dynamic' => ['active' => true],
			'default' => (string) MetaFields::get($post_id, MetaFields::SCHEMA_BREADCRUMB),
		]);

		$document->add_control(MetaFields::SCHEMA_CUSTOM, [
			'label'       => __('Custom JSON-LD schema', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'label_block' => true,
			'ai'          => ['active' => false],
			'description' => __('Valid JSON-LD block to merge into page schema.', 'mihdan-index-now'),
			'default'     => (string) MetaFields::get($post_id, MetaFields::SCHEMA_CUSTOM),
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
				'index'   => __('Yes — index this post', 'mihdan-index-now'),
				'noindex' => __('No — keep it out of search results', 'mihdan-index-now'),
			],
			'default' => (string) MetaFields::get($post_id, MetaFields::ROBOTS_INDEX, 'index'),
		]);

		$document->add_control(MetaFields::ROBOTS_FOLLOW, [
			'label'   => __('Follow links', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				'follow'   => __('Yes — follow links on this page', 'mihdan-index-now'),
				'nofollow' => __('No — do not follow links on this page', 'mihdan-index-now'),
			],
			'default' => (string) MetaFields::get($post_id, MetaFields::ROBOTS_FOLLOW, 'follow'),
		]);

		$saved_robots_adv = MetaFields::get($post_id, MetaFields::ROBOTS_ADVANCED, []);
		if (! is_array($saved_robots_adv)) {
			$saved_robots_adv = [];
		}

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
			'default'     => $saved_robots_adv,
		]);

		$document->add_control(MetaFields::MAX_SNIPPET, [
			'label'   => __('Max snippet', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				''     => __('Default', 'mihdan-index-now'),
				'none' => __('None — no snippet', 'mihdan-index-now'),
				'160'  => __('160 characters', 'mihdan-index-now'),
			],
			'default' => (string) MetaFields::get($post_id, MetaFields::MAX_SNIPPET, ''),
		]);

		$document->add_control(MetaFields::MAX_IMAGE, [
			'label'   => __('Max image preview', 'mihdan-index-now'),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'options' => [
				'large'    => __('Large (default)', 'mihdan-index-now'),
				'standard' => __('Standard', 'mihdan-index-now'),
				'none'     => __('None', 'mihdan-index-now'),
			],
			'default' => (string) MetaFields::get($post_id, MetaFields::MAX_IMAGE, 'large'),
		]);

		$document->add_control(MetaFields::REDIRECT_URL, [
			'label'       => __('301 / 302 Redirect URL', 'mihdan-index-now'),
			'type'        => \Elementor\Controls_Manager::URL,
			'placeholder' => 'https://...',
			'ai'          => ['active' => false],
			'dynamic'     => ['active' => true],
			'default'     => ['url' => (string) MetaFields::get($post_id, MetaFields::REDIRECT_URL)],
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
			'default' => (string) MetaFields::get($post_id, MetaFields::REDIRECT_TYPE, '301'),
		]);

		$document->end_controls_section();
	}

	/**
	 * Persist all CrawlWP SEO settings through the central FieldProcessor pipeline.
	 *
	 * @param \Elementor\Core\DocumentTypes\Document|object $document
	 * @param array $data
	 */
	public function save($document, array $data): void
	{
		if (! is_object($document) || ! method_exists($document, 'get_main_id')) {
			return;
		}

		$post_id  = (int) $document->get_main_id();
		$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

		if ($post_id <= 0 || $settings === []) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$normalized = [];

		// Unpack URL and media picker arrays to plain values expected by FieldProcessor
		foreach ($settings as $key => $value) {
			if ($key === MetaFields::CANONICAL_URL || $key === MetaFields::REDIRECT_URL) {
				$normalized[$key] = is_array($value) ? (string) ($value['url'] ?? '') : (string) $value;
			} elseif ($key === MetaFields::OG_IMAGE || $key === MetaFields::X_IMAGE) {
				$normalized[$key] = is_array($value) ? (string) ($value['id'] ?? '') : (string) $value;
			} elseif ($key === MetaFields::OG_SYNC || $key === MetaFields::X_SYNC) {
				// Switcher in Elementor: 'yes' or '1' is on (present). Falsy/empty is off (absent in HTML checkbox terms).
				if ($value === 'yes' || $value === '1') {
					$normalized[$key] = '1';
				}
			} else {
				$normalized[$key] = $value;
			}
		}

		// Process via central field definitions
		$values = FieldProcessor::process(MetaFields::field_definitions(true), $normalized);

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

		// Refresh signals cache
		SeoSignals::persist($post_id);
	}
}
