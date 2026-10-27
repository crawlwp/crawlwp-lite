<?php

namespace Mihdan\IndexNow\SEOCore\Authors;

/**
 * The "CrawlWP SEO — Author Profile" section of the user profile screen.
 *
 * Every field is optional. The values are used in the Person schema of the
 * user's posts and in the author box, see {@see Author}.
 */
class AuthorProfile
{
	public function __construct()
	{
		/* Priority 9: above the existing "Social Profiles" section. */
		add_action('show_user_profile', [$this, 'render'], 9);
		add_action('edit_user_profile', [$this, 'render'], 9);

		add_action('personal_options_update', [$this, 'save']);
		add_action('edit_user_profile_update', [$this, 'save']);

		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
	}

	public function enqueue(string $hook): void
	{
		if (! in_array($hook, ['profile.php', 'user-edit.php'], true) || ! current_user_can('upload_files')) {
			return;
		}

		$user_id = $hook === 'user-edit.php' && isset($_GET['user_id']) ? absint($_GET['user_id']) : get_current_user_id();

		if (! user_can($user_id, 'edit_posts')) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'crawlwp-author-profile',
			CRAWLWP_PLUGIN_URL . 'src/SEOCore/Authors/assets/author-profile.js',
			['jquery'],
			CRAWLWP_VERSION,
			true
		);

