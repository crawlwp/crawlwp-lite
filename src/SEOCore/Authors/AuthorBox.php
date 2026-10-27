<?php

namespace Mihdan\IndexNow\SEOCore\Authors;

use Mihdan\IndexNow\SEOCore\SettingsFieldsTrait;
use Mihdan\IndexNow\Utils;
use Mihdan\IndexNow\Views\WPOSA;

/**
 * The "Authors" settings section and the optional author box shown after posts.
 *
 * Off by default. The box uses the theme's fonts and colours, and can also be
 * placed anywhere with the [crawlwp_author_box] shortcode.
 *
 * Option row: crawlwp_authors
 */
class AuthorBox
{
	use SettingsFieldsTrait;

	public const SECTION = 'authors';

	private const STYLE = 'crawlwp-author-box';

	public function __construct()
	{
		/* Priority 6: right after Site Information. */
		add_action('crawlwp_setup_fields', [$this, 'settings_fields'], 6, 2);

		add_action('wp_enqueue_scripts', [$this, 'enqueue']);
		add_filter('the_content', [$this, 'append'], 20);
		add_shortcode('crawlwp_author_box', [$this, 'shortcode']);
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public function settings_fields(WPOSA $wposa, $settingsInstance = null): void
	{
		if ($wposa->get_active_header_menu() !== Utils::get_plugin_prefix() . '_advanced_settings') {
			return;
		}

		$wposa->add_section([
			'header_menu_id' => 'advanced_settings',
			'id'             => self::SECTION,
			'title'          => __('Authors', 'mihdan-index-now'),
			'desc'           => sprintf(
				/* translators: %s: link to the user's profile screen */
				__('Each author fills in their job title, expertise and credentials on their %s. CrawlWP adds them to the structured data of their posts, so search engines and AI tools know who wrote the content.', 'mihdan-index-now'),
				'<a href="' . esc_url(admin_url('profile.php#crawlwp-author-profile')) . '">' . esc_html__('profile', 'mihdan-index-now') . '</a>'
			),
		]);

		$this->add_heading(
			$wposa,
			self::SECTION,
			'heading_author_box',
			__('Author box', 'mihdan-index-now'),
			__('Show who wrote the post at the end of it: photo, name, job title, bio, expertise and profile links. It uses your theme\'s fonts and colours.', 'mihdan-index-now')
		);

		$wposa->add_field(self::SECTION, [
			'id'      => 'author_box',
			'type'    => 'switch',
			'name'    => __('Show an author box after posts', 'mihdan-index-now'),
			'default' => 'off',
		]);

		$wposa->add_field(self::SECTION, [
			'id'                => 'author_box_types',
			'type'              => 'multicheck',
			'name'              => __('Show on', 'mihdan-index-now'),
			'default'           => ['post' => 'post'],
			'options'           => self::post_type_options(),
			'sanitize_callback' => [__CLASS__, 'sanitize_types'],
			'desc'              => sprintf(
				/* translators: %s: shortcode */
				esc_html__('To place the box somewhere else, turn this off and use the %s shortcode.', 'mihdan-index-now'),
				'<code>[crawlwp_author_box]</code>'
			),
		]);

		$wposa->add_field(self::SECTION, [
			'id'          => 'author_box_title',
			'type'        => 'text',
			'name'        => __('Heading', 'mihdan-index-now'),
			'placeholder' => __('About the author', 'mihdan-index-now'),
			'desc'        => esc_html__('Small heading above the author\'s name. Leave empty for "About the author".', 'mihdan-index-now'),
		]);
	}

	/**
	 * @return array<string, string> Post type => label.
	 */
	private static function post_type_options(): array
	{
		$options = [];

		foreach (get_post_types(['public' => true], 'objects') as $type) {
			if ($type->name === 'attachment' || ! post_type_supports($type->name, 'author')) {
				continue;
			}

			$options[$type->name] = $type->labels->name;
		}

		return $options;
	}

	/**
	 * Keep only real post types.
	 *
	 * @param mixed $value
	 *
	 * @return array<string, string>
	 */
	public static function sanitize_types($value): array
	{
		$clean = [];

		foreach ((array) $value as $type) {
			$type = sanitize_key((string) $type);

			if ($type !== '' && post_type_exists($type)) {
				$clean[$type] = $type;
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public static function get(string $field, $default = '')
	{
		$options = get_option('crawlwp_' . self::SECTION, []);

		return is_array($options) && isset($options[$field]) ? $options[$field] : $default;
	}

	// -------------------------------------------------------------------------
	// Front end
	// -------------------------------------------------------------------------

	/**
	 * Whether the automatic box belongs after this post.
	 */
	public static function shows_after(\WP_Post $post): bool
	{
		$show = self::get('author_box', 'off') === 'on'
			&& in_array($post->post_type, array_keys((array) self::get('author_box_types', ['post' => 'post'])), true)
			&& (int) $post->post_author > 0;

		/**
		 * Filter whether the author box is added after a post's content.
		 *
		 * @param bool     $show Whether to show it.
		 * @param \WP_Post $post The post.
		 */
		return (bool) apply_filters('crawlwp_author_box_show', $show, $post);
	}

	public function enqueue(): void
	{
		wp_register_style(self::STYLE, false, [], CRAWLWP_VERSION);
		wp_add_inline_style(self::STYLE, self::css());

		$post = get_queried_object();

		if (is_singular() && $post instanceof \WP_Post && self::shows_after($post)) {
			wp_enqueue_style(self::STYLE);
		}
	}

	/**
	 * Add the box after the main post's content.
	 *
	 * @param mixed $content
	 *
	 * @return mixed
	 */
	public function append($content)
	{
		if (! is_singular() || ! in_the_loop() || ! is_main_query() || ! is_string($content)) {
			return $content;
		}

		$post = get_post();

		if (! $post instanceof \WP_Post || $post->ID !== get_queried_object_id() || post_password_required($post) || ! self::shows_after($post)) {
			return $content;
		}

		/* The post places the box itself. */
		if (has_shortcode($post->post_content, 'crawlwp_author_box')) {
			return $content;
		}

		return $content . self::render((int) $post->post_author);
	}

	/**
	 * [crawlwp_author_box] — the current post's author, or [crawlwp_author_box user="12"].
	 *
	 * @param array|string $atts
	 */
	public function shortcode($atts): string
	{
		$atts   = shortcode_atts(['user' => 0], (array) $atts, 'crawlwp_author_box');
		$post   = get_post();
		$author = $post instanceof \WP_Post ? (int) $post->post_author : 0;
		$user   = (int) $atts['user'];

		/*
		 * Another user's box only in posts by editors and admins, and only for
		 * people who write: a subscriber's or customer's name and photo stay private.
		 */
		if ($user > 0 && $user !== $author && (! user_can($author, 'edit_others_posts') || ! user_can($user, 'edit_posts'))) {
			return '';
		}

		return self::render($user > 0 ? $user : $author);
	}

	/**
	 * The author box HTML, '' when the user doesn't exist.
	 */
	public static function render(int $user_id): string
	{
		$author = Author::get($user_id);

		if ($author === null || $author['name'] === '') {
			return '';
		}

		/* Late enqueue for the shortcode; printed in the footer if the head is gone. */
		wp_enqueue_style(self::STYLE);

		$heading = trim((string) self::get('author_box_title', ''));

		if ($heading === '') {
			$heading = __('About the author', 'mihdan-index-now');
		}

		$photo = $author['photo_id'] > 0
			? wp_get_attachment_image($author['photo_id'], 'thumbnail', false, ['class' => 'crawlwp-author-box__photo', 'alt' => '', 'loading' => 'lazy'])
			: (string) get_avatar($user_id, 160, '', '', ['class' => 'crawlwp-author-box__photo', 'loading' => 'lazy']);

		$role = '';
		$org  = is_array($author['works_for']) ? (string) $author['works_for']['name'] : '';

		if ($author['job_title'] !== '' && $org !== '') {
			/* translators: 1: job title, 2: organisation */
			$role = sprintf(__('%1$s at %2$s', 'mihdan-index-now'), $author['job_title'], $org);
		} elseif ($author['job_title'] !== '') {
			$role = $author['job_title'];
		}

		$name = $author['url'] !== ''
			? sprintf('<a href="%s">%s</a>', esc_url($author['url']), esc_html($author['name']))
			: esc_html($author['name']);

		$links = [];

		foreach ($author['same_as'] as $url) {
			$links[] = sprintf('<a href="%s" rel="me noopener" target="_blank">%s</a>', esc_url($url), esc_html(self::link_label($url)));
		}

		if ($author['url'] !== '') {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url($author['url']),
				/* translators: %s: author's name */
				esc_html(sprintf(__('All posts by %s →', 'mihdan-index-now'), $author['name']))
			);
		}

		/* The bio may hold the few tags WordPress allows there. */
		$bio = trim((string) get_user_meta($user_id, 'description', true));

		ob_start();
		?>
		<section class="crawlwp-author-box" aria-label="<?php echo esc_attr($heading); ?>">
			<?php echo $photo; // phpcs:ignore WordPress.Security.EscapeOutput -- core image/avatar markup ?>
			<div class="crawlwp-author-box__body">
				<p class="crawlwp-author-box__heading"><?php echo esc_html($heading); ?></p>
				<p class="crawlwp-author-box__name"><?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></p>
				<?php if ($role !== '') : ?>
					<p class="crawlwp-author-box__role"><?php echo esc_html($role); ?></p>
				<?php endif; ?>
				<?php if ($bio !== '') : ?>
					<div class="crawlwp-author-box__bio"><?php echo wp_kses_post(wpautop($bio)); ?></div>
				<?php endif; ?>
				<?php if ($author['expertise'] !== []) : ?>
					<p class="crawlwp-author-box__expertise"><strong><?php esc_html_e('Expertise:', 'mihdan-index-now'); ?></strong> <?php echo esc_html(implode(', ', $author['expertise'])); ?></p>
				<?php endif; ?>
				<?php if ($links !== []) : ?>
					<p class="crawlwp-author-box__links"><?php echo implode(' ', $links); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
		$html = (string) ob_get_clean();

		/* Strip the template's indentation (any run with a tab or line break) so themes don't add stray <p>s. Plain spaces stay. */
		$html = trim((string) preg_replace('/> *[\t\r\n]\s*</', '><', $html));

		/**
		 * Filter the author box HTML.
		 *
		 * @param string $html    The box.
		 * @param int    $user_id User ID.
		 * @param array  $author  Author details from {@see Author::get()}.
		 */
		return (string) apply_filters('crawlwp_author_box_html', $html, $user_id, $author);
	}

	/**
	 * A readable name for a profile link: "LinkedIn", "GitHub", or the domain.
	 */
	public static function link_label(string $url): string
	{
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$host = preg_replace('/^(www|m|mobile)\./', '', $host);

		$known = [
			'linkedin.com'  => 'LinkedIn',
			'x.com'         => 'X',
			'twitter.com'   => 'X',
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'youtube.com'   => 'YouTube',
			'github.com'    => 'GitHub',
			'tiktok.com'    => 'TikTok',
			'threads.net'   => 'Threads',
			'bsky.app'      => 'Bluesky',
			'pinterest.com' => 'Pinterest',
			'medium.com'    => 'Medium',
			'wikipedia.org' => 'Wikipedia',
			'scholar.google.com' => 'Google Scholar',
			'orcid.org'     => 'ORCID',
		];

		foreach ($known as $domain => $label) {
			if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
				return $label;
			}
		}

		return $host !== '' ? $host : $url;
	}

	private static function css(): string
	{
		/* "p.class" so each line's spacing beats theme rules for paragraphs. */
		return '.crawlwp-author-box{display:flex;gap:20px;align-items:flex-start;margin:2em 0;padding:24px;border:1px solid rgba(127,127,127,.3);border-radius:12px}'
			. '.crawlwp-author-box .crawlwp-author-box__photo{flex:0 0 auto;width:80px;height:80px;margin:0;border-radius:50%;object-fit:cover}'
			. '.crawlwp-author-box__body{flex:1;min-width:0}'
			. '.crawlwp-author-box p.crawlwp-author-box__heading{margin:0;font-size:.8em;opacity:.7;text-transform:uppercase;letter-spacing:.06em}'
			. '.crawlwp-author-box p.crawlwp-author-box__name{margin:4px 0 2px;font-size:1.25em;font-weight:600;line-height:1.3}'
			. '.crawlwp-author-box__name a{color:inherit;text-decoration:none}'
			. '.crawlwp-author-box p.crawlwp-author-box__role{margin:0;font-size:.9em;opacity:.8}'
			. '.crawlwp-author-box .crawlwp-author-box__bio{margin:10px 0 0}'
			. '.crawlwp-author-box .crawlwp-author-box__bio p{margin:0 0 10px}'
			. '.crawlwp-author-box .crawlwp-author-box__bio p:last-child{margin-bottom:0}'
			. '.crawlwp-author-box p.crawlwp-author-box__expertise{margin:10px 0 0;font-size:.9em}'
			. '.crawlwp-author-box p.crawlwp-author-box__links{display:flex;flex-wrap:wrap;gap:6px 14px;margin:12px 0 0;font-size:.9em}'
			. '@media (max-width:600px){.crawlwp-author-box{flex-direction:column;padding:18px}}';
	}
}
