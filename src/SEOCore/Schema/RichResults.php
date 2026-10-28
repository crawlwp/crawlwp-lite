<?php
/**
 * Rich result structured data per post (Product, Recipe, Event...).
 *
 * @package mihdan-index-now
 */

namespace Mihdan\IndexNow\SEOCore\Schema;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use Mihdan\IndexNow\SEOCore\SiteInfoSettings\SiteInfoSettings;

/**
 * One rich result type per post, chosen in the Schema tab and stored as JSON
 * ({"type": "...", "fields": {...}}) in MetaFields::SCHEMA_RICH.
 *
 * Types are definitions (label, fields, build callback). Free types live in
 * {@see RichResultTypes}; Premium registers more through `crawlwp_rich_result_types`.
 * A type without a build callback is shown locked with an upgrade prompt.
 */
class RichResults
{
	/**
	 * All rich result types, keyed by id.
	 *
	 * Type keys: label, pitch (locked types), hint, fields, build (callable|null),
	 * required_any (list of field-key groups; at least one group must be complete),
	 * required_any_message.
	 * Field keys: label, type (text|textarea|list|url|number|currency|datetime|date|minutes|duration|select),
	 * required (bool, ['field' => key, 'in' => values] or ['field' => key, 'nonzero' => true]),
	 * empty (label of a select's empty option when empty means a default, e.g. "Scheduled"), options, default (title|description|image|url|date|author|organization|site),
	 * placeholder, hint, group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function types(): array
	{
		static $types = null;

		if ($types === null) {
			$types = (array) apply_filters('crawlwp_rich_result_types', RichResultTypes::all());
		}

		return $types;
	}

	public static function is_available(string $type): bool
	{
		$types = self::types();

		return isset($types[$type]) && is_callable($types[$type]['build'] ?? null);
	}

	/**
	 * Types offered for a post. WooCommerce products already get Product data, so Product is left out there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function types_for(\WP_Post $post): array
	{
		$types = self::types();

		if ($post->post_type === 'product' && function_exists('WC')) {
			unset($types['Product']);
		}

		return $types;
	}

	/**
	 * Stored choice for a post.
	 *
	 * @return array{type: string, fields: array<string, string>}
	 */
	public static function get(int $post_id): array
	{
		return self::decode((string) MetaFields::get($post_id, MetaFields::SCHEMA_RICH, ''));
	}

	/**
	 * @return array{type: string, fields: array<string, string>}
	 */
	public static function decode(string $json): array
	{
		$data = json_decode($json, true);

		if (! is_array($data) || empty($data['type']) || ! is_string($data['type'])) {
			return ['type' => '', 'fields' => []];
		}

		$fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];

