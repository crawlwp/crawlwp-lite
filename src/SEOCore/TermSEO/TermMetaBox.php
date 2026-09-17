<?php

namespace Mihdan\IndexNow\SEOCore\TermSEO;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

/**
 * SEO fields on category / tag / custom taxonomy term screens.
 */
class TermMetaBox
{
	public function __construct()
	{
		add_action('init', [$this, 'register_hooks'], 20);
	}

	public function register_hooks(): void
	{
		$taxonomies = get_taxonomies(['public' => true], 'names');

		foreach ($taxonomies as $taxonomy) {
			add_action($taxonomy . '_add_form_fields', [$this, 'render_add']);
			add_action($taxonomy . '_edit_form_fields', [$this, 'render_edit']);
			add_action('created_' . $taxonomy, [$this, 'save']);
			add_action('edited_' . $taxonomy, [$this, 'save']);
		}
	}

	public function render_add(): void
	{
		wp_nonce_field(MetaFields::NONCE_ACTION, MetaFields::NONCE_NAME);
		?>
		<div class="form-field">
			<label for="cwpTermTitle"><?php esc_html_e('SEO title', 'mihdan-index-now'); ?></label>
			<input name="<?php echo esc_attr(MetaFields::SEO_TITLE); ?>" id="cwpTermTitle" type="text" value="" class="regular-text">
		</div>
		<div class="form-field">
			<label for="cwpTermDesc"><?php esc_html_e('Meta description', 'mihdan-index-now'); ?></label>
			<textarea name="<?php echo esc_attr(MetaFields::SEO_DESCRIPTION); ?>" id="cwpTermDesc" rows="3"></textarea>
		</div>
		<?php
	}

	public function render_edit(\WP_Term $term): void
	{
		$data = TermFields::get_all((int) $term->term_id);
		wp_nonce_field(MetaFields::NONCE_ACTION, MetaFields::NONCE_NAME);
		?>
		<tr class="form-field">
			<th colspan="2"><h2><?php esc_html_e('CrawlWP SEO', 'mihdan-index-now'); ?></h2></th>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cwpTermTitle"><?php esc_html_e('SEO title', 'mihdan-index-now'); ?></label></th>
			<td>
				<input name="<?php echo esc_attr(MetaFields::SEO_TITLE); ?>" id="cwpTermTitle" type="text" value="<?php echo esc_attr($data['seo_title']); ?>" class="regular-text">
				<p class="description"><?php esc_html_e('Leave empty to use the taxonomy template from Title &amp; Meta.', 'mihdan-index-now'); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cwpTermDesc"><?php esc_html_e('Meta description', 'mihdan-index-now'); ?></label></th>
			<td>
				<textarea name="<?php echo esc_attr(MetaFields::SEO_DESCRIPTION); ?>" id="cwpTermDesc" rows="3" class="large-text"><?php echo esc_textarea($data['seo_description']); ?></textarea>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cwpTermCanonical"><?php esc_html_e('Canonical URL', 'mihdan-index-now'); ?></label></th>
			<td>
				<input name="<?php echo esc_attr(MetaFields::CANONICAL_URL); ?>" id="cwpTermCanonical" type="url" value="<?php echo esc_attr($data['canonical_url']); ?>" class="regular-text">
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e('Robots', 'mihdan-index-now'); ?></th>
			<td>
				<select name="<?php echo esc_attr(MetaFields::ROBOTS_INDEX); ?>">
					<option value="index" <?php selected($data['robots_index'], 'index'); ?>><?php esc_html_e('index', 'mihdan-index-now'); ?></option>
					<option value="noindex" <?php selected($data['robots_index'], 'noindex'); ?>><?php esc_html_e('noindex', 'mihdan-index-now'); ?></option>
				</select>
				<select name="<?php echo esc_attr(MetaFields::ROBOTS_FOLLOW); ?>">
					<option value="follow" <?php selected($data['robots_follow'], 'follow'); ?>><?php esc_html_e('follow', 'mihdan-index-now'); ?></option>
					<option value="nofollow" <?php selected($data['robots_follow'], 'nofollow'); ?>><?php esc_html_e('nofollow', 'mihdan-index-now'); ?></option>
				</select>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cwpTermOgTitle"><?php esc_html_e('Open Graph title', 'mihdan-index-now'); ?></label></th>
			<td>
				<input name="<?php echo esc_attr(MetaFields::OG_TITLE); ?>" id="cwpTermOgTitle" type="text" value="<?php echo esc_attr($data['og_title']); ?>" class="regular-text">
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cwpTermOgDesc"><?php esc_html_e('Open Graph description', 'mihdan-index-now'); ?></label></th>
			<td>
				<textarea name="<?php echo esc_attr(MetaFields::OG_DESCRIPTION); ?>" id="cwpTermOgDesc" rows="3" class="large-text"><?php echo esc_textarea($data['og_description']); ?></textarea>
			</td>
		</tr>
		<?php
	}

	public function save(int $term_id): void
	{
		TermFields::save($term_id);
	}
}
