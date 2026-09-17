<?php

namespace Mihdan\IndexNow\SEOCore\CoreSettings;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\SEOCore\TitleMeta\Entities;
use Mihdan\IndexNow\SEOCore\TitleMeta\Options;
use Mihdan\IndexNow\SEOCore\TitleMeta\Variables;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

class CoreSettings
{
	use SettingsFieldsTrait;

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 10, 2);

		add_action('crawlwp_pre_setup_fields', function ($wposa) {

			$wposa->add_header_menu([
				'id' => 'title_meta',
				'title' => __('Title & Meta', 'mihdan-index-now'),
			]);

			do_action('crawlwp_setup_fields_after_title_meta', $wposa, $this);
		}, -1);
	}

	public function settings_fields(WPOSA $wposa, $settingsInstance)
	{
		if ($wposa->get_active_header_menu() === Utils::get_plugin_prefix() . '_title_meta') {

			do_action('crawlwp_before_title_meta_settings_fields', $wposa);

			foreach (Entities::all() as $entity) {
				$this->register_entity($wposa, $entity);
			}

			do_action('crawlwp_after_title_meta_settings_fields', $wposa);
		}
	}

	/**
	 * Register one sidebar item, its section and every field on that screen.
	 */
	private function register_entity(WPOSA $wposa, array $entity): void
	{
		$key = $entity['key'];
		$section = Options::section_id($key);
		$groups = Entities::groups();

		$wposa->add_section([
			'header_menu_id' => 'title_meta',
			'id' => $section,
			'title' => $entity['label'],
			'nav_group' => $entity['group'],
			'nav_group_label' => $groups[$entity['group']] ?? '',
		]);

		if ($key === 'home') {
			$this->add_separator_field($wposa, $section);

			if (self::is_static_front_page()) {
				$this->add_static_home_notice($wposa, $section, $entity);
				return;
			}

			$this->add_noindex_field($wposa, $section, $entity);
		} else {
			$this->add_noindex_field($wposa, $section, $entity);
		}

		$this->add_heading($wposa, $section, 'heading_main', $entity['heading']);
		$this->add_template_fields($wposa, $section, $entity, '');
		$this->add_social_image_fields($wposa, $section, $entity, '');

		$this->add_social_override_fields($wposa, $section, $entity);
		$this->add_robots_fields($wposa, $section, $entity);

		if ($entity['type'] === Entities::TYPE_POST_TYPE) {
			$this->add_schema_field($wposa, $section, $entity);

			if (!empty($entity['archive'])) {
				$this->add_archive_fields($wposa, $section, $entity);
			}
		}
	}

	/**
	 * Site-wide title separator, surfaced on the Homepage screen.
	 */
	private function add_separator_field(WPOSA $wposa, string $section): void
	{
		$wposa->add_field($section, [
			'id' => 'separator',
			'type' => 'select',
			'name' => __('Title separator', 'mihdan-index-now'),
			'options' => Variables::separator_choices(),
			'default' => '-',
			'desc' => esc_html__('The character used wherever the {{ sep }} variable appears in a title.', 'mihdan-index-now'),
		]);
	}

	/**
	 * Whether a static page is set as the WordPress front page.
	 */
	public static function is_static_front_page(): bool
	{
		return 'page' === get_option('show_on_front') && (int) get_option('page_on_front') > 0;
	}

	/**
	 * When a static page is set as the homepage, inform the admin that the
	 * SEO title and description are configured directly on the page itself.
	 */
	private function add_static_home_notice(WPOSA $wposa, string $section, array $entity): void
	{
		$this->add_heading(
			$wposa,
			$section,
			'heading_main',
			__('Homepage', 'mihdan-index-now'),
			__('Determine how your homepage should look in the search results and on social media. This is what people probably will see when they search for your brand name.', 'mihdan-index-now')
		);

		$page_on_front_id = (int) get_option('page_on_front');
		$home_edit_url    = $page_on_front_id > 0 ? get_edit_post_link($page_on_front_id) : '';

		if (! $home_edit_url && $page_on_front_id > 0) {
			$home_edit_url = admin_url('post.php?post=' . $page_on_front_id . '&action=edit');
		}

		$page_for_posts_id = (int) get_option('page_for_posts');
		$posts_edit_url    = $page_for_posts_id > 0 ? get_edit_post_link($page_for_posts_id) : '';

		if (! $posts_edit_url && $page_for_posts_id > 0) {
			$posts_edit_url = admin_url('post.php?post=' . $page_for_posts_id . '&action=edit');
		}

		$home_link = $home_edit_url
			? sprintf('<a href="%s">%s</a>', esc_url($home_edit_url), esc_html__('editing the homepage itself', 'mihdan-index-now'))
			: esc_html__('editing the homepage itself', 'mihdan-index-now');

		$messages = [];
		$messages[] = sprintf(
			/* translators: %s: link to edit the homepage page */
			__('You can determine the title and description for the homepage by %s.', 'mihdan-index-now'),
			$home_link
		);

		if ($posts_edit_url) {
			$posts_link = sprintf('<a href="%s">%s</a>', esc_url($posts_edit_url), esc_html__('editing the blog page itself', 'mihdan-index-now'));
			$messages[] = sprintf(
				/* translators: %s: link to edit the blog page */
				__('You can determine the title and description for the blog page by %s.', 'mihdan-index-now'),
				$posts_link
			);
		}

		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';

		$paragraphs = '';
		foreach ($messages as $msg) {
			$paragraphs .= sprintf('<p>%s</p>', $msg);
		}

		$alert_html = sprintf(
			'<div class="cwp-tm-alert"><span class="cwp-tm-alert__icon" aria-hidden="true">%s</span><div class="cwp-tm-alert__content">%s</div></div>',
			$icon,
			$paragraphs
		);

		$wposa->add_field($section, [
			'id'    => 'static_home_notice',
			'type'  => 'html',
			'name'  => '',
			'desc'  => $alert_html,
			'class' => 'wposa-form-table__row cwp-tm-alert-row',
		]);
	}

	/**
	 * "Hide from search results" toggle.
	 */
	private function add_noindex_field(WPOSA $wposa, string $section, array $entity): void
	{
		$wposa->add_field($section, [
			'id' => 'noindex',
			'type' => 'switch',
			'name' => __('Hide from search results', 'mihdan-index-now'),
			'default' => Entities::default_value($entity['key'], 'noindex', 'off'),
			'desc' => $this->description($this->noindex_description($entity)),
		]);
	}

	/**
	 * Meta title + meta description template fields.
	 *
	 * @param string $prefix Field id prefix, e.g. `archive_`.
	 */
	private function add_template_fields(WPOSA $wposa, string $section, array $entity, string $prefix, ?string $entity_override = null): void
	{
		$wposa->add_field($section, [
			'id' => $prefix . 'title',
			'type' => 'text',
			'name' => __('Meta title', 'mihdan-index-now'),
			'default' => Entities::default_value($entity['key'], $prefix . 'title'),
			'attributes' => $this->template_attributes($entity['key'], $prefix . 'title', $entity_override),
		]);

		$wposa->add_field($section, [
			'id' => $prefix . 'description',
			'type' => 'textarea',
			'name' => __('Meta description', 'mihdan-index-now'),
			'default' => Entities::default_value($entity['key'], $prefix . 'description'),
			'rows' => 4,
			'attributes' => $this->template_attributes($entity['key'], $prefix . 'description', $entity_override),
		]);
	}

	/**
	 * Facebook + X share images.
	 */
	private function add_social_image_fields(WPOSA $wposa, string $section, array $entity, string $prefix): void
	{
		$featured_note = $entity['type'] === Entities::TYPE_POST_TYPE
			? ' ' . esc_html__('Leave empty to use the featured image.', 'mihdan-index-now')
			: '';

		$wposa->add_field($section, [
			'id' => $prefix . 'og_image',
			'type' => 'image',
			'name' => __('Social image (OG)', 'mihdan-index-now'),
			'desc' => esc_html__('Used as og:image for Facebook, LinkedIn and other Open Graph consumers. Recommended size: 1200×630 px (1.91:1 ratio, min 600 px wide).', 'mihdan-index-now') . $featured_note,
		]);

		$wposa->add_field($section, [
			'id' => $prefix . 'x_image',
			'type' => 'image',
			'name' => __('X/Twitter image', 'mihdan-index-now'),
			'desc' => esc_html__('Used as twitter:image. Recommended size: 1200×600 px (2:1 ratio, 300–4096 px wide). Falls back to the OG image when empty.', 'mihdan-index-now') . $featured_note,
		]);
	}

	/**
	 * Unified social title/description overrides (used for both OG and X/Twitter).
	 */
	private function add_social_override_fields(WPOSA $wposa, string $section, array $entity): void
	{
		$this->add_heading(
			$wposa,
			$section,
			'heading_social',
			__('Social overrides', 'mihdan-index-now'),
			__('Leave these empty to reuse the meta title and meta description above. Applied to all social networks via Open Graph and X/Twitter cards.', 'mihdan-index-now')
		);

		$wposa->add_field($section, [
			'id' => 'social_title',
			'type' => 'text',
			'name' => __('Social title', 'mihdan-index-now'),
			'attributes' => $this->template_attributes($entity['key'], 'social_title'),
		]);

		$wposa->add_field($section, [
			'id' => 'social_description',
			'type' => 'textarea',
			'name' => __('Social description', 'mihdan-index-now'),
			'rows' => 3,
			'attributes' => $this->template_attributes($entity['key'], 'social_description'),
		]);
	}

	/**
	 * Robots directives visible to the user (nofollow + noarchive only).
	 * All other directives use their built-in defaults in FrontendOutput.
	 */
	private function add_robots_fields(WPOSA $wposa, string $section, array $entity): void
	{
		$this->add_heading(
			$wposa,
			$section,
			'heading_robots',
			__('Robots directives', 'mihdan-index-now')
		);

		$wposa->add_field($section, [
			'id' => 'nofollow',
			'type' => 'switch',
			'name' => __('No follow', 'mihdan-index-now'),
			'default' => 'off',
			'desc' => $this->description(__('Tell search engines not to follow links on these pages.', 'mihdan-index-now')),
		]);

		$wposa->add_field($section, [
			'id' => 'noarchive',
			'type' => 'switch',
			'name' => __('No archive', 'mihdan-index-now'),
			'default' => 'off',
			'desc' => $this->description(__('Prevent search engines from showing a cached copy of these pages.', 'mihdan-index-now')),
		]);
	}

	/**
	 * Default schema.org types (page type + article type) for a post type.
	 */
	private function add_schema_field(WPOSA $wposa, string $section, array $entity): void
	{
		$this->add_heading($wposa, $section, 'heading_schema', __('Schema Structured data', 'mihdan-index-now'));

		$wposa->add_field($section, [
			'id' => 'schema_page_type',
			'type' => 'select',
			'name' => __('Page type', 'mihdan-index-now'),
			'default' => Entities::default_value($entity['key'], 'schema_page_type', 'WebPage'),
			'options' => [
				'WebPage' => __('Web Page', 'mihdan-index-now'),
				'ItemPage' => __('Item Page', 'mihdan-index-now'),
				'AboutPage' => __('About Page', 'mihdan-index-now'),
				'FAQPage' => __('FAQ Page', 'mihdan-index-now'),
				'QAPage' => __('Q&A Page', 'mihdan-index-now'),
				'ProfilePage' => __('Profile Page', 'mihdan-index-now'),
				'ContactPage' => __('Contact Page', 'mihdan-index-now'),
				'MedicalWebPage' => __('Medical Web Page', 'mihdan-index-now'),
				'CollectionPage' => __('Collection Page', 'mihdan-index-now'),
				'RealEstateListing' => __('Real Estate Listing', 'mihdan-index-now'),
				'none' => __('None — no structured data', 'mihdan-index-now'),
			],
			'desc' => esc_html__('Tells Google and other search engines what kind of page this is (for example, a regular page, an FAQ page, or a contact page), so they can understand and sometimes display it better in search results. If you also set an article type below, that choice takes priority over this one. Most sites can safely leave this on "Web Page".', 'mihdan-index-now'),
		]);

		$wposa->add_field($section, [
			'id' => 'schema_article_type',
			'type' => 'select',
			'name' => __('Article type', 'mihdan-index-now'),
			'default' => Entities::default_value($entity['key'], 'schema_article_type', 'Article'),
			'options' => [
				'Article' => __('Article', 'mihdan-index-now'),
				'BlogPosting' => __('Blog Posting', 'mihdan-index-now'),
				'SocialMediaPosting' => __('Social Media Posting', 'mihdan-index-now'),
				'NewsArticle' => __('News Article', 'mihdan-index-now'),
				'AdvertiserContentArticle' => __('Advertiser Content Article', 'mihdan-index-now'),
				'SatiricalArticle' => __('Satirical Article', 'mihdan-index-now'),
				'ScholarlyArticle' => __('Scholarly Article', 'mihdan-index-now'),
				'TechArticle' => __('Tech Article', 'mihdan-index-now'),
				'Report' => __('Report', 'mihdan-index-now'),
				'none' => __('None — use page type only', 'mihdan-index-now'),
			],
			'desc' => esc_html__('Choose this only if the content is written like an article — a blog post, news story, review, etc. It tells search engines to treat it as an article (helping show details like the author and publish date in search results) instead of a plain page, and it overrides the page type above when set. Choose "None" if this content isn\'t an article, so only the page type above is used.', 'mihdan-index-now'),
		]);
	}

	/**
	 * Archive listing defaults for a post type that has an archive.
	 */
	private function add_archive_fields(WPOSA $wposa, string $section, array $entity): void
	{
		$post_type = $entity['object'];
		$label = $post_type instanceof \WP_Post_Type ? $post_type->label : $entity['label'];

		$this->add_heading(
			$wposa,
			$section,
			'heading_archive',
			sprintf(
			/* translators: %s: post type plural label. */
				__('%s archive page', 'mihdan-index-now'),
				$label
			)
		);

		$wposa->add_field($section, [
			'id' => 'archive_noindex',
			'type' => 'switch',
			'name' => __('Hide from search results', 'mihdan-index-now'),
			'default' => 'off',
			'desc' => $this->description(__('This setting will apply the noindex robots tag to the archive listing page only.', 'mihdan-index-now')),
		]);

		$this->add_template_fields($wposa, $section, $entity, 'archive_', $entity['key'] . '_archive');
		$this->add_social_image_fields($wposa, $section, $entity, 'archive_');
	}

	/**
	 * Data attributes consumed by the live preview script.
	 *
	 * @param string $entity_key Entity key (e.g. `pt_post`).
	 * @param string $field Field id used as the `data-cwp-tm` value.
	 * @param string|null $entity_override Override the entity key sent to JS (used for archive sub-sections).
	 */
	private function template_attributes(string $entity_key, string $field, ?string $entity_override = null): array
	{
		return [
			'data-cwp-tm' => $field,
			'data-cwp-entity' => $entity_override !== null ? $entity_override : $entity_key,
		];
	}

	/**
	 * Context-aware copy for the "Hide from search results" toggle.
	 */
	private function noindex_description(array $entity): string
	{
		return match ($entity['type']) {
			Entities::TYPE_HOME      => __('This setting will apply the noindex robots tag to the homepage.', 'mihdan-index-now'),
			Entities::TYPE_POST_TYPE => __('This setting will apply the noindex robots tag to all posts of this post type and exclude the post type from the sitemap.', 'mihdan-index-now'),
			Entities::TYPE_TAXONOMY  => __('This setting will apply the noindex robots tag to every term archive of this taxonomy.', 'mihdan-index-now'),
			Entities::TYPE_AUTHOR    => __('This setting will apply the noindex robots tag to author pages.', 'mihdan-index-now'),
			Entities::TYPE_DATE      => __('This setting will apply the noindex robots tag to date based archives.', 'mihdan-index-now'),
			Entities::TYPE_SEARCH    => __('This setting will apply the noindex robots tag to search results pages. Keeping this enabled is recommended.', 'mihdan-index-now'),
			Entities::TYPE_NOT_FOUND => __('This setting will apply the noindex robots tag to the 404 page. Keeping this enabled is recommended.', 'mihdan-index-now'),
			default                  => __('This setting will apply the noindex robots tag to these pages.', 'mihdan-index-now'),
		};
	}
}