		return [
			'type'   => $data['type'],
			'fields' => array_map('strval', array_filter($fields, 'is_scalar')),
		];
	}

	/**
	 * Sanitise the submitted JSON against the type's field definitions.
	 * Unknown types and fields are dropped; an empty choice stores ''.
	 */
	public static function sanitize(string $json, string $current = ''): string
	{
		$data  = self::decode($json);
		$types = self::types();

		if (! isset($types[$data['type']])) {
			return '';
		}

		/*
		 * A locked type (Premium inactive) has no field definitions here, so the editor
		 * can't send its values: keep what is stored instead of wiping it.
		 */
		if (! is_callable($types[$data['type']]['build'] ?? null)) {
			return self::decode($current)['type'] === $data['type'] ? $current : '';
		}

		$clean = [];

		foreach ($types[$data['type']]['fields'] as $key => $field) {

			$value = self::clean_value($data['fields'][$key] ?? '', $field);

			if ($value !== '') {
				$clean[$key] = $value;
			}
		}

		$encoded = wp_json_encode(['type' => $data['type'], 'fields' => (object) $clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return is_string($encoded) ? $encoded : '';
	}

	/**
	 * Clean one value for storage (the form's own format, e.g. "2026-11-18T14:00").
	 *
	 * @param mixed $raw
	 */
	public static function clean_value($raw, array $field): string
	{
		$raw = trim((string) $raw);

		if ($raw === '') {
			return '';
		}

		switch ($field['type'] ?? 'text') {
			case 'textarea':
			case 'list':
				return sanitize_textarea_field($raw);
			case 'url':
				return (string) esc_url_raw($raw, ['http', 'https']);
			case 'number':
				/* Finite and within the field's range: "1e999" would be stored as INF and break the page. */
				if (! is_numeric($raw) || ! is_finite((float) $raw)) {
					return '';
				}

				if ((isset($field['min']) && (float) $raw < $field['min']) || (isset($field['max']) && (float) $raw > $field['max'])) {
					return '';
				}

				return (string) self::number($raw);
			case 'minutes':
				return ctype_digit($raw) ? (string) (int) $raw : '';
			case 'currency':
				$raw = strtoupper($raw);
				return preg_match('/^[A-Z]{3}$/', $raw) ? $raw : '';
			case 'datetime':
				return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) ? $raw : '';
			case 'date':
				return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
			case 'duration':
				return preg_match('/^(\d{1,2}:)?\d{1,2}:\d{2}$/', $raw) ? $raw : '';
			case 'select':
				return array_key_exists($raw, (array) ($field['options'] ?? [])) ? $raw : '';
			default:
				return mb_substr(sanitize_text_field($raw), 0, 500);
		}
	}

	/**
	 * Values used when a field is left empty, per default token.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(\WP_Post $post): array
	{
		$image = (string) get_the_post_thumbnail_url($post, 'full');

		$description = (string) MetaFields::get($post->ID, MetaFields::SEO_DESCRIPTION, '');
		if ($description === '' || strpos($description, '{{') !== false) {
			$description = has_excerpt($post) ? (string) get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags((string) $post->post_content), 30, '…');
		}

		$author = get_userdata((int) $post->post_author);

		$organization = (string) SiteInfoSettings::get('site_name', '');
		if ($organization === '') {
			$organization = (string) get_bloginfo('name');
		}

		return [
			'title'        => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
			'description'  => html_entity_decode(trim($description), ENT_QUOTES, 'UTF-8'),
			'image'        => $image,
			'url'          => (string) get_permalink($post),
			'date'         => (string) get_the_date('Y-m-d', $post),
			'author'       => $author ? (string) $author->display_name : '',
			'organization' => $organization,
			'site'         => home_url('/'),
		];
	}

	/**
	 * Field values with defaults filled in.
	 *
	 * @return array<string, string>
	 */
	public static function values(array $type, array $stored, array $defaults): array
	{
		$values = [];

		foreach ($type['fields'] as $key => $field) {

			$value = (string) ($stored[$key] ?? '');

			if ($value === '' && ! empty($field['default'])) {
				$value = (string) ($defaults[$field['default']] ?? '');
			}

			$values[$key] = $value;
		}

		return $values;
	}

	/**
	 * Labels of required fields that are still empty, and the "one of" rule message when it fails.
	 *
	 * @return string[]
	 */
	public static function missing(array $type, array $values): array
	{
		$missing = [];

		foreach ($type['fields'] as $key => $field) {
			if (self::is_required($field, $values) && ($values[$key] ?? '') === '') {
				$missing[] = (string) $field['label'];
			}
		}

		if (! empty($type['required_any'])) {

			$met = false;

			foreach ($type['required_any'] as $group) {
				$met = $met || array_reduce((array) $group, function ($carry, $key) use ($values) {
					return $carry && ($values[$key] ?? '') !== '';
				}, true);
			}

			if (! $met) {
				$missing[] = (string) ($type['required_any_message'] ?? '');
			}
		}

		return array_values(array_filter($missing));
	}

	/**
	 * @param array<string, string> $values
	 */
	public static function is_required(array $field, array $values): bool
	{
		$required = $field['required'] ?? false;

		if (is_array($required)) {
			$other = (string) ($values[$required['field']] ?? '');

			if (! empty($required['nonzero'])) {
				return $other !== '' && (float) $other != 0;
			}

			return in_array($other, (array) ($required['in'] ?? []), true);
		}

		return (bool) $required;
	}

	/**
	 * JSON-LD node for the post's rich result, or none when the type isn't
	 * available or a required field is missing (Google would reject it).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function nodes_for_post(\WP_Post $post, string $webpage_id = ''): array
	{
		return self::build($post, self::get($post->ID), $webpage_id)['nodes'];
	}

	/**
	 * What the post would output for unsaved rich result values, for the
	 * Schema tab preview. Uses the same code as the page output.
	 *
	 * @param array{type: string, fields: array<string, string>} $stored
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, missing: string[], label: string}
	 */
	public static function preview(\WP_Post $post, array $stored, string $webpage_id = ''): array
	{
		return self::build($post, $stored, $webpage_id);
	}

	/**
	 * @param array{type: string, fields: array<string, string>} $stored
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, missing: string[], label: string}
	 */
	private static function build(\WP_Post $post, array $stored, string $webpage_id): array
	{
		$none  = ['nodes' => [], 'missing' => [], 'label' => ''];
		$types = self::types_for($post);

		if ($stored['type'] === '' || ! isset($types[$stored['type']]) || ! self::is_available($stored['type'])) {
			return $none;
		}

		$type     = $types[$stored['type']];
		$defaults = self::defaults($post);
		$values   = self::values($type, $stored['fields'], $defaults);
		$missing  = self::missing($type, $values);
		$none['label'] = (string) $type['label'];

		if ($missing !== []) {
			return ['missing' => $missing] + $none;
		}

		$url = $defaults['url'];

		$context = [
			'post'       => $post,
			'id'         => ($webpage_id !== '' ? strtok($webpage_id, '#') : $url) . '#richresult',
			'webpage_id' => $webpage_id !== '' ? $webpage_id : $url . '#webpage',
			'url'        => $url,
			'defaults'   => $defaults,
		];

		$node = call_user_func($type['build'], $values, $context);

		if (! is_array($node) || empty($node['@type'])) {
			return $none;
		}

		$node = ['@id' => $context['id']] + $node;

		/**
		 * Filter the rich result node built for a post.
		 *
		 * @param array    $node    JSON-LD node.
		 * @param string   $type    Rich result type id.
		 * @param array    $values  Field values, defaults filled in.
		 * @param \WP_Post $post    The post.
		 */
		$node = (array) apply_filters('crawlwp_rich_result_node', $node, $stored['type'], $values, $post);

		return ['nodes' => [self::prune($node)]] + $none;
	}

	/**
	 * Drop empty values (recursively) so optional fields left blank don't print.
	 */
	public static function prune(array $node): array
	{
		foreach ($node as $key => $value) {

			if (is_array($value)) {
				$value = self::prune($value);
				$value = array_keys($value) === ['@type'] ? [] : $value;
			}

			if ($value === '' || $value === null || $value === []) {
				unset($node[$key]);
			} else {
				$node[$key] = $value;
			}
		}

		return $node;
	}

	/* ---- Helpers for build callbacks ---- */

	/**
	 * ISO 8601 date-time in the site's time zone, from the form's "YYYY-MM-DDTHH:MM".
	 */
	public static function iso_datetime(string $value): string
	{
		if ($value === '') {
			return '';
		}

		$date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, wp_timezone());

		return $date ? $date->format('c') : '';
	}

	/**
	 * Minutes to an ISO 8601 duration (PT1H30M).
	 */
	public static function iso_minutes(string $minutes): string
	{
		$minutes = (int) $minutes;

		if ($minutes <= 0) {
			return '';
		}

		$hours = intdiv($minutes, 60);
		$rest  = $minutes % 60;

		return 'PT' . ($hours ? $hours . 'H' : '') . ($rest ? $rest . 'M' : '');
	}

	/**
	 * "mm:ss" or "hh:mm:ss" to an ISO 8601 duration.
	 */
	public static function iso_duration(string $value): string
	{
		if ($value === '') {
			return '';
		}

		$parts = array_map('intval', explode(':', $value));

		if (count($parts) === 2) {
			array_unshift($parts, 0);
		}

		[$h, $m, $s] = $parts;

		$out = ($h ? $h . 'H' : '') . ($m ? $m . 'M' : '') . ($s ? $s . 'S' : '');

		return $out === '' ? '' : 'PT' . $out;
	}

	/**
	 * Non-empty lines of a list field.
	 *
	 * @return string[]
	 */
	public static function lines(string $value): array
	{
		return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
	}

	/**
	 * A stored number as int or float for the JSON-LD; 0 for anything not finite.
	 *
	 * @param mixed $value
	 *
	 * @return int|float
	 */
	public static function number($value)
	{
		$value = trim((string) $value);

		if (! is_numeric($value) || ! is_finite((float) $value)) {
			return 0;
		}

		return 0 + $value;
	}

	/**
	 * AggregateRating node, or [] without both value and count.
	 */
	public static function aggregate_rating(string $value, string $count): array
	{
		if ($value === '' || $count === '' || (int) $count < 1) {
			return [];
		}

		return [
			'@type'       => 'AggregateRating',
			'ratingValue' => self::number($value),
			'ratingCount' => (int) $count,
			'bestRating'  => 5,
		];
	}

	/**
	 * Offer node, or [] without a price.
	 */
	public static function offer(string $price, string $currency, string $url = '', string $availability = ''): array
	{
		if ($price === '') {
			return [];
		}

		return [
			'@type'         => 'Offer',
			'price'         => self::number($price),
			'priceCurrency' => $currency,
			'availability'  => $availability !== '' ? 'https://schema.org/' . $availability : '',
			'url'           => $url,
		];
	}

	/**
	 * Schema.org address from one line of text ("street, city, postcode, country").
	 */
	public static function address(string $value): array
	{
		return $value === '' ? [] : ['@type' => 'PostalAddress', 'streetAddress' => $value];
	}

	/**
	 * Data the metabox needs: type definitions without callbacks, defaults, the post's choice.
	 */
	public static function editor_data(\WP_Post $post): array
	{
		$types = [];

		foreach (self::types_for($post) as $id => $type) {
			$types[$id] = [
				'label'              => (string) ($type['label'] ?? $id),
				'premium'            => ! empty($type['premium']),
				'locked'             => ! is_callable($type['build'] ?? null),
				'pitch'              => (string) ($type['pitch'] ?? ''),
				'hint'               => (string) ($type['hint'] ?? ''),
				'fields'             => $type['fields'] ?? [],
				'requiredAny'        => $type['required_any'] ?? [],
				'requiredAnyMessage' => (string) ($type['required_any_message'] ?? ''),
			];
		}

		$defaults   = self::defaults($post);
		$pro_active = (bool) apply_filters('crawlwp_is_pro_active', defined('CRAWLWP_DETACH_LIBSODIUM'));

		return [
			'types'      => $types,
			'value'      => self::get($post->ID),
			'defaults'   => $defaults,
			'testUrl'    => 'https://search.google.com/test/rich-results?url=' . rawurlencode($defaults['url']),
			'upgradeUrl' => $pro_active ? admin_url('plugins.php') : 'https://crawlwp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=rich-results',
			'proActive'  => $pro_active,
			'i18n'       => [
				'type'        => __('Type', 'mihdan-index-now'),
				'none'        => __('None', 'mihdan-index-now'),
				'choose'      => __('Choose…', 'mihdan-index-now'),
				'free'        => __('Free', 'mihdan-index-now'),
				'premium'     => __('Premium', 'mihdan-index-now'),
				'required'    => __('Required by Google', 'mihdan-index-now'),
				'ready'       => __('Ready for rich results.', 'mihdan-index-now'),
				'readyText'   => __('Every field Google requires is filled in.', 'mihdan-index-now'),
				'missing'     => __('Not output yet. Google needs:', 'mihdan-index-now'),
				'test'        => __('Test this page in Google\'s Rich Results Test ↗', 'mihdan-index-now'),
				'faqNote'     => __('FAQ and How-to are not listed because Google no longer shows them as rich results. You can still add them with Custom JSON-LD below.', 'mihdan-index-now'),
				/* translators: %s: rich result type */
				'lockedTitle' => __('%s is a Premium type', 'mihdan-index-now'),
				'upgrade'     => __('Upgrade to Premium', 'mihdan-index-now'),
				'update'      => __('Update CrawlWP Premium to use it', 'mihdan-index-now'),
				'notNumber'   => __('Not a number, so it won\'t be saved.', 'mihdan-index-now'),
				'wholeNumber' => __('Use a whole number of minutes. This won\'t be saved.', 'mihdan-index-now'),
				/* translators: 1: lowest allowed number, 2: highest allowed number */
				'between'     => __('Use a number from %1$s to %2$s. This won\'t be saved.', 'mihdan-index-now'),
				/* translators: %s: lowest allowed number */
				'atLeast'     => __('Use %s or more. This won\'t be saved.', 'mihdan-index-now'),
				/* translators: %s: highest allowed number */
				'atMost'      => __('Use %s or less. This won\'t be saved.', 'mihdan-index-now'),
			],
		];
	}
}
