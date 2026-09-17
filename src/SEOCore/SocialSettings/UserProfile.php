<?php

namespace Mihdan\IndexNow\SEOCore\SocialSettings;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

/**
 * Adds CrawlWP SEO social profile fields to the WordPress user profile / edit-user screen,
 * and outputs per-author Open Graph / Twitter Card meta overrides.
 *
 * Stored user meta keys:
 *   _crawlwp_facebook_author      — personal Facebook page URL
 *   _crawlwp_twitter_creator      — X (Twitter) @handle
 *   _crawlwp_additional_profiles  — additional profile URLs (one per line, used as sameAs)
 *
 * The author archive SEO fields deliberately reuse the post metabox keys, so a
 * value migrated by the importer and a value typed on this screen are the same
 * row — see {@see \Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput}:
 *   _crawlwp_seo_title        — SEO title for the author archive
 *   _crawlwp_seo_description  — meta description for the author archive
 *   _crawlwp_robots_index     — 'noindex' to hide the author archive, absent to inherit
 */
class UserProfile
{
	const META_FACEBOOK            = '_crawlwp_facebook_author';
	const META_TWITTER             = '_crawlwp_twitter_creator';
	const META_ADDITIONAL_PROFILES = '_crawlwp_additional_profiles';

	const META_SEO_TITLE       = MetaFields::SEO_TITLE;
	const META_SEO_DESCRIPTION = MetaFields::SEO_DESCRIPTION;
	const META_ROBOTS_INDEX    = MetaFields::ROBOTS_INDEX;
	const META_ROBOTS_FOLLOW   = MetaFields::ROBOTS_FOLLOW;
	const META_CANONICAL_URL   = MetaFields::CANONICAL_URL;

	public function __construct()
	{
		/* Render fields on the logged-in user's own profile page. */
		add_action('show_user_profile', [$this, 'render_fields']);

		/* Render fields when an admin edits another user's profile. */
		add_action('edit_user_profile', [$this, 'render_fields']);

		/* Save when the user updates their own profile. */
		add_action('personal_options_update', [$this, 'save_fields']);

		/* Save when an admin updates another user's profile. */
		add_action('edit_user_profile_update', [$this, 'save_fields']);
	}

