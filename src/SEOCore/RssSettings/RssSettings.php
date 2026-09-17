<?php

namespace Mihdan\IndexNow\SEOCore\RssSettings;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * Registers the "RSS" settings section under the "Advanced" tab and
 * injects before/after content into the site's RSS/Atom feeds.
 *
 * Option row: crawlwp_rss
 * Fields: rss_before_content, rss_after_content
 *
 * Supported variables (resolved at render time):
 *   {{ authorlink }}     – Link to the author archive, anchor = author display name.
 *   {{ postlink }}       – Link to the post, anchor = post title.
 *   {{ bloglink }}       – Link to the site home, anchor = site name.
 *   {{ blogdesclink }}   – Link to the site home, anchor = "site name – tagline".
 */
class RssSettings
{
	use SettingsFieldsTrait;

	/** Option/section id (without the crawlwp_ prefix). */
	const SECTION = 'rss';

	/** Default content appended after each RSS item. */
	const DEFAULT_AFTER = 'The post {{ postlink }} appeared first on {{ bloglink }}.';

	public function __construct()
	{
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 20, 2);

		/* Inject content into all feed types. */
		add_filter('the_content_feed',  [$this, 'inject_content'], 10, 2);
		add_filter('the_excerpt_rss',   [$this, 'inject_excerpt'], 10, 1);

