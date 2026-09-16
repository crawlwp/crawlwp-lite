<?php

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

if (! defined('ABSPATH')) exit;
$robots_adv = is_array($data['robots_advanced']) ? $data['robots_advanced'] : [];
$categories = get_the_terms($post, 'category');
$categories = is_array($categories) ? $categories : [];

/**
 * Render the "generate with AI" button for a field.
 *
 * @param string $target The id of the input the generated text goes into.
 * @param string $field  The field key sent to the server.
 */
$cwp_ai_button = static function (string $target, string $field): void {
  ?>
  <button class="cwp-ai-btn" type="button" data-ai-target="<?php echo esc_attr($target); ?>" data-ai-field="<?php echo esc_attr($field); ?>" title="<?php esc_attr_e('Generate with AI', 'mihdan-index-now'); ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.09 6.26L20 10l-5.91 1.74L12 18l-2.09-6.26L4 10l5.91-1.74z"/><path d="M19 2l.87 2.61L22.5 5.5l-2.63.89L19 9l-.87-2.61L15.5 5.5l2.63-.89z"/></svg>
    <?php esc_html_e('AI', 'mihdan-index-now'); ?>
  </button>
  <?php
};

$cwp_is_product = ($post instanceof \WP_Post && $post->post_type === 'product');
$cwp_wc_var_items = static function () use ($cwp_is_product): void {
  if (! $cwp_is_product) {
    return;
  }
  ?>
  <button class="cwp-var-item" type="button" data-token="{{ product.price }}"><code>{{ product.price }}</code><span><?php esc_html_e('Price', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.price_with_tax }}"><code>{{ product.price_with_tax }}</code><span><?php esc_html_e('Price including tax', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.sale_from }}"><code>{{ product.sale_from }}</code><span><?php esc_html_e('Sale price date "From"', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.sale_to }}"><code>{{ product.sale_to }}</code><span><?php esc_html_e('Sale price date "To"', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.sku }}"><code>{{ product.sku }}</code><span><?php esc_html_e('SKU', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.stock }}"><code>{{ product.stock }}</code><span><?php esc_html_e('Stock status', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.currency }}"><code>{{ product.currency }}</code><span><?php esc_html_e('Currency', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.rating }}"><code>{{ product.rating }}</code><span><?php esc_html_e('Rating value', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.review_count }}"><code>{{ product.review_count }}</code><span><?php esc_html_e('Review count', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.low_price }}"><code>{{ product.low_price }}</code><span><?php esc_html_e('Low price (variable product)', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.high_price }}"><code>{{ product.high_price }}</code><span><?php esc_html_e('High price (variable product)', 'mihdan-index-now'); ?></span></button>
  <button class="cwp-var-item" type="button" data-token="{{ product.offer_count }}"><code>{{ product.offer_count }}</code><span><?php esc_html_e('Offer count (variable product)', 'mihdan-index-now'); ?></span></button>
  <?php
};
?>
<div class="cwp-metabox" id="crawlwp-seo-metabox-inner">

  <?php
  /*
   * Marks the request as coming from the full SEO form. Checkbox and
   * robots-advanced fields are only written when it is present, so a
   * programmatic save_post from another plugin cannot reset them.
   */
  ?>
  <input type="hidden" name="<?php echo esc_attr(MetaFields::CHECKBOX_TRACKER); ?>" value="1">

  <div class="cwp-tabs" role="tablist">
    <button class="cwp-tab is-active" role="tab" aria-selected="true" data-panel="general" type="button"><?php esc_html_e('General', 'mihdan-index-now'); ?></button>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="analysis" type="button">
      <?php esc_html_e('Analysis', 'mihdan-index-now'); ?> <span class="cwp-dot cwp-dot-analysis" title="" hidden></span>
    </button>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="social" type="button"><?php esc_html_e('Social', 'mihdan-index-now'); ?></button>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="schema" type="button"><?php esc_html_e('Schema', 'mihdan-index-now'); ?></button>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="links" type="button">
      <?php esc_html_e('Links', 'mihdan-index-now'); ?> <span class="cwp-dot cwp-dot-links" title="<?php esc_attr_e('No internal links yet', 'mihdan-index-now'); ?>" hidden></span>
    </button>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="advanced" type="button"><?php esc_html_e('Advanced', 'mihdan-index-now'); ?></button>
    <?php if (! defined('CRAWLWP_PRO_VERSION')) : ?>
    <button class="cwp-tab" role="tab" aria-selected="false" data-panel="insights" type="button">
      <?php esc_html_e('Insights', 'mihdan-index-now'); ?> <span class="cwp-pro-badge"><?php esc_html_e('PRO', 'mihdan-index-now'); ?></span>
    </button>
    <?php endif; ?>
    <?php
    /**
     * Fires inside the SEO metabox tab bar, right after the "Advanced" tab.
     *
     * Add-on plugins (e.g. mihdan-index-now-pro) can use this to render
     * their own `<button class="cwp-tab" data-panel="{id}">` tabs — the
     * generic tab-switching JS matches on `data-panel` / `#cwp-panel-{id}`
     * so no extra wiring is needed on the free plugin's side.
     *
     * @param \WP_Post $post The post being edited.
     * @param array    $data The metabox's resolved field data.
     */
    do_action('crawlwp_metabox_tabs', $post, $data);
    ?>
  </div>

  <!-- GENERAL -->
  <div class="cwp-panel is-active" id="cwp-panel-general" role="tabpanel">
    <div class="cwp-field">
      <div class="cwp-label-row">
        <label class="cwp-label" for="cwpTitle"><?php esc_html_e('SEO title', 'mihdan-index-now'); ?></label>
        <span class="cwp-help" title="<?php esc_attr_e('Shown as the clickable headline in search results.', 'mihdan-index-now'); ?>">?</span>
        <span class="cwp-spacer"></span>
        <?php $cwp_ai_button('cwpTitle', 'title'); ?>
        <div class="cwp-var">
          <button class="cwp-var-btn" type="button" data-var-for="cwpTitle">
            <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
          </button>
          <div class="cwp-var-menu">
            <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ sep }}"><code>{{ sep }}</code><span><?php esc_html_e('Separator', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ post.author }}"><code>{{ post.author }}</code><span><?php esc_html_e('Author name', 'mihdan-index-now'); ?></span></button>
            <?php $cwp_wc_var_items(); ?>
          </div>
        </div>
      </div>
      <input class="cwp-input" id="cwpTitle" name="<?php echo esc_attr(MetaFields::SEO_TITLE); ?>" type="text"
             value="<?php echo esc_attr($data['seo_title']); ?>"
             placeholder="{{ post.title }} {{ sep }} {{ site.title }}"
             data-meter="cwpTitleMeter" data-limit="580" data-font="bold 20px Arial">
      <div class="cwp-meter">
        <div class="cwp-meter-bar"><div class="cwp-meter-fill" id="cwpTitleMeterFill"></div></div>
        <div class="cwp-meter-text" id="cwpTitleMeter">—</div>
      </div>
      <p class="cwp-hint"><?php esc_html_e('Google cuts titles by width, not character count — the bar tracks rendered pixels.', 'mihdan-index-now'); ?></p>
    </div>

    <div class="cwp-field">
      <div class="cwp-label-row">
        <label class="cwp-label" for="cwpDesc"><?php esc_html_e('Meta description', 'mihdan-index-now'); ?></label>
        <span class="cwp-help" title="<?php esc_attr_e('The snippet under your title. Google may replace it with page text.', 'mihdan-index-now'); ?>">?</span>
        <span class="cwp-spacer"></span>
        <?php $cwp_ai_button('cwpDesc', 'description'); ?>
        <div class="cwp-var">
          <button class="cwp-var-btn" type="button" data-var-for="cwpDesc">
            <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
          </button>
          <div class="cwp-var-menu">
            <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
            <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
            <?php $cwp_wc_var_items(); ?>
          </div>
        </div>
      </div>
      <textarea class="cwp-textarea" id="cwpDesc" name="<?php echo esc_attr(MetaFields::SEO_DESCRIPTION); ?>"
                data-meter="cwpDescMeter" data-limit="920" data-font="14px Arial"><?php echo esc_textarea($data['seo_description']); ?></textarea>
      <div class="cwp-meter">
        <div class="cwp-meter-bar"><div class="cwp-meter-fill" id="cwpDescMeterFill"></div></div>
        <div class="cwp-meter-text" id="cwpDescMeter">—</div>
      </div>
    </div>

    <!-- Readability badge -->
    <div class="cwp-readability-badge" id="cwpReadabilityBadge">
      <span class="cwp-readability-icon" id="cwpReadabilityIcon">—</span>
      <span class="cwp-readability-text" id="cwpReadabilityText"><?php esc_html_e('Readability analysis will run when content is available.', 'mihdan-index-now'); ?></span>
    </div>

    <!-- Search Preview -->
    <div class="cwp-preview cwp-preview-last">
      <div class="cwp-preview-bar">
        <h4><?php esc_html_e('Search preview', 'mihdan-index-now'); ?></h4>
        <div class="cwp-seg" id="cwpDeviceSeg">
          <button class="is-active" data-device="desktop" type="button"><?php esc_html_e('Desktop', 'mihdan-index-now'); ?></button>
          <button data-device="mobile" type="button"><?php esc_html_e('Mobile', 'mihdan-index-now'); ?></button>
        </div>
      </div>
      <div class="cwp-preview-body">
        <div class="cwp-serp" id="cwpSerp">
          <div class="cwp-serp-site">
            <div class="cwp-favicon">C</div>
            <div>
              <div class="cwp-serp-name"><?php echo esc_html($site_name); ?></div>
              <div class="cwp-serp-url" id="cwpSerpUrl"><?php echo esc_html(str_replace(['https://', 'http://'], '', $site_url)); ?></div>
            </div>
          </div>
          <div class="cwp-serp-title" id="cwpSerpTitle"><?php echo esc_html($data['seo_title'] ?: $post_title); ?></div>
          <div class="cwp-serp-desc"><span id="cwpSerpDesc"><?php echo esc_html($data['seo_description']); ?></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- SOCIAL -->
  <div class="cwp-panel" id="cwp-panel-social" role="tabpanel">
    <div class="cwp-social-tabs">
      <button class="cwp-social-tab is-active" data-social="fb" type="button">
        <svg width="12" height="12" viewBox="0 0 24 24"><path fill="#1877f2" d="M24 12a12 12 0 10-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.6 4.5-4.6 1.3 0 2.6.2 2.6.2v2.9h-1.5c-1.5 0-1.9.9-1.9 1.8V12h3.3l-.5 3.5h-2.8v8.4A12 12 0 0024 12z"/></svg>
        Facebook
      </button>
      <button class="cwp-social-tab" data-social="x" type="button">
        <svg width="11" height="11" viewBox="0 0 24 24"><path fill="#0f1419" d="M18.9 1.2h3.7l-8 9.1 9.4 12.5h-7.4l-5.8-7.6-6.6 7.6H.5l8.6-9.8L0 1.2h7.6l5.2 6.9zm-1.3 19.4h2L6.5 3.3H4.3z"/></svg>
        X
      </button>
    </div>

    <!-- Facebook panel -->
    <div class="cwp-social-panel is-active" id="cwp-social-fb">
      <div class="cwp-preview">
        <div class="cwp-preview-bar"><h4>Facebook · LinkedIn · WhatsApp</h4></div>
        <div class="cwp-preview-body cwp-og-preview">
          <div class="cwp-fb-card">
            <div class="cwp-og-img" <?php if ($og_image_url || $featured_image_url): ?>style="background-image:url('<?php echo esc_url($og_image_url ?: $featured_image_url); ?>')"<?php endif; ?>>
              <?php if (! $og_image_url && ! $featured_image_url): ?>1200 × 630<?php endif; ?>
            </div>
            <div class="cwp-fb-meta">
              <div class="cwp-fb-domain"><?php echo esc_html(wp_parse_url($site_url, PHP_URL_HOST)); ?></div>
              <p class="cwp-fb-title" id="cwpFbTitlePrev"><?php echo esc_html($data['seo_title'] ?: $post_title); ?></p>
              <p class="cwp-fb-desc" id="cwpFbDescPrev"><?php echo esc_html($data['seo_description']); ?></p>
            </div>
          </div>
        </div>
      </div>

      <div class="cwp-field">
        <label class="cwp-switch">
          <input type="checkbox" id="cwpFbSync" name="<?php echo esc_attr(MetaFields::OG_SYNC); ?>" value="1" <?php checked($data['og_sync'], '1'); ?>>
          <span class="cwp-switch-ui"></span>
          <span class="cwp-switch-text"><?php esc_html_e('Use the SEO title and description', 'mihdan-index-now'); ?></span>
        </label>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <label class="cwp-label" for="cwpFbTitle"><?php esc_html_e('Open Graph title', 'mihdan-index-now'); ?></label>
          <span class="cwp-spacer"></span>
          <?php $cwp_ai_button('cwpFbTitle', 'og_title'); ?>
          <div class="cwp-var">
            <button class="cwp-var-btn" type="button" data-var-for="cwpFbTitle">
              <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
            </button>
            <div class="cwp-var-menu">
              <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ sep }}"><code>{{ sep }}</code><span><?php esc_html_e('Separator', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.author }}"><code>{{ post.author }}</code><span><?php esc_html_e('Author name', 'mihdan-index-now'); ?></span></button>
              <?php $cwp_wc_var_items(); ?>
            </div>
          </div>
        </div>
        <input class="cwp-input" id="cwpFbTitle" name="<?php echo esc_attr(MetaFields::OG_TITLE); ?>" type="text" value="<?php echo esc_attr($data['og_title']); ?>" <?php disabled($data['og_sync'], '1'); ?>>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <label class="cwp-label" for="cwpFbDesc"><?php esc_html_e('Open Graph description', 'mihdan-index-now'); ?></label>
          <span class="cwp-spacer"></span>
          <?php $cwp_ai_button('cwpFbDesc', 'og_description'); ?>
          <div class="cwp-var">
            <button class="cwp-var-btn" type="button" data-var-for="cwpFbDesc">
              <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
            </button>
            <div class="cwp-var-menu">
              <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
              <?php $cwp_wc_var_items(); ?>
            </div>
          </div>
        </div>
        <textarea class="cwp-textarea" id="cwpFbDesc" name="<?php echo esc_attr(MetaFields::OG_DESCRIPTION); ?>" <?php disabled($data['og_sync'], '1'); ?>><?php echo esc_textarea($data['og_description']); ?></textarea>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <span class="cwp-label"><?php esc_html_e('Open Graph image', 'mihdan-index-now'); ?></span>
          <span class="cwp-help" title="<?php esc_attr_e('1200 × 630 px works everywhere. Falls back to the featured image.', 'mihdan-index-now'); ?>">?</span>
        </div>
        <div class="cwp-img-picker">
          <div class="cwp-img-thumb" <?php if ($og_image_url): ?>style="background-image:url('<?php echo esc_url($og_image_url); ?>')"<?php endif; ?>></div>
          <input type="hidden" id="cwpOgImage" name="<?php echo esc_attr(MetaFields::OG_IMAGE); ?>" value="<?php echo esc_attr($data['og_image']); ?>">
          <div class="cwp-img-actions">
            <div class="cwp-img-btns">
              <button class="cwp-btn cwp-img-pick-btn" type="button" data-target="cwpOgImage"><?php esc_html_e('Replace image', 'mihdan-index-now'); ?></button>
              <button class="cwp-btn cwp-btn-link cwp-img-remove-btn" type="button" data-target="cwpOgImage"><?php esc_html_e('Remove', 'mihdan-index-now'); ?></button>
            </div>
            <p class="cwp-hint" style="margin:0;"><?php esc_html_e('Recommended 1200 × 630 px, under 5 MB.', 'mihdan-index-now'); ?></p>
          <div class="cwp-img-dimensions" id="cwpOgImgDimensions" hidden></div>
          </div>
        </div>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row"><label class="cwp-label" for="cwpOgImageAlt"><?php esc_html_e('Social image alt text', 'mihdan-index-now'); ?></label></div>
        <input class="cwp-input" id="cwpOgImageAlt" name="<?php echo esc_attr(MetaFields::OG_IMAGE_ALT); ?>" type="text" value="<?php echo esc_attr($data['og_image_alt']); ?>" placeholder="<?php esc_attr_e('Describe the image for screen readers and social networks', 'mihdan-index-now'); ?>">
        <p class="cwp-hint"><?php esc_html_e('Used as og:image:alt and twitter:image:alt. Falls back to the image\'s WordPress alt text.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

    <!-- X panel -->
    <div class="cwp-social-panel" id="cwp-social-x">
      <div class="cwp-preview">
        <div class="cwp-preview-bar"><h4><?php esc_html_e('X preview', 'mihdan-index-now'); ?></h4></div>
        <div class="cwp-preview-body cwp-og-preview">
          <div class="cwp-x-card">
            <div class="cwp-og-img" <?php if ($x_image_url || $og_image_url || $featured_image_url): ?>style="background-image:url('<?php echo esc_url($x_image_url ?: $og_image_url ?: $featured_image_url); ?>')"<?php endif; ?>>
              <?php if (! $x_image_url && ! $og_image_url && ! $featured_image_url): ?>1200 × 675<?php endif; ?>
            </div>
            <div class="cwp-x-meta">
              <div class="cwp-x-domain"><?php echo esc_html(wp_parse_url($site_url, PHP_URL_HOST)); ?></div>
              <p class="cwp-x-title" id="cwpXTitlePrev"><?php echo esc_html($data['seo_title'] ?: $post_title); ?></p>
              <p class="cwp-x-desc" id="cwpXDescPrev"><?php echo esc_html($data['seo_description']); ?></p>
            </div>
          </div>
        </div>
      </div>

      <div class="cwp-field">
        <label class="cwp-switch">
          <input type="checkbox" id="cwpXSync" name="<?php echo esc_attr(MetaFields::X_SYNC); ?>" value="1" <?php checked($data['x_sync'], '1'); ?>>
          <span class="cwp-switch-ui"></span>
          <span class="cwp-switch-text"><?php esc_html_e('Use the Facebook values', 'mihdan-index-now'); ?></span>
        </label>
      </div>

      <div class="cwp-grid-2">
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpXCard"><?php esc_html_e('Card type', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpXCard" name="<?php echo esc_attr(MetaFields::X_CARD_TYPE); ?>">
            <option value="summary_large_image" <?php selected($data['x_card_type'], 'summary_large_image'); ?>><?php esc_html_e('Summary with large image', 'mihdan-index-now'); ?></option>
            <option value="summary" <?php selected($data['x_card_type'], 'summary'); ?>><?php esc_html_e('Summary', 'mihdan-index-now'); ?></option>
          </select>
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpXCreator"><?php esc_html_e('Author handle', 'mihdan-index-now'); ?></label></div>
          <input class="cwp-input" id="cwpXCreator" name="<?php echo esc_attr(MetaFields::X_CREATOR); ?>" type="text" value="<?php echo esc_attr($data['x_creator']); ?>" placeholder="@username">
        </div>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <label class="cwp-label" for="cwpXTitle"><?php esc_html_e('X title', 'mihdan-index-now'); ?></label>
          <span class="cwp-spacer"></span>
          <?php $cwp_ai_button('cwpXTitle', 'x_title'); ?>
          <div class="cwp-var">
            <button class="cwp-var-btn" type="button" data-var-for="cwpXTitle">
              <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
            </button>
            <div class="cwp-var-menu">
              <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ sep }}"><code>{{ sep }}</code><span><?php esc_html_e('Separator', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.author }}"><code>{{ post.author }}</code><span><?php esc_html_e('Author name', 'mihdan-index-now'); ?></span></button>
              <?php $cwp_wc_var_items(); ?>
            </div>
          </div>
        </div>
        <input class="cwp-input" id="cwpXTitle" name="<?php echo esc_attr(MetaFields::X_TITLE); ?>" type="text" value="<?php echo esc_attr($data['x_title']); ?>" <?php disabled($data['x_sync'], '1'); ?>>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <label class="cwp-label" for="cwpXDesc"><?php esc_html_e('X description', 'mihdan-index-now'); ?></label>
          <span class="cwp-spacer"></span>
          <?php $cwp_ai_button('cwpXDesc', 'x_description'); ?>
          <div class="cwp-var">
            <button class="cwp-var-btn" type="button" data-var-for="cwpXDesc">
              <svg width="10" height="10" viewBox="0 0 10 10"><path d="M5 1v8M1 5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              <?php esc_html_e('Insert variable', 'mihdan-index-now'); ?>
            </button>
            <div class="cwp-var-menu">
              <button class="cwp-var-item" type="button" data-token="{{ post.auto_description }}"><code>{{ post.auto_description }}</code><span><?php esc_html_e('Post excerpt', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.title }}"><code>{{ post.title }}</code><span><?php esc_html_e('Post title', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ site.title }}"><code>{{ site.title }}</code><span><?php esc_html_e('Site name', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ post.category }}"><code>{{ post.category }}</code><span><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></span></button>
              <button class="cwp-var-item" type="button" data-token="{{ current.year }}"><code>{{ current.year }}</code><span><?php esc_html_e('Current year', 'mihdan-index-now'); ?></span></button>
              <?php $cwp_wc_var_items(); ?>
            </div>
          </div>
        </div>
        <textarea class="cwp-textarea" id="cwpXDesc" name="<?php echo esc_attr(MetaFields::X_DESCRIPTION); ?>" <?php disabled($data['x_sync'], '1'); ?>><?php echo esc_textarea($data['x_description']); ?></textarea>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row"><span class="cwp-label"><?php esc_html_e('X image', 'mihdan-index-now'); ?></span></div>
        <div class="cwp-img-picker">
          <div class="cwp-img-thumb" <?php if ($x_image_url): ?>style="background-image:url('<?php echo esc_url($x_image_url); ?>')"<?php endif; ?>></div>
          <input type="hidden" id="cwpXImage" name="<?php echo esc_attr(MetaFields::X_IMAGE); ?>" value="<?php echo esc_attr($data['x_image']); ?>">
          <div class="cwp-img-actions">
            <div class="cwp-img-btns">
              <button class="cwp-btn cwp-img-pick-btn" type="button" data-target="cwpXImage"><?php esc_html_e('Replace image', 'mihdan-index-now'); ?></button>
              <button class="cwp-btn cwp-btn-link cwp-img-remove-btn" type="button" data-target="cwpXImage"><?php esc_html_e('Remove', 'mihdan-index-now'); ?></button>
            </div>
            <p class="cwp-hint" style="margin:0;"><?php esc_html_e('Recommended 1200 × 675 px.', 'mihdan-index-now'); ?></p>
          <div class="cwp-img-dimensions" id="cwpXImgDimensions" hidden></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- SCHEMA -->
  <div class="cwp-panel" id="cwp-panel-schema" role="tabpanel">
    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Structured data', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('CrawlWP outputs this as JSON-LD in the page head. Google, Bing and Yandex all read it.', 'mihdan-index-now'); ?></p>

      <div class="cwp-grid-2">
        <div class="cwp-field">
          <div class="cwp-label-row">
            <label class="cwp-label" for="cwpPageType"><?php esc_html_e('Page type', 'mihdan-index-now'); ?></label>
          </div>
          <select class="cwp-select" id="cwpPageType" name="<?php echo esc_attr(MetaFields::SCHEMA_PAGE_TYPE); ?>">
            <option value="WebPage" <?php selected($data['schema_page_type'], 'WebPage'); ?>><?php esc_html_e('Web Page', 'mihdan-index-now'); ?></option>
            <option value="ItemPage" <?php selected($data['schema_page_type'], 'ItemPage'); ?>><?php esc_html_e('Item Page', 'mihdan-index-now'); ?></option>
            <option value="AboutPage" <?php selected($data['schema_page_type'], 'AboutPage'); ?>><?php esc_html_e('About Page', 'mihdan-index-now'); ?></option>
            <option value="FAQPage" <?php selected($data['schema_page_type'], 'FAQPage'); ?>><?php esc_html_e('FAQ Page', 'mihdan-index-now'); ?></option>
            <option value="QAPage" <?php selected($data['schema_page_type'], 'QAPage'); ?>><?php esc_html_e('Q&amp;A Page', 'mihdan-index-now'); ?></option>
            <option value="ProfilePage" <?php selected($data['schema_page_type'], 'ProfilePage'); ?>><?php esc_html_e('Profile Page', 'mihdan-index-now'); ?></option>
            <option value="ContactPage" <?php selected($data['schema_page_type'], 'ContactPage'); ?>><?php esc_html_e('Contact Page', 'mihdan-index-now'); ?></option>
            <option value="MedicalWebPage" <?php selected($data['schema_page_type'], 'MedicalWebPage'); ?>><?php esc_html_e('Medical Web Page', 'mihdan-index-now'); ?></option>
            <option value="CollectionPage" <?php selected($data['schema_page_type'], 'CollectionPage'); ?>><?php esc_html_e('Collection Page', 'mihdan-index-now'); ?></option>
            <option value="RealEstateListing" <?php selected($data['schema_page_type'], 'RealEstateListing'); ?>><?php esc_html_e('Real Estate Listing', 'mihdan-index-now'); ?></option>
            <option value="none" <?php selected($data['schema_page_type'], 'none'); ?>><?php esc_html_e('None — no structured data', 'mihdan-index-now'); ?></option>
          </select>
          <p class="cwp-hint"><?php esc_html_e('The general type for this page.', 'mihdan-index-now'); ?></p>
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row">
            <label class="cwp-label" for="cwpArticleType"><?php esc_html_e('Article type', 'mihdan-index-now'); ?></label>
          </div>
          <select class="cwp-select" id="cwpArticleType" name="<?php echo esc_attr(MetaFields::SCHEMA_ARTICLE_TYPE); ?>">
            <option value="Article" <?php selected($data['schema_article_type'], 'Article'); ?>><?php esc_html_e('Article', 'mihdan-index-now'); ?></option>
            <option value="BlogPosting" <?php selected($data['schema_article_type'], 'BlogPosting'); ?>><?php esc_html_e('Blog Posting', 'mihdan-index-now'); ?></option>
            <option value="SocialMediaPosting" <?php selected($data['schema_article_type'], 'SocialMediaPosting'); ?>><?php esc_html_e('Social Media Posting', 'mihdan-index-now'); ?></option>
            <option value="NewsArticle" <?php selected($data['schema_article_type'], 'NewsArticle'); ?>><?php esc_html_e('News Article', 'mihdan-index-now'); ?></option>
            <option value="AdvertiserContentArticle" <?php selected($data['schema_article_type'], 'AdvertiserContentArticle'); ?>><?php esc_html_e('Advertiser Content Article', 'mihdan-index-now'); ?></option>
            <option value="SatiricalArticle" <?php selected($data['schema_article_type'], 'SatiricalArticle'); ?>><?php esc_html_e('Satirical Article', 'mihdan-index-now'); ?></option>
            <option value="ScholarlyArticle" <?php selected($data['schema_article_type'], 'ScholarlyArticle'); ?>><?php esc_html_e('Scholarly Article', 'mihdan-index-now'); ?></option>
            <option value="TechArticle" <?php selected($data['schema_article_type'], 'TechArticle'); ?>><?php esc_html_e('Tech Article', 'mihdan-index-now'); ?></option>
            <option value="Report" <?php selected($data['schema_article_type'], 'Report'); ?>><?php esc_html_e('Report', 'mihdan-index-now'); ?></option>
            <option value="none" <?php selected($data['schema_article_type'], 'none'); ?>><?php esc_html_e('None — no article type', 'mihdan-index-now'); ?></option>
          </select>
          <p class="cwp-hint"><?php esc_html_e('Used when the page type is an article. Ignored otherwise.', 'mihdan-index-now'); ?></p>
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpBreadcrumb"><?php esc_html_e('Breadcrumb label', 'mihdan-index-now'); ?></label></div>
          <input class="cwp-input" id="cwpBreadcrumb" name="<?php echo esc_attr(MetaFields::SCHEMA_BREADCRUMB); ?>" type="text" value="<?php echo esc_attr($data['schema_breadcrumb']); ?>" placeholder="<?php echo esc_attr($post_title); ?>">
          <p class="cwp-hint"><?php esc_html_e('Shown in the breadcrumb trail. Defaults to the post title.', 'mihdan-index-now'); ?></p>
          <div class="cwp-breadcrumb-preview" id="cwpBreadcrumbPreview"></div>
        </div>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row"><label class="cwp-label" for="cwpHeadline"><?php esc_html_e('Headline', 'mihdan-index-now'); ?></label></div>
        <input class="cwp-input" id="cwpHeadline" name="<?php echo esc_attr(MetaFields::SCHEMA_HEADLINE); ?>" type="text" value="<?php echo esc_attr($data['schema_headline']); ?>" placeholder="<?php echo esc_attr($post_title); ?>">
        <p class="cwp-hint"><?php esc_html_e('Leave empty to reuse the SEO title.', 'mihdan-index-now'); ?></p>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row"><label class="cwp-label" for="cwpSection"><?php esc_html_e('Article section', 'mihdan-index-now'); ?></label></div>
        <input class="cwp-input" id="cwpSection" name="<?php echo esc_attr(MetaFields::SCHEMA_SECTION); ?>" type="text" value="<?php echo esc_attr($data['schema_section']); ?>" placeholder="<?php esc_attr_e('e.g. Reviews, Tutorials, News', 'mihdan-index-now'); ?>">
        <p class="cwp-hint"><?php esc_html_e('The main category or topic this article belongs to, e.g. "Reviews" or "Tutorials". Only used when the type is an Article.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

    <div class="cwp-section">
      <div class="cwp-field">
        <div class="cwp-label-row"><label class="cwp-label" for="cwpSchemaCustom"><?php esc_html_e('Custom JSON-LD', 'mihdan-index-now'); ?></label></div>
        <textarea class="cwp-textarea" id="cwpSchemaCustom" name="<?php echo esc_attr(MetaFields::SCHEMA_CUSTOM); ?>" rows="4" placeholder="{ &quot;@type&quot;: &quot;WebPage&quot; }"><?php echo esc_textarea($data['schema_custom']); ?></textarea>
      </div>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Output', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('What gets written into the page. Read-only.', 'mihdan-index-now'); ?></p>
      <div class="cwp-code-head">
        <button class="cwp-btn cwp-btn-link" type="button" id="cwpJsonToggle"><?php esc_html_e('Show JSON-LD', 'mihdan-index-now'); ?></button>
      </div>
      <pre class="cwp-code" id="cwpJson" hidden></pre>
    </div>
  </div>

  <!-- LINKS -->
  <div class="cwp-panel" id="cwp-panel-links" role="tabpanel">

    <div class="cwp-linkbar">
      <div class="cwp-linkstat">
        <b id="cwpLinksOut">0</b>
        <span><?php esc_html_e('links out', 'mihdan-index-now'); ?></span>
      </div>
      <div class="cwp-linkstat">
        <b id="cwpLinksIn">0</b>
        <span><?php esc_html_e('links in', 'mihdan-index-now'); ?></span>
      </div>
      <div class="cwp-linkstat">
        <b id="cwpLinksExt">0</b>
        <span><?php esc_html_e('external', 'mihdan-index-now'); ?></span>
      </div>
    </div>

    <div class="cwp-notice cwp-links-notice" id="cwpLinksNotice" hidden>
      <svg width="13" height="13" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.6v4.2M8 11.2v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <span id="cwpLinksNoticeText"></span>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Suggested links', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Related posts you could link to from this content.', 'mihdan-index-now'); ?></p>
      <div class="cwp-linklist" id="cwpSuggestedLinks">
        <p class="cwp-empty-msg" id="cwpSuggestedEmpty"><?php esc_html_e('No suggestions available yet. Add a focus keyword to get link suggestions.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Outbound links in this post', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Internal and external links found in the post content.', 'mihdan-index-now'); ?></p>
      <div class="cwp-linklist" id="cwpOutboundLinks">
        <p class="cwp-empty-msg" id="cwpOutboundEmpty"><?php esc_html_e('No links found in the content yet.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Linking to this post', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Posts on your site that already point here.', 'mihdan-index-now'); ?></p>
      <div class="cwp-linklist" id="cwpInboundLinks">
        <p class="cwp-empty-msg" id="cwpInboundEmpty"><?php esc_html_e('No inbound links found.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

  </div>

  <!-- ADVANCED -->
  <div class="cwp-panel" id="cwp-panel-advanced" role="tabpanel">
    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Search engine visibility', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Applies to Google, Bing and Yandex through the robots meta tag.', 'mihdan-index-now'); ?></p>

      <div class="cwp-grid-2">
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpIndex"><?php esc_html_e('Allow indexing', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpIndex" name="<?php echo esc_attr(MetaFields::ROBOTS_INDEX); ?>">
            <option value="index" <?php selected($data['robots_index'], 'index'); ?>><?php esc_html_e('Yes — index this post (default)', 'mihdan-index-now'); ?></option>
            <option value="noindex" <?php selected($data['robots_index'], 'noindex'); ?>><?php esc_html_e('No — keep it out of search results', 'mihdan-index-now'); ?></option>
          </select>
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpFollow"><?php esc_html_e('Follow links', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpFollow" name="<?php echo esc_attr(MetaFields::ROBOTS_FOLLOW); ?>">
            <option value="follow" <?php selected($data['robots_follow'], 'follow'); ?>><?php esc_html_e('Yes — follow links on this post (default)', 'mihdan-index-now'); ?></option>
            <option value="nofollow" <?php selected($data['robots_follow'], 'nofollow'); ?>><?php esc_html_e('No — don\'t follow links', 'mihdan-index-now'); ?></option>
          </select>
        </div>
      </div>

      <div class="cwp-field">
        <div class="cwp-label-row">
          <span class="cwp-label"><?php esc_html_e('Crawler directives', 'mihdan-index-now'); ?></span>
        </div>
        <div class="cwp-checks">
          <label class="cwp-check"><input type="checkbox" name="<?php echo esc_attr(MetaFields::ROBOTS_ADVANCED); ?>[]" value="noimageindex" <?php checked(in_array('noimageindex', $robots_adv, true)); ?>>
            <span><?php esc_html_e('No image indexing', 'mihdan-index-now'); ?><em>noimageindex</em></span></label>
          <label class="cwp-check"><input type="checkbox" name="<?php echo esc_attr(MetaFields::ROBOTS_ADVANCED); ?>[]" value="noarchive" <?php checked(in_array('noarchive', $robots_adv, true)); ?>>
            <span><?php esc_html_e('No cached copy', 'mihdan-index-now'); ?><em>noarchive</em></span></label>
          <label class="cwp-check"><input type="checkbox" name="<?php echo esc_attr(MetaFields::ROBOTS_ADVANCED); ?>[]" value="nosnippet" <?php checked(in_array('nosnippet', $robots_adv, true)); ?>>
            <span><?php esc_html_e('No snippet', 'mihdan-index-now'); ?><em>nosnippet</em></span></label>
          <label class="cwp-check"><input type="checkbox" name="<?php echo esc_attr(MetaFields::ROBOTS_ADVANCED); ?>[]" value="notranslate" <?php checked(in_array('notranslate', $robots_adv, true)); ?>>
            <span><?php esc_html_e('No translated results', 'mihdan-index-now'); ?><em>notranslate</em></span></label>
        </div>
      </div>

      <div class="cwp-grid-2">
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpMaxSnip"><?php esc_html_e('Snippet length', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpMaxSnip" name="<?php echo esc_attr(MetaFields::MAX_SNIPPET); ?>">
            <option value="" <?php selected($data['max_snippet'], ''); ?>><?php esc_html_e('Let the engine decide', 'mihdan-index-now'); ?></option>
            <option value="none" <?php selected($data['max_snippet'], 'none'); ?>><?php esc_html_e('No text snippet', 'mihdan-index-now'); ?></option>
            <option value="160" <?php selected($data['max_snippet'], '160'); ?>><?php esc_html_e('Up to 160 characters', 'mihdan-index-now'); ?></option>
          </select>
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpMaxImg"><?php esc_html_e('Image preview size', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpMaxImg" name="<?php echo esc_attr(MetaFields::MAX_IMAGE); ?>">
            <option value="large" <?php selected($data['max_image'], 'large'); ?>><?php esc_html_e('Large', 'mihdan-index-now'); ?></option>
            <option value="standard" <?php selected($data['max_image'], 'standard'); ?>><?php esc_html_e('Standard', 'mihdan-index-now'); ?></option>
            <option value="none" <?php selected($data['max_image'], 'none'); ?>><?php esc_html_e('None', 'mihdan-index-now'); ?></option>
          </select>
        </div>
      </div>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Redirect', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Redirect this post to a different URL. Useful when you change slugs or deprecate content.', 'mihdan-index-now'); ?></p>
      <div class="cwp-grid-2">
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpRedirectUrl"><?php esc_html_e('Redirect URL', 'mihdan-index-now'); ?></label></div>
          <input class="cwp-input" id="cwpRedirectUrl" name="<?php echo esc_attr(MetaFields::REDIRECT_URL); ?>" type="url" value="<?php echo esc_attr($data['redirect_url']); ?>" placeholder="https://">
        </div>
        <div class="cwp-field">
          <div class="cwp-label-row"><label class="cwp-label" for="cwpRedirectType"><?php esc_html_e('Redirect type', 'mihdan-index-now'); ?></label></div>
          <select class="cwp-select" id="cwpRedirectType" name="<?php echo esc_attr(MetaFields::REDIRECT_TYPE); ?>">
            <option value="301" <?php selected($data['redirect_type'], '301'); ?>><?php esc_html_e('301 — Permanent', 'mihdan-index-now'); ?></option>
            <option value="302" <?php selected($data['redirect_type'], '302'); ?>><?php esc_html_e('302 — Temporary', 'mihdan-index-now'); ?></option>
            <option value="307" <?php selected($data['redirect_type'], '307'); ?>><?php esc_html_e('307 — Temporary (strict)', 'mihdan-index-now'); ?></option>
            <option value="410" <?php selected($data['redirect_type'], '410'); ?>><?php esc_html_e('410 — Content deleted', 'mihdan-index-now'); ?></option>
            <option value="451" <?php selected($data['redirect_type'], '451'); ?>><?php esc_html_e('451 — Unavailable for legal reasons', 'mihdan-index-now'); ?></option>
          </select>
        </div>
      </div>
      <p class="cwp-hint"><?php esc_html_e('Leave the URL empty to disable the redirect.', 'mihdan-index-now'); ?></p>
    </div>

    <?php if ($categories !== []) : ?>
    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Primary category', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Used for breadcrumbs and category permalink tags.', 'mihdan-index-now'); ?></p>
      <div class="cwp-field">
        <select class="cwp-select" id="cwpPrimaryCat" name="<?php echo esc_attr(MetaFields::PRIMARY_CATEGORY); ?>">
          <option value="0"><?php esc_html_e('Default (first category)', 'mihdan-index-now'); ?></option>
          <?php foreach ($categories as $cat) : ?>
            <option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected((int) $data['primary_category'], (int) $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Canonical URL', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Point engines at the original when this content exists elsewhere.', 'mihdan-index-now'); ?></p>
      <div class="cwp-field">
        <input class="cwp-input" name="<?php echo esc_attr(MetaFields::CANONICAL_URL); ?>" type="url" value="<?php echo esc_attr($data['canonical_url']); ?>" placeholder="<?php echo esc_url($permalink); ?>">
        <p class="cwp-hint"><?php esc_html_e('Leave empty to use the post\'s own permalink.', 'mihdan-index-now'); ?></p>
      </div>
    </div>

    <div class="cwp-section">
      <h4 class="cwp-section-title"><?php esc_html_e('Indexing', 'mihdan-index-now'); ?></h4>
      <p class="cwp-section-desc"><?php esc_html_e('Tell engines about this URL without waiting for the next crawl.', 'mihdan-index-now'); ?></p>
      <button class="cwp-btn cwp-btn-primary" type="button" id="cwpSubmitIndexNow"><?php esc_html_e('Submit for Indexing', 'mihdan-index-now'); ?></button>
    </div>
  </div>

  <!-- ANALYSIS -->
  <div class="cwp-panel" id="cwp-panel-analysis" role="tabpanel">
    <div class="cwp-field">
      <div class="cwp-label-row">
        <label class="cwp-label" for="cwpKeyword"><?php esc_html_e('Focus keywords', 'mihdan-index-now'); ?></label>
        <span class="cwp-help" title="<?php esc_attr_e('Comma-separated. The first phrase is the primary keyword; additional phrases are secondary keywords.', 'mihdan-index-now'); ?>">?</span>
      </div>
      <input class="cwp-input" id="cwpKeyword" name="<?php echo esc_attr(MetaFields::FOCUS_KEYWORD); ?>" type="text" value="<?php echo esc_attr($data['focus_keyword']); ?>" placeholder="<?php esc_attr_e('primary keyword, secondary keyword', 'mihdan-index-now'); ?>">
      <p class="cwp-hint"><?php esc_html_e('Separate secondary keywords with commas. Click each keyword tab below to inspect its individual score.', 'mihdan-index-now'); ?></p>
      <div class="cwp-kw-warning" id="cwpKwWarning" style="display:none">
        <svg width="13" height="13" viewBox="0 0 16 16"><path d="M8 1l7 14H1z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M8 6.5v3.5M8 12v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span id="cwpKwWarningText"></span>
      </div>
    </div>

    <div class="cwp-kw-tabs" id="cwpKwTabs" style="display:none" role="tablist"></div>

    <div class="cwp-notice" id="cwpAnalysisNotice">
      <svg width="13" height="13" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.6v4.2M8 11.2v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <span id="cwpAnalysisNoticeText"><?php esc_html_e('Enter a focus keyword above to run the analysis.', 'mihdan-index-now'); ?></span>
    </div>

    <div class="cwp-checklist" id="cwpChecklist"></div>
  </div>

  <?php if (! defined('CRAWLWP_PRO_VERSION')) : ?>
  <!-- INSIGHTS (upsell — the real panel is rendered by mihdan-index-now-pro
       via the crawlwp_metabox_panels hook below when that plugin is active) -->
  <div class="cwp-panel" id="cwp-panel-insights" role="tabpanel">
    <div class="cwp-insights-upsell">
      <div class="cwp-insights-upsell__icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l3-4 3 2 5-7"/></svg>
      </div>
      <h4 class="cwp-insights-upsell__title"><?php esc_html_e('Search performance insights, right in this editor', 'mihdan-index-now'); ?></h4>
      <p class="cwp-insights-upsell__desc"><?php esc_html_e('See this page\'s real clicks, impressions, average position and indexing status across Google, Bing and Yandex, plus its top search queries, without ever leaving the post editor.', 'mihdan-index-now'); ?></p>
      <ul class="cwp-insights-upsell__list">
        <li><?php esc_html_e('Clicks, impressions, average position and CTR for every search engine', 'mihdan-index-now'); ?></li>
        <li><?php esc_html_e('Top search queries driving traffic to this exact page', 'mihdan-index-now'); ?></li>
        <li><?php esc_html_e('Live indexing status and last crawl date for Google, Bing and Yandex', 'mihdan-index-now'); ?></li>
      </ul>
      <a class="cwp-btn cwp-btn-primary cwp-insights-upsell__cta" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url('https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=crawlwp-metabox-insights-upsell'); ?>">
        <?php esc_html_e('Upgrade to CrawlWP Premium', 'mihdan-index-now'); ?>
      </a>
      <p class="cwp-insights-upsell__note"><?php esc_html_e('Already have CrawlWP Premium? Make sure it\'s activated to see real data here.', 'mihdan-index-now'); ?></p>
    </div>
  </div>
  <?php endif; ?>

  <?php
  /**
   * Fires after the last built-in SEO metabox panel ("Advanced").
   *
   * Add-on plugins (e.g. mihdan-index-now-pro) can use this to render
   * their own `<div class="cwp-panel" id="cwp-panel-{id}">` panel(s) that
   * pair with a tab button added via the `crawlwp_metabox_tabs` action.
   *
   * @param \WP_Post $post The post being edited.
   * @param array    $data The metabox's resolved field data.
   */
  do_action('crawlwp_metabox_panels', $post, $data);
  ?>

  <!-- Hidden field: JS fills this with the analysis score on every keystroke/save.
       Always starts empty — the value is only meaningful when JS has run the
       full in-editor analysis.  persist_score() ignores empty submissions so
       the post list falls back to a live PHP approximation instead. -->
  <input type="hidden" id="cwpSeoScoreCache" name="<?php echo esc_attr(MetaFields::SEO_SCORE); ?>" value="">

  <!-- FOOTER -->
  <div class="cwp-mb-foot">
    <span><?php esc_html_e('Changes save with the post.', 'mihdan-index-now'); ?></span>
    <span class="cwp-spacer"></span>
    <a class="cwp-btn cwp-btn-link" href="<?php echo esc_url(admin_url('admin.php?page=crawlwp')); ?>"><?php esc_html_e('Open CrawlWP settings', 'mihdan-index-now'); ?></a>
  </div>

</div>