	/**
	 * Output the CrawlWP SEO social profile section inside the user profile form.
	 *
	 * @param \WP_User $user The user whose profile is being edited.
	 */
	public function render_fields(\WP_User $user): void
	{
		$facebook   = (string) get_user_meta($user->ID, self::META_FACEBOOK, true);
		$twitter    = (string) get_user_meta($user->ID, self::META_TWITTER, true);
		$additional = (string) get_user_meta($user->ID, self::META_ADDITIONAL_PROFILES, true);

		$seo_title       = (string) get_user_meta($user->ID, self::META_SEO_TITLE, true);
		$seo_description = (string) get_user_meta($user->ID, self::META_SEO_DESCRIPTION, true);
		$noindex         = (string) get_user_meta($user->ID, self::META_ROBOTS_INDEX, true) === 'noindex';
		$nofollow        = (string) get_user_meta($user->ID, self::META_ROBOTS_FOLLOW, true) === 'nofollow';
		$canonical       = (string) get_user_meta($user->ID, self::META_CANONICAL_URL, true);
		?>
		<h2><?php esc_html_e('CrawlWP SEO — Social Profiles', 'mihdan-index-now'); ?></h2>
		<p class="description">
			<?php esc_html_e('These values override the site-wide fallbacks set in Advanced → Social Networks when your posts are shared on social media.', 'mihdan-index-now'); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="cwpwp_facebook_author"><?php esc_html_e('Facebook Page URL', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="cwpwp_facebook_author"
						name="<?php echo esc_attr(self::META_FACEBOOK); ?>"
						class="regular-text"
						value="<?php echo esc_attr($facebook); ?>"
						placeholder="https://www.facebook.com/YourPersonalProfile"
					/>
					<p class="description">
						<?php esc_html_e('Used as the article:author Open Graph tag on your posts. Overrides the site-wide fallback.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cwpwp_twitter_creator"><?php esc_html_e('X (Twitter) @handle', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="cwpwp_twitter_creator"
						name="<?php echo esc_attr(self::META_TWITTER); ?>"
						class="regular-text"
						value="<?php echo esc_attr($twitter); ?>"
						placeholder="@your-personal-username"
					/>
					<p class="description">
						<?php esc_html_e('Used as the twitter:creator meta tag on your posts. Overrides the site-wide fallback.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cwpwp_additional_profiles"><?php esc_html_e('Additional Profile URLs', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<textarea
						id="cwpwp_additional_profiles"
						name="<?php echo esc_attr(self::META_ADDITIONAL_PROFILES); ?>"
						class="large-text"
						rows="5"
						placeholder="https://www.linkedin.com/in/yourprofile
https://github.com/yourprofile"
					><?php echo esc_textarea($additional); ?></textarea>
					<p class="description">
						<?php esc_html_e('Additional profile URLs to include in the sameAs Schema property. Enter one URL per line.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
		</table>
		<h2><?php esc_html_e('CrawlWP SEO — Author Archive', 'mihdan-index-now'); ?></h2>
		<p class="description">
			<?php esc_html_e('These values override the global author archive templates set in Title & Meta → Author.', 'mihdan-index-now'); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="cwpwp_seo_title"><?php esc_html_e('SEO Title', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="cwpwp_seo_title"
						name="<?php echo esc_attr(self::META_SEO_TITLE); ?>"
						class="regular-text"
						value="<?php echo esc_attr($seo_title); ?>"
					/>
					<p class="description">
						<?php esc_html_e('Title used on this author\'s archive page. Leave empty to use the global author title template.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cwpwp_seo_description"><?php esc_html_e('Meta Description', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<textarea
						id="cwpwp_seo_description"
						name="<?php echo esc_attr(self::META_SEO_DESCRIPTION); ?>"
						class="large-text"
						rows="3"
					><?php echo esc_textarea($seo_description); ?></textarea>
					<p class="description">
						<?php esc_html_e('Meta description used on this author\'s archive page. Leave empty to use the global author description template.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('Search engine visibility', 'mihdan-index-now'); ?></th>
				<td>
					<label for="cwpwp_robots_noindex">
						<input
							type="checkbox"
							id="cwpwp_robots_noindex"
							name="<?php echo esc_attr(self::META_ROBOTS_INDEX); ?>"
							value="noindex"
							<?php checked($noindex); ?>
						/>
						<?php esc_html_e('Hide this author archive from search results', 'mihdan-index-now'); ?>
					</label>
					<p class="description">
						<?php esc_html_e('Adds a noindex robots directive to this author\'s archive page. When unchecked, the global author archive setting applies.', 'mihdan-index-now'); ?>
					</p>
					<label for="cwpwp_robots_nofollow">
						<input
							type="checkbox"
							id="cwpwp_robots_nofollow"
							name="<?php echo esc_attr(self::META_ROBOTS_FOLLOW); ?>"
							value="nofollow"
							<?php checked($nofollow); ?>
						/>
						<?php esc_html_e('Tell search engines not to follow links on this author archive', 'mihdan-index-now'); ?>
					</label>
					<p class="description">
						<?php esc_html_e('Adds a nofollow robots directive. When unchecked, the global author archive setting applies.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cwpwp_canonical_url"><?php esc_html_e('Canonical URL', 'mihdan-index-now'); ?></label>
				</th>
				<td>
					<input
						type="url"
						id="cwpwp_canonical_url"
						name="<?php echo esc_attr(self::META_CANONICAL_URL); ?>"
						class="regular-text"
						value="<?php echo esc_attr($canonical); ?>"
						placeholder="https://example.com/about/"
					/>
					<p class="description">
						<?php esc_html_e('Point this author archive at another URL. Leave empty to use the self-referencing canonical.', 'mihdan-index-now'); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the social profile fields when the profile form is submitted.
	 *
	 * @param int $user_id The ID of the user being saved.
	 */
	public function save_fields(int $user_id): void
	{
		if (! current_user_can('edit_user', $user_id)) {
			return;
		}

		if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-user_' . $user_id)) {
			return;
		}

		$facebook   = isset($_POST[self::META_FACEBOOK]) ? esc_url_raw(wp_unslash((string) $_POST[self::META_FACEBOOK])) : '';
		$twitter    = isset($_POST[self::META_TWITTER])  ? sanitize_text_field(wp_unslash((string) $_POST[self::META_TWITTER])) : '';
		/* Left raw here: every line is run through esc_url_raw() below, and
		   sanitize_textarea_field() would mangle otherwise valid URLs. */
		$additional = isset($_POST[self::META_ADDITIONAL_PROFILES]) ? wp_unslash((string) $_POST[self::META_ADDITIONAL_PROFILES]) : '';

		/* Ensure @handle always starts with @. */
		if ($twitter !== '' && strncmp($twitter, '@', 1) !== 0) {
			$twitter = '@' . ltrim($twitter, '@');
		}

		/* Sanitize each additional profile URL individually. */
		$additional_lines = array_filter(array_map('esc_url_raw', array_map('trim', explode("\n", $additional))));
		$additional       = implode("\n", $additional_lines);

		update_user_meta($user_id, self::META_FACEBOOK, $facebook);
		update_user_meta($user_id, self::META_TWITTER, $twitter);
		update_user_meta($user_id, self::META_ADDITIONAL_PROFILES, $additional);

		$this->save_seo_fields($user_id);
	}

	/**
	 * Persist the author archive SEO fields.
	 *
	 * Sanitisation mirrors the post metabox: a text field for the title and a
	 * textarea field for the description. Empty values and an unchecked noindex
	 * box delete their row instead of storing a blank/'index' value, so the
	 * author archive keeps inheriting the global template and robots setting.
	 *
	 * @param int $user_id The ID of the user being saved.
	 */
	private function save_seo_fields(int $user_id): void
	{
		$seo_title = isset($_POST[self::META_SEO_TITLE])
			? sanitize_text_field(wp_unslash((string) $_POST[self::META_SEO_TITLE]))
			: '';

		$seo_description = isset($_POST[self::META_SEO_DESCRIPTION])
			? sanitize_textarea_field(wp_unslash((string) $_POST[self::META_SEO_DESCRIPTION]))
			: '';

		self::save_optional($user_id, self::META_SEO_TITLE, $seo_title);
		self::save_optional($user_id, self::META_SEO_DESCRIPTION, $seo_description);

		$noindex = isset($_POST[self::META_ROBOTS_INDEX])
			&& sanitize_text_field(wp_unslash((string) $_POST[self::META_ROBOTS_INDEX])) === 'noindex';

		self::save_optional($user_id, self::META_ROBOTS_INDEX, $noindex ? 'noindex' : '');

		$nofollow = isset($_POST[self::META_ROBOTS_FOLLOW])
			&& sanitize_text_field(wp_unslash((string) $_POST[self::META_ROBOTS_FOLLOW])) === 'nofollow';

		self::save_optional($user_id, self::META_ROBOTS_FOLLOW, $nofollow ? 'nofollow' : '');

		$canonical = isset($_POST[self::META_CANONICAL_URL])
			? MetaFields::sanitize_url(wp_unslash((string) $_POST[self::META_CANONICAL_URL]))
			: '';

		self::save_optional($user_id, self::META_CANONICAL_URL, $canonical);
	}

	/**
	 * Store a user meta value, removing the row when the value is empty.
	 */
	private static function save_optional(int $user_id, string $meta_key, string $value): void
	{
		if ($value === '') {
			delete_user_meta($user_id, $meta_key);

			return;
		}

		update_user_meta($user_id, $meta_key, $value);
	}

	/**
	 * Read a social profile meta value for a given user.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $meta_key One of the META_* constants.
	 *
	 * @return string
	 */
	public static function get(int $user_id, string $meta_key): string
	{
		return (string) get_user_meta($user_id, $meta_key, true);
	}

	/**
	 * Return the additional profile URLs for a given user as an array.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string[]
	 */
	public static function get_additional_profiles(int $user_id): array
	{
		$raw = (string) get_user_meta($user_id, self::META_ADDITIONAL_PROFILES, true);

		if ($raw === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode("\n", $raw))));
	}
}