		/* Variable inserter UI on admin screens. */
		add_action('admin_footer', [$this, 'print_inserter_js']);
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
			'title'          => __('RSS', 'mihdan-index-now'),
		]);

		$this->add_feed_fields($wposa);
	}

	// -------------------------------------------------------------------------
	// Fields
	// -------------------------------------------------------------------------

	private function add_feed_fields(WPOSA $wposa): void
	{
		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_rss_feed',
			__('RSS feed', 'mihdan-index-now'),
			__('Automatically add content to your RSS. This enables you to add links back to your blog and your blog posts, helping search engines identify you as the original source of the content.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'         => 'rss_before_content',
			'type'       => 'textarea',
			'name'       => __('Content to put before each post in the feed', 'mihdan-index-now'),
			'rows'       => 5,
			'attributes' => ['data-cwp-rss' => 'before'],
		]);

		$wposa->add_field(self::SECTION, [
			'id'         => 'rss_after_content',
			'type'       => 'textarea',
			'name'       => __('Content to put after each post in the feed', 'mihdan-index-now'),
			'rows'       => 5,
			'default'    => self::DEFAULT_AFTER,
			'attributes' => ['data-cwp-rss' => 'after'],
		]);
	}

	// -------------------------------------------------------------------------
	// Variable inserter UI
	// -------------------------------------------------------------------------

	/**
	 * Print a lightweight JS variable-inserter for the RSS textarea fields.
	 * Only active when [data-cwp-rss] textareas are present on the page.
	 */
	public function print_inserter_js(): void
	{
		$screen = get_current_screen();

		if (! $screen) {
			return;
		}

		/* Only emit on pages that contain our RSS settings section. */
		if (strpos($screen->id, 'crawlwp') === false) {
			return;
		}

		$variables = [
			[
				'token' => 'authorlink',
				'desc'  => __('A link to the author archive, with author name as anchor text.', 'mihdan-index-now'),
			],
			[
				'token' => 'postlink',
				'desc'  => __('A link to the post, with the title as anchor text.', 'mihdan-index-now'),
			],
			[
				'token' => 'bloglink',
				'desc'  => __('A link to your site, with your site name as anchor text.', 'mihdan-index-now'),
			],
			[
				'token' => 'blogdesclink',
				'desc'  => __('A link to your site, with site name and description as anchor text.', 'mihdan-index-now'),
			],
		];

		$i18n = [
			'insertVariable'  => __('Insert variable', 'mihdan-index-now'),
			'searchVariables' => __('Search variables…', 'mihdan-index-now'),
			'noVariables'     => __('No matching variables.', 'mihdan-index-now'),
		];

		?>
		<script id="cwp-rss-inserter-js">
		(function() {
			'use strict';

			var VARS  = <?php echo wp_json_encode($variables); ?>;
			var I18N  = <?php echo wp_json_encode($i18n); ?>;

			/* ----------------------------------------------------------------
			 * Helpers
			 * -------------------------------------------------------------- */

			function insertAtCaret(el, text) {
				var start = el.selectionStart;
				var end   = el.selectionEnd;
				var val   = el.value;
				el.value  = val.slice(0, start) + text + val.slice(end);
				el.selectionStart = el.selectionEnd = start + text.length;
				el.focus();
				el.dispatchEvent(new Event('input', { bubbles: true }));
			}

			var openPanel = null;

			function closePanel() {
				if (openPanel) {
					openPanel.panel.style.display = 'none';
					openPanel.trigger.setAttribute('aria-expanded', 'false');
					openPanel = null;
				}
			}

			/* ----------------------------------------------------------------
			 * Build a dropdown panel (lazy, once per textarea).
			 * -------------------------------------------------------------- */

			function buildPanel(trigger, textarea) {
				var panel = document.createElement('div');
				panel.className = 'cwp-rss-panel';
				panel.style.display = 'none';

				var search = document.createElement('input');
				search.type = 'search';
				search.className = 'cwp-rss-panel__search';
				search.placeholder = I18N.searchVariables;
				panel.appendChild(search);

				var list = document.createElement('div');
				list.className = 'cwp-rss-panel__list';
				panel.appendChild(list);

				function renderList(filter) {
					list.innerHTML = '';
					var f = (filter || '').toLowerCase();
					var shown = 0;

					VARS.forEach(function(v) {
						if (f && v.token.toLowerCase().indexOf(f) === -1 && v.desc.toLowerCase().indexOf(f) === -1) {
							return;
						}
						shown++;

					var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'cwp-rss-panel__item';
						var displayToken = '{{ ' + v.token + ' }}';
						btn.innerHTML = '<code>' + displayToken + '</code><span>' + v.desc + '</span>';

						btn.addEventListener('click', function() {
							insertAtCaret(textarea, '{{ ' + v.token + ' }}');
							closePanel();
						});

						list.appendChild(btn);
					});

					if (!shown) {
						var empty = document.createElement('p');
						empty.className = 'cwp-rss-panel__empty';
						empty.textContent = I18N.noVariables;
						list.appendChild(empty);
					}
				}

				renderList('');

				search.addEventListener('input', function() { renderList(this.value); });

				/* Insert the panel after the textarea's wrapper. */
				var wrapper = textarea.closest('.cwp-rss-field') || textarea.parentNode;
				wrapper.style.position = 'relative';
				wrapper.appendChild(panel);

				return { panel: panel, search: search };
			}

			/* ----------------------------------------------------------------
			 * Initialise each RSS textarea.
			 * -------------------------------------------------------------- */

			function init() {
				var textareas = document.querySelectorAll('textarea[data-cwp-rss]');
				if (!textareas.length) { return; }

				textareas.forEach(function(ta) {
					if (ta.dataset.cwpRssReady) { return; }
					ta.dataset.cwpRssReady = '1';

					/* Wrap textarea so we can position the trigger button. */
					var wrapper = document.createElement('div');
					wrapper.className = 'cwp-rss-field';
					ta.parentNode.insertBefore(wrapper, ta);
					wrapper.appendChild(ta);

					/* Trigger button. */
					var trigger = document.createElement('button');
					trigger.type = 'button';
					trigger.className = 'cwp-rss-vars';
					trigger.title = I18N.insertVariable;
					trigger.setAttribute('aria-haspopup', 'true');
					trigger.setAttribute('aria-expanded', 'false');
					trigger.textContent = '\u2026'; /* ellipsis */
					wrapper.appendChild(trigger);

					var built = null;

					trigger.addEventListener('click', function(e) {
						e.stopPropagation();

						if (openPanel && openPanel.trigger === trigger) {
							closePanel();
							return;
						}

						closePanel();

						if (!built) {
							built = buildPanel(trigger, ta);
						}

						built.panel.style.display = 'block';
						trigger.setAttribute('aria-expanded', 'true');
						built.search.value = '';
						built.search.focus();
						openPanel = { panel: built.panel, trigger: trigger };
					});
				});

				/* Close on outside click or Escape. */
				document.addEventListener('click', function() { closePanel(); });
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') { closePanel(); }
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		}());
		</script>
		<style id="cwp-rss-inserter-css">
			.cwp-rss-field {
				position: relative;
				display: block;
				max-width: 640px;
			}
			.cwp-rss-field textarea {
				width: 100%;
				padding-right: 34px;
				box-sizing: border-box;
			}
			.cwp-rss-vars {
				position: absolute;
				top: 6px;
				right: 6px;
				background: transparent;
				border: 1px solid transparent;
				border-radius: 3px;
				padding: 2px 6px;
				cursor: pointer;
				color: #646970;
				font-size: 16px;
				line-height: 1;
				letter-spacing: 2px;
			}
			.cwp-rss-vars:hover,
			.cwp-rss-vars:focus {
				color: var(--wp-admin-theme-color);
				background: #f0f0f1;
				border-color: #c3c4c7;
			}
			.cwp-rss-panel {
				position: absolute;
				right: 0;
				top: 100%;
				z-index: 200;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				box-shadow: 0 2px 8px rgba(0,0,0,.12);
				width: 380px;
				max-width: 90vw;
				max-height: 280px;
				overflow-y: auto;
				padding: 8px;
			}
			.cwp-rss-panel__search {
				width: 100%;
				margin-bottom: 6px;
				box-sizing: border-box;
			}
			.cwp-rss-panel__item {
				display: flex;
				flex-direction: column;
				gap: 2px;
				width: 100%;
				text-align: left;
				background: transparent;
				border: none;
				border-radius: 3px;
				padding: 6px 8px;
				cursor: pointer;
			}
			.cwp-rss-panel__item:hover,
			.cwp-rss-panel__item:focus {
				background: #f0f0f1;
			}
			.cwp-rss-panel__item code {
				color: var(--wp-admin-theme-color);
				font-size: 12px;
			}
			.cwp-rss-panel__item span {
				color: #646970;
				font-size: 11px;
			}
			.cwp-rss-panel__empty {
				padding: 6px 8px;
				color: #646970;
				font-size: 12px;
				margin: 0;
			}
		</style>
		<?php
	}

	// -------------------------------------------------------------------------
	// Feed content injection
	// -------------------------------------------------------------------------

	/**
	 * Prepend/append configured content to full-content feed items.
	 *
	 * @param string $content  The post content for the feed.
	 * @param string $feed_type  The feed type (rss2, atom, rdf, etc.).
	 * @return string
	 */
	public function inject_content(string $content, string $feed_type): string
	{
		$before = $this->resolve(self::get('rss_before_content', ''));
		$after  = $this->resolve(self::get('rss_after_content', self::DEFAULT_AFTER));

		/**
		 * Filter the HTML prepended to each RSS feed item.
		 *
		 * @param string       $before    The resolved before-content HTML.
		 * @param \WP_Post|null $post     The current post in The Loop.
		 */
		$before = (string) apply_filters('crawlwp_rss_content_before', $before, get_post());

		/**
		 * Filter the HTML appended to each RSS feed item.
		 *
		 * @param string        $after  The resolved after-content HTML.
		 * @param \WP_Post|null $post   The current post in The Loop.
		 */
		$after = (string) apply_filters('crawlwp_rss_content_after', $after, get_post());

		if ($before !== '') {
			$content = '<p>' . $before . '</p>' . $content;
		}

		if ($after !== '') {
			$content = $content . '<p>' . $after . '</p>';
		}

		return $content;
	}

	/**
	 * Prepend/append configured content to excerpt feed items.
	 *
	 * @param string $excerpt  The post excerpt for the feed.
	 * @return string
	 */
	public function inject_excerpt(string $excerpt): string
	{
		$before = $this->resolve(self::get('rss_before_content', ''));
		$after  = $this->resolve(self::get('rss_after_content', self::DEFAULT_AFTER));

		/** This filter is documented in inject_content(). */
		$before = (string) apply_filters('crawlwp_rss_content_before', $before, get_post());

		/** This filter is documented in inject_content(). */
		$after = (string) apply_filters('crawlwp_rss_content_after', $after, get_post());

		if ($before !== '') {
			$excerpt = '<p>' . $before . '</p>' . $excerpt;
		}

		if ($after !== '') {
			$excerpt = $excerpt . '<p>' . $after . '</p>';
		}

		return $excerpt;
	}

	/**
	 * Resolve {{ variable }} placeholders using the current post in The Loop.
	 *
	 * @param string $template  The raw template string.
	 * @return string
	 */
	private function resolve(string $template): string
	{
		if ($template === '') {
			return '';
		}

		/*
		 * The template comes straight from an admin textarea, so strip anything
		 * that does not belong in a feed item (scripts, iframes, event handlers,
		 * …) before the placeholders are resolved.
		 */
		$template = $this->sanitize_template($template);

		if ($template === '') {
			return '';
		}

		$post = get_post();

		if (! $post) {
			return $template;
		}

		$site_url  = home_url('/');
		$site_name = get_bloginfo('name');
		$site_desc = get_bloginfo('description');

		/* {{ bloglink }} — anchor is the site name. */
		$blog_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url($site_url),
			esc_html($site_name)
		);

		/* {{ blogdesclink }} — anchor is "site name – tagline". */
		$blog_anchor = $site_desc !== ''
			? $site_name . ' &ndash; ' . $site_desc
			: $site_name;

		$blog_desc_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url($site_url),
			wp_kses($blog_anchor, [])
		);

		/* {{ postlink }} — anchor is the post title. */
		$post_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url(get_permalink($post)),
			esc_html(get_the_title($post))
		);

		/* {{ authorlink }} — anchor is the author display name. */
		$author_url  = get_author_posts_url((int) $post->post_author);
		$author_name = get_the_author_meta('display_name', (int) $post->post_author);
		$author_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url($author_url),
			esc_html($author_name)
		);

		/*
		 * Support both space-padded {{ token }} and unpadded {{token}} forms,
		 * matching the plugin's standard variable syntax.
		 *
		 * Use preg_replace_callback (not preg_replace with a string map) so that
		 * HTML replacement values containing '$' (e.g. URLs with query strings)
		 * are never misinterpreted as regex backreferences.
		 */
		$replacements = [
			'authorlink'   => $author_link,
			'postlink'     => $post_link,
			'bloglink'     => $blog_link,
			'blogdesclink' => $blog_desc_link,
		];

		return preg_replace_callback(
			'/\{\{\s*(' . implode('|', array_map('preg_quote', array_keys($replacements))) . ')\s*\}\}/i',
			function ($m) use ($replacements) {
				return $replacements[ strtolower($m[1]) ] ?? $m[0];
			},
			$template
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Run the admin-supplied template through a light wp_kses() pass.
	 *
	 * Standard links and inline formatting are kept; everything else (script,
	 * iframe, style, event handler attributes, …) is removed.
	 *
	 * @param string $template The raw template string.
	 *
	 * @return string
	 */
	private function sanitize_template(string $template): string
	{
		$allowed = [
			'a'      => [
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'u'      => [],
			'span'   => [],
			'p'      => [],
			'br'     => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'small'  => [],
			'code'   => [],
			'blockquote' => [],
		];

		/**
		 * Filter the HTML allowed in the RSS before/after content templates.
		 *
		 * @param array $allowed Allowed tags in wp_kses() format.
		 */
		$allowed = (array) apply_filters('crawlwp_rss_allowed_html', $allowed);

		return trim(wp_kses($template, $allowed));
	}

	/**
	 * Read an RSS setting value.
	 *
	 * @param string $field   Field id (e.g. 'rss_before_content').
	 * @param mixed  $default Default value when option is absent.
	 *
	 * @return mixed
	 */
	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		if (! is_array($options)) {
			return $default;
		}

		return $options[$field] ?? $default;
	}
}