		wp_localize_script('crawlwp-author-profile', 'crawlwpAuthorProfile', [
			'title'  => __('Choose a profile photo', 'mihdan-index-now'),
			'button' => __('Use this photo', 'mihdan-index-now'),
		]);
	}

	public function render(\WP_User $user): void
	{
		/* Only people who can write posts have anything to show it on. */
		if (! user_can($user, 'edit_posts')) {
			return;
		}

		$job_title   = (string) get_user_meta($user->ID, Author::META_JOB_TITLE, true);
		$works_for   = (string) get_user_meta($user->ID, Author::META_WORKS_FOR, true);
		$org_name    = (string) get_user_meta($user->ID, Author::META_WORKS_FOR_NAME, true);
		$org_url     = (string) get_user_meta($user->ID, Author::META_WORKS_FOR_URL, true);
		$expertise   = (string) get_user_meta($user->ID, Author::META_EXPERTISE, true);
		$credentials = (string) get_user_meta($user->ID, Author::META_CREDENTIALS, true);
		$photo_id    = (int) get_user_meta($user->ID, Author::META_PHOTO, true);
		$photo_url   = $photo_id > 0 ? (string) wp_get_attachment_image_url($photo_id, 'thumbnail') : '';
		$is_org      = Author::site_is_organization();
		$can_upload  = current_user_can('upload_files');

		/* Nobody until the author says otherwise: an employer is a claim only they can make. */
		if ($works_for === '' || ($works_for === 'site' && ! $is_org)) {
			$works_for = 'none';
		}

		wp_nonce_field('crawlwp_author_profile', '_crawlwp_author_nonce');
		?>
		<h2 id="crawlwp-author-profile"><?php esc_html_e('CrawlWP SEO — Author Profile', 'mihdan-index-now'); ?></h2>
		<p class="description" style="max-width:720px">
			<?php esc_html_e('Tells Google who wrote your posts and why they know the subject (E-E-A-T: experience, expertise, authoritativeness and trust). Used in your posts\' structured data and in the author box. Everything is optional.', 'mihdan-index-now'); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cwp_author_job_title"><?php esc_html_e('Job title', 'mihdan-index-now'); ?></label></th>
				<td>
					<input type="text" id="cwp_author_job_title" name="<?php echo esc_attr(Author::META_JOB_TITLE); ?>" class="regular-text" value="<?php echo esc_attr($job_title); ?>">
					<p class="description"><?php esc_html_e('For example: Registered dietitian, Senior developer, Head chef.', 'mihdan-index-now'); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cwp_author_works_for"><?php esc_html_e('Works for', 'mihdan-index-now'); ?></label></th>
				<td>
					<select id="cwp_author_works_for" name="<?php echo esc_attr(Author::META_WORKS_FOR); ?>">
						<?php if ($is_org) : ?>
							<option value="site" <?php selected($works_for, 'site'); ?>><?php
								/* translators: %s: organisation name from Site Information */
								printf(esc_html__('%s (from Site Information)', 'mihdan-index-now'), esc_html(Author::site_name()));
							?></option>
						<?php endif; ?>
						<option value="other" <?php selected($works_for, 'other'); ?>><?php esc_html_e('Another organisation…', 'mihdan-index-now'); ?></option>
						<option value="none" <?php selected($works_for, 'none'); ?>><?php esc_html_e('Nobody (freelance or not relevant)', 'mihdan-index-now'); ?></option>
					</select>
					<div id="cwp_author_org" style="margin-top:10px;<?php echo $works_for === 'other' ? '' : 'display:none'; ?>">
						<p><label><?php esc_html_e('Organisation name', 'mihdan-index-now'); ?><br>
							<input type="text" name="<?php echo esc_attr(Author::META_WORKS_FOR_NAME); ?>" class="regular-text" value="<?php echo esc_attr($org_name); ?>"></label></p>
						<p><label><?php esc_html_e('Organisation website (optional)', 'mihdan-index-now'); ?><br>
							<input type="url" name="<?php echo esc_attr(Author::META_WORKS_FOR_URL); ?>" class="regular-text" value="<?php echo esc_attr($org_url); ?>" placeholder="https://"></label></p>
					</div>
					<script>
					(function () {
						var s = document.getElementById('cwp_author_works_for'), o = document.getElementById('cwp_author_org');
						if (s && o) { s.addEventListener('change', function () { o.style.display = s.value === 'other' ? '' : 'none'; }); }
					})();
					</script>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cwp_author_expertise"><?php esc_html_e('Expertise', 'mihdan-index-now'); ?></label></th>
				<td>
					<input type="text" id="cwp_author_expertise" name="<?php echo esc_attr(Author::META_EXPERTISE); ?>" class="regular-text" value="<?php echo esc_attr($expertise); ?>">
					<p class="description"><?php esc_html_e('Topics this person knows well, separated by commas.', 'mihdan-index-now'); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cwp_author_credentials"><?php esc_html_e('Education and credentials', 'mihdan-index-now'); ?></label></th>
				<td>
					<textarea id="cwp_author_credentials" name="<?php echo esc_attr(Author::META_CREDENTIALS); ?>" class="large-text" rows="3"><?php echo esc_textarea($credentials); ?></textarea>
					<p class="description"><?php esc_html_e('Degrees, certificates, licences or memberships. One per line.', 'mihdan-index-now'); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('Profile photo', 'mihdan-index-now'); ?></th>
				<td>
					<div class="cwp-author-photo" style="display:flex;align-items:center;gap:12px">
						<span id="cwp_author_photo_preview" data-gravatar="<?php echo esc_url((string) get_avatar_url($user->ID, ['size' => 128])); ?>"><?php
							if ($photo_url !== '') {
								printf('<img src="%s" width="64" height="64" style="border-radius:50%%;object-fit:cover" alt="">', esc_url($photo_url));
							} else {
								echo get_avatar($user->ID, 64, '', '', ['extra_attr' => 'style="border-radius:50%"']);
							}
						?></span>
						<?php if ($can_upload) : ?>
							<input type="hidden" id="cwp_author_photo" name="<?php echo esc_attr(Author::META_PHOTO); ?>" value="<?php echo $photo_id > 0 ? (int) $photo_id : ''; ?>">
							<button type="button" class="button" id="cwp_author_photo_choose"><?php esc_html_e('Choose photo', 'mihdan-index-now'); ?></button>
							<button type="button" class="button-link button-link-delete" id="cwp_author_photo_remove" <?php echo $photo_id > 0 ? '' : 'style="display:none"'; ?>><?php esc_html_e('Use Gravatar', 'mihdan-index-now'); ?></button>
						<?php endif; ?>
					</div>
					<p class="description"><?php
						echo $can_upload
							? esc_html__('Shown in the author box and given to search engines. Square, at least 256 × 256 px. Without one, the author box shows the Gravatar and search engines get no photo.', 'mihdan-index-now')
							: esc_html__('The author box shows the Gravatar for this email address.', 'mihdan-index-now');
					?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('Short bio', 'mihdan-index-now'); ?></th>
				<td><p style="margin:0"><?php
					/* translators: %s: "Biographical Info" */
					printf(esc_html__('Uses %s above.', 'mihdan-index-now'), '<a href="#description"><b>' . esc_html__('Biographical Info', 'mihdan-index-now') . '</b></a>');
				?></p></td>
			</tr>
		</table>
		<?php
	}

	public function save(int $user_id): void
	{
		if (! current_user_can('edit_user', $user_id) || ! user_can($user_id, 'edit_posts')) {
			return;
		}

		if (! isset($_POST['_crawlwp_author_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_crawlwp_author_nonce'])), 'crawlwp_author_profile')) {
			return;
		}

		$text = static function (string $key, bool $multiline = false): string {
			if (! isset($_POST[$key])) {
				return '';
			}

			$value = wp_unslash((string) $_POST[$key]);

			return $multiline ? sanitize_textarea_field($value) : sanitize_text_field($value);
		};

		$works_for = $text(Author::META_WORKS_FOR);

		if (! in_array($works_for, ['site', 'other', 'none'], true)) {
			$works_for = '';
		}

		$values = [
			Author::META_JOB_TITLE      => mb_substr($text(Author::META_JOB_TITLE), 0, 120),
			Author::META_WORKS_FOR      => $works_for,
			Author::META_WORKS_FOR_NAME => mb_substr($text(Author::META_WORKS_FOR_NAME), 0, 160),
			Author::META_WORKS_FOR_URL  => isset($_POST[Author::META_WORKS_FOR_URL]) ? esc_url_raw(wp_unslash((string) $_POST[Author::META_WORKS_FOR_URL]), ['http', 'https']) : '',
			Author::META_EXPERTISE      => implode(', ', array_slice(Author::split($text(Author::META_EXPERTISE), ','), 0, 30)),
			Author::META_CREDENTIALS    => implode("\n", array_slice(Author::split($text(Author::META_CREDENTIALS, true), "\n"), 0, 30)),
		];

		/* The photo field is only on the form for users who can upload files. */
		if (current_user_can('upload_files') && isset($_POST[Author::META_PHOTO])) {
			$photo_id = absint(wp_unslash($_POST[Author::META_PHOTO]));

			$values[Author::META_PHOTO] = $photo_id > 0 && wp_attachment_is_image($photo_id) ? (string) $photo_id : '';
		}

		foreach ($values as $key => $value) {
			if ($value === '') {
				delete_user_meta($user_id, $key);
			} else {
				update_user_meta($user_id, $key, $value);
			}
		}

		Author::flush($user_id);
	}
}
