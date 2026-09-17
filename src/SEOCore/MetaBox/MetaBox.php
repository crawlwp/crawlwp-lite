<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

class MetaBox
{
	public function __construct()
	{
		add_action('add_meta_boxes', [$this, 'register'], 1);
		add_action('save_post', [$this, 'save']);
	}

	public function register($post_type = '')
	{
		$post_types = get_post_types(['public' => true]);

		$title = sprintf(
			'<span class="cwp-mb-title-wrap">%s <span class="cwp-score"><span class="cwp-score-ring"><svg width="38" height="38" viewBox="0 0 38 38" aria-hidden="true"><circle class="cwp-track" cx="19" cy="19" r="16" fill="none" stroke-width="3.5"/><circle class="cwp-fill" cx="19" cy="19" r="16" fill="none" stroke-width="3.5" stroke-dasharray="0 100" pathLength="100"/></svg><span class="cwp-score-num">—</span></span></span></span>',
			esc_html__('CrawlWP SEO', 'mihdan-index-now')
		);

		$priority = 'product' === $post_type ? 'default' : 'high';

		$context    = apply_filters( 'crawlwp_seo_meta_box_context', 'normal' );
		$priority   = apply_filters( 'crawlwp_seo_meta_box_priority', $priority );

		foreach ($post_types as $_post_type) {
			add_meta_box(
				'crawlwp-seo-metabox',
				$title,
				[$this, 'render'],
				$_post_type,
				$context,
				$priority
			);
		}
	}

	public function save(int $post_id): void
	{
		MetaFields::save($post_id);
	}

	public function render(\WP_Post $post): void
	{
		$data      = MetaFields::get_all($post->ID);
		$post_type = get_post_type_object($post->post_type);
		$type_name = $post_type ? $post_type->labels->singular_name : __('Post', 'mihdan-index-now');
		$site_name = get_bloginfo('name');
		$site_url  = home_url('/');
		$permalink = get_permalink($post->ID);
		$post_title = wp_specialchars_decode($post->post_title, ENT_QUOTES);

		$og_image_url = '';
		if (! empty($data['og_image'])) {
			$img = wp_get_attachment_image_url((int) $data['og_image'], 'large');
			if ($img) {
				$og_image_url = $img;
			}
		}

		$x_image_url = '';
		if (! empty($data['x_image'])) {
			$img = wp_get_attachment_image_url((int) $data['x_image'], 'large');
			if ($img) {
				$x_image_url = $img;
			}
		}

		$featured_image_url = get_the_post_thumbnail_url($post->ID, 'large') ?: '';

		wp_nonce_field(MetaFields::NONCE_ACTION, MetaFields::NONCE_NAME);

		include __DIR__ . '/views/metabox-template.php';
	}
}
