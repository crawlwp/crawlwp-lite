<?php

namespace Mihdan\IndexNow\SEOCore\Authors;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\Schema\Graph;

/**
 * E-E-A-T structured data.
 *
 * - Posts: the author becomes a full Person node (job title, employer,
 *   expertise, credentials, photo, bio, profiles) that the article points to
 *   by `@id`.
 * - Posts: an optional "Reviewed by" person, chosen in the SEO box's Schema
 *   tab, is added to the WebPage as `reviewedBy`.
 * - Author archives: the ProfilePage gets the author as its `mainEntity`.
 *
 * Hooks into the nodes {@see \Mihdan\IndexNow\SEOCore\TitleMeta\FrontendOutput}
 * builds, so pages without structured data (noindex, page type "None") get
 * none of this either.
 */
class AuthorSchema
{
	public const META_REVIEWED_BY = '_crawlwp_reviewed_by';

	/** @var array{0:string, 1:string}|null WebPage `@id` and reviewer `@id` to join in the graph. */
	private $review;

	public function __construct()
	{
		/* Priority 5: site code on the default priority sees the final node. */
		add_filter('crawlwp_schema_data', [$this, 'schema_data'], 5, 2);
		add_filter('crawlwp_schema_graph', [$this, 'add_reviewed_by']);

		add_action('crawlwp_metabox_schema_top', [$this, 'render_metabox_fields']);
		add_action('save_post', [$this, 'save'], 10, 2);
	}

	/**
	 * @param array         $schema The node describing the queried content.
	 * @param \WP_Post|null $post   The queried post, null on archives.
	 */
	public function schema_data($schema, $post = null): array
	{
		$schema = (array) $schema;

		if (! $post instanceof \WP_Post) {
			return $this->profile_page($schema);
		}

		/* Only replace the plain Person FrontendOutput built, not something a filter already changed. */
		if (isset($schema['author']['@type']) && $schema['author']['@type'] === 'Person' && ! isset($schema['author']['@id'])) {
			$node = Author::person_node((int) $post->post_author);

			if ($node !== null) {
				Graph::add_node($node);
				$schema['author'] = ['@id' => $node['@id']];
			}
		}

		$reviewer = self::reviewer($post->ID);
		$node     = $reviewer > 0 && user_can($reviewer, 'edit_posts') ? Author::person_node($reviewer) : null;

		if ($node !== null) {
			Graph::add_node($node);

			/* reviewedBy belongs on the WebPage. For an article that's a separate node. */
			if (isset($schema['mainEntityOfPage']['@id'])) {
				$this->review = [(string) $schema['mainEntityOfPage']['@id'], (string) $node['@id']];
			} else {
				$schema['reviewedBy'] = ['@id' => $node['@id']];
			}
		}

		return $schema;
	}

	/**
	 * Author archive: the person is what the ProfilePage is about.
	 */
	private function profile_page(array $schema): array
	{
		if (! is_author() || ($schema['@type'] ?? '') !== 'ProfilePage') {
			return $schema;
		}

		$user = get_queried_object();
		$node = $user instanceof \WP_User ? Author::person_node((int) $user->ID) : null;

		if ($node !== null) {
			$node['mainEntityOfPage'] = ['@id' => (string) ($schema['@id'] ?? '')];

			if ($node['mainEntityOfPage']['@id'] === '') {
				unset($node['mainEntityOfPage']);
			}

			Graph::add_node($node);
			$schema['mainEntity'] = ['@id' => $node['@id']];
		}

		return $schema;
	}

	/**
	 * Add reviewedBy to the article's WebPage node once the graph is complete.
	 *
	 * @param array $nodes Every node of the request graph.
	 */
	public function add_reviewed_by($nodes): array
	{
		$nodes = (array) $nodes;

		if ($this->review === null) {
			return $nodes;
		}

		[$page_id, $reviewer_id] = $this->review;

		foreach ($nodes as $i => $node) {
			if (is_array($node) && ($node['@id'] ?? '') === $page_id) {
				$nodes[$i]['reviewedBy'] = ['@id' => $reviewer_id];
				break;
			}
		}

		return $nodes;
	}

	/**
	 * The reviewer saved on a post, 0 for none.
	 */
	public static function reviewer(int $post_id): int
	{
		return (int) get_post_meta($post_id, self::META_REVIEWED_BY, true);
	}

	/**
	 * People who can review: anyone who can write posts.
	 *
	 * @return array<int, string> User ID => "Name — Job title".
	 */
	private static function reviewer_options(int $current): array
	{
		/* Writers can only name themselves; editors anyone who writes. */
		if (! self::can_choose_anyone()) {
			$ids = array_values(array_unique(array_filter([get_current_user_id(), $current])));

			return array_combine($ids, array_map([self::class, 'label'], $ids)) ?: [];
		}

		$users = get_users([
			'capability' => 'edit_posts',
			'orderby'    => 'display_name',
			'number'     => 300,
			'fields'     => ['ID', 'display_name'],
		]);

		$ids = array_map(static function ($u): int {
			return (int) $u->ID;
		}, $users);

		if ($current > 0 && ! in_array($current, $ids, true) && get_userdata($current)) {
			$ids[] = $current;
		}

		/* One query for every user's details and job title, not one per user. */
		cache_users($ids);

		$options = [];

		foreach ($ids as $id) {
			$options[$id] = self::label($id);
		}

		return $options;
	}

