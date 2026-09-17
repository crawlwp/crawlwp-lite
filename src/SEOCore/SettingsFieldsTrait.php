<?php

namespace Mihdan\IndexNow\SEOCore;

use Mihdan\IndexNow\Views\WPOSA;

/**
 * Presentation helpers shared by the CrawlWP settings screens.
 *
 * Every settings class used to carry its own private copy of these two
 * helpers, so a markup or class-name change had to be repeated in each file.
 *
 * Used by CoreSettings, SiteInfoSettings, SocialSettings, RssSettings and
 * SitemapSettings. Any other settings class needing the same sub-heading
 * markup should adopt this trait instead of re-implementing it.
 */
trait SettingsFieldsTrait
{
	/**
	 * A full-width sub-heading inside a settings screen.
	 *
	 * @param WPOSA  $wposa      Settings API instance.
	 * @param string $section    Section id the heading belongs to.
	 * @param string $id         Unique field id.
	 * @param string $title      Heading text.
	 * @param string $desc       Optional copy rendered below the heading.
	 * @param bool   $allow_html Allow safe HTML (e.g. a link) in $desc. Callers
	 *                           passing true must escape their own markup.
	 */
	private function add_heading(WPOSA $wposa, string $section, string $id, string $title, string $desc = '', bool $allow_html = false): void
	{
		$html = sprintf('<h3 class="cwp-tm-subheading">%s</h3>', esc_html($title));

		if ($desc !== '') {
			$html .= sprintf('<p class="description">%s</p>', $allow_html ? wp_kses_post($desc) : esc_html($desc));
		}

		$wposa->add_field($section, [
			'id'    => $id,
			'type'  => 'html',
			'name'  => '',
			'desc'  => $html,
			'class' => 'wposa-form-table__row cwp-tm-heading-row',
		]);
	}

	/**
	 * Wrap helper copy so it renders below a switch instead of beside it.
	 */
	private function description(string $text): string
	{
		return sprintf('<p class="description">%s</p>', esc_html($text));
	}
}
