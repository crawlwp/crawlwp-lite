<?php

namespace Mihdan\IndexNow\SEOCore\MetaBox;

/**
 * Shared sanitisation/mapping layer for the SEO fields.
 *
 * Both {@see MetaFields::save()} (post meta) and
 * {@see \Mihdan\IndexNow\SEOCore\TermSEO\TermFields::save()} (term meta) feed
 * their field definitions through {@see self::process()}, so a rule added or
 * tightened here applies to posts and terms alike and the two savers cannot
 * drift apart.
 *
 * A definition is `meta_key => spec`, where spec is:
 *
 *   type     string  One of the self::TYPE_* constants. Required.
 *   allowed  array   Allowed values (TYPE_SELECT, TYPE_MULTI_SELECT).
 *   fallback mixed   Stored when the submitted value is not allowed (TYPE_SELECT).
 *   always   bool    Write the field even when it is absent from the request.
 *                    Used by checkbox/multi-select fields, whose "off" state is
 *                    an absent request key. Never enable it for a field unless
 *                    the request is known to come from a form that renders it.
 */
class FieldProcessor
{
	public const TYPE_TEXT         = 'text';
	public const TYPE_TEXTAREA     = 'textarea';
	public const TYPE_URL          = 'url';
	public const TYPE_SELECT       = 'select';
	public const TYPE_MULTI_SELECT = 'multi_select';
	public const TYPE_CHECKBOX     = 'checkbox';
	public const TYPE_INT          = 'int';
	public const TYPE_JSON         = 'json';

	/**
	 * Sanitise a request against a field definition list.
	 *
	 * @param array<string, array<string, mixed>> $fields  Field definitions, keyed by meta key.
	 * @param array<string, mixed>                $request Raw (still slashed) request data, usually $_POST.
	 * @return array<string, mixed> Sanitised values keyed by meta key. Only keys the
	 *                              caller should write are present.
	 */
	public static function process(array $fields, array $request): array
	{
		$values = [];

		foreach ($fields as $key => $spec) {
			$type    = (string) ($spec['type'] ?? self::TYPE_TEXT);
			$always  = ! empty($spec['always']);
			$present = isset($request[$key]);

			if (! $present && ! $always) {
				continue;
			}

			$raw = $present ? $request[$key] : null;

			switch ($type) {
				case self::TYPE_TEXT:
					$values[$key] = self::text($raw);
					break;

				case self::TYPE_TEXTAREA:
					$values[$key] = self::textarea($raw);
					break;

				case self::TYPE_URL:
					$values[$key] = MetaFields::sanitize_url(wp_unslash($raw));
					break;

				case self::TYPE_SELECT:
					$values[$key] = self::select(
						$raw,
						is_array($spec['allowed'] ?? null) ? $spec['allowed'] : [],
						$spec['fallback'] ?? ''
					);
					break;

				case self::TYPE_MULTI_SELECT:
					$values[$key] = self::multi_select(
						$raw,
						is_array($spec['allowed'] ?? null) ? $spec['allowed'] : []
					);
					break;

				case self::TYPE_CHECKBOX:
					$values[$key] = $present ? '1' : '0';
					break;

				case self::TYPE_INT:
					$values[$key] = absint($raw);
					break;

				case self::TYPE_JSON:
					$values[$key] = self::json($raw);
					break;
			}
		}

		return $values;
	}

	public static function text($raw): string
	{
		return sanitize_text_field(wp_unslash((string) $raw));
	}

	public static function textarea($raw): string
	{
		return sanitize_textarea_field(wp_unslash((string) $raw));
	}

	/**
	 * @param mixed $raw
	 * @param array<int, string> $allowed
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function select($raw, array $allowed, $fallback = '')
	{
		$value = self::text($raw);

		return in_array($value, $allowed, true) ? $value : $fallback;
	}

	/**
	 * @param mixed $raw
	 * @param array<int, string> $allowed
	 * @return array<int, string>
	 */
	public static function multi_select($raw, array $allowed): array
	{
		if (! is_array($raw)) {
			return [];
		}

		$sanitized = array_map('sanitize_text_field', wp_unslash($raw));

		return array_values(array_intersect($sanitized, $allowed));
	}

	/**
	 * Validate *and* value-sanitise a JSON payload.
	 *
	 * Invalid JSON is discarded. Valid JSON is decoded, every string key and
	 * value is run through wp_strip_all_tags() recursively, and the result is
	 * re-encoded — so markup pasted into a schema field can never reach the
	 * front end inside the JSON-LD block.
	 *
	 * @param mixed $raw
	 */
	public static function json($raw): string
	{
		$json = trim((string) wp_unslash((string) $raw));

		if ($json === '') {
			return '';
		}

		$decoded = json_decode($json, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return '';
		}

		$encoded = wp_json_encode(self::strip_tags_deep($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return is_string($encoded) ? $encoded : '';
	}

	/**
	 * Strip tags from every string key/value of an arbitrarily nested value.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function strip_tags_deep($value)
	{
		if (is_array($value)) {
			$out = [];

			foreach ($value as $key => $item) {
				$clean_key = is_string($key) ? wp_strip_all_tags($key) : $key;

				$out[$clean_key] = self::strip_tags_deep($item);
			}

			return $out;
		}

		if (is_string($value)) {
			return wp_strip_all_tags($value);
		}

		return $value;
	}
}