	/**
	 * Whether the current user may name anyone as a post's reviewer.
	 */
	private static function can_choose_anyone(): bool
	{
		return current_user_can('edit_others_posts');
	}

	/**
	 * "Name — Job title", or just the name.
	 */
	private static function label(int $user_id): string
	{
		$user = get_userdata($user_id);

		if (! $user instanceof \WP_User) {
			return '';
		}

		$job = (string) get_user_meta($user_id, Author::META_JOB_TITLE, true);

		return $job !== '' ? $user->display_name . ' — ' . $job : $user->display_name;
	}

	/**
	 * Link to the profile screen section where the author details are edited.
	 */
	private static function profile_link(int $user_id): string
	{
		if (! current_user_can('edit_user', $user_id)) {
			return '';
		}

		$url = $user_id === get_current_user_id()
			? admin_url('profile.php')
			: add_query_arg('user_id', $user_id, admin_url('user-edit.php'));

		return $url . '#crawlwp-author-profile';
	}

	/**
	 * Author and "Reviewed by" at the top of the SEO box's Schema tab.
	 */
	public function render_metabox_fields($post): void
	{
		if (! $post instanceof \WP_Post) {
			return;
		}

		$author_id = (int) $post->post_author;
		$reviewer  = self::reviewer($post->ID);
		$link      = self::profile_link($author_id);
		?>
		<div class="cwp-section cwp-authors">
			<div class="cwp-grid-2">
				<div class="cwp-field">
					<div class="cwp-label-row"><label class="cwp-label" for="cwpSchemaAuthor"><?php esc_html_e('Author', 'mihdan-index-now'); ?></label></div>
					<input type="text" class="cwp-input" id="cwpSchemaAuthor" value="<?php echo esc_attr(self::label($author_id)); ?>" readonly>
					<p class="cwp-hint"><?php
						esc_html_e('The post author. Change it in the editor\'s Author setting.', 'mihdan-index-now');

						if ($link !== '') {
							printf(' <a href="%s" target="_blank">%s</a>', esc_url($link), esc_html__('Edit author profile', 'mihdan-index-now'));
						}
					?></p>
				</div>
				<div class="cwp-field">
					<div class="cwp-label-row"><label class="cwp-label" for="cwpReviewedBy"><?php esc_html_e('Reviewed by', 'mihdan-index-now'); ?></label></div>
					<select class="cwp-select" id="cwpReviewedBy" name="<?php echo esc_attr(self::META_REVIEWED_BY); ?>" <?php disabled(! self::can_choose_anyone() && ! in_array($reviewer, [0, get_current_user_id()], true)); ?>>
						<option value=""><?php esc_html_e('Nobody', 'mihdan-index-now'); ?></option>
						<?php foreach (self::reviewer_options($reviewer) as $id => $label) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected($reviewer, $id); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="cwp-hint"><?php esc_html_e('For advice on health, money or law: who checked this page is correct. Added to the structured data as reviewedBy.', 'mihdan-index-now'); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save "Reviewed by" with the SEO box.
	 */
	public function save(int $post_id, $post = null): void
	{
		if (! isset($_POST[self::META_REVIEWED_BY], $_POST[MetaFields::NONCE_NAME])) {
			return;
		}

		if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[MetaFields::NONCE_NAME])), MetaFields::NONCE_ACTION)) {
			return;
		}

		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || ! current_user_can('edit_post', $post_id)) {
			return;
		}

		/* With the Permissions feature, the Schema tab needs the "Advanced SEO fields" permission. */
		$permissions = '\Mihdan\IndexNow\SEOCore\Permissions';

		if (class_exists($permissions) && ! $permissions::can($permissions::SEO_ADVANCED)) {
			return;
		}

		$reviewer = absint(wp_unslash($_POST[self::META_REVIEWED_BY]));
		$current  = self::reviewer($post_id);

		if ($reviewer === $current) {
			return;
		}

		if ($reviewer > 0 && ! user_can($reviewer, 'edit_posts')) {
			return;
		}

		/* Naming someone else as the reviewer, or removing them, is for editors. */
		if (! self::can_choose_anyone() && ! in_array($reviewer, [0, get_current_user_id()], true)) {
			return;
		}

		if (! self::can_choose_anyone() && ! in_array($current, [0, get_current_user_id()], true)) {
			return;
		}

		if ($reviewer > 0) {
			update_post_meta($post_id, self::META_REVIEWED_BY, $reviewer);
		} else {
			delete_post_meta($post_id, self::META_REVIEWED_BY);
		}
	}
}
