<?php

namespace Mihdan\IndexNow\SEOCore\LocalSeo;

/**
 * Weekly opening hours: the editor, cleaning, Google's openingHoursSpecification
 * and the text shown to visitors.
 *
 * Stored per day as up to two time ranges, e.g.
 *   ['mon' => [['09:00', '13:00'], ['14:00', '17:00']], 'sun' => []]
 * An empty list is a closed day. Used by the Local SEO settings and by
 * Premium's locations.
 */
class OpeningHours
{
	public const DAYS = [
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	];

	/** Time ranges per day: a lunch break needs two. */
	private const RANGES = 2;

	/**
	 * Translated day names.
	 *
	 * @return array<string, string>
	 */
	public static function day_labels(): array
	{
		return [
			'mon' => __('Monday', 'mihdan-index-now'),
			'tue' => __('Tuesday', 'mihdan-index-now'),
			'wed' => __('Wednesday', 'mihdan-index-now'),
			'thu' => __('Thursday', 'mihdan-index-now'),
			'fri' => __('Friday', 'mihdan-index-now'),
			'sat' => __('Saturday', 'mihdan-index-now'),
			'sun' => __('Sunday', 'mihdan-index-now'),
		];
	}

	/**
	 * Clean hours as posted by the editor (or as stored).
	 *
	 * Posted shape: [day => ['open' => '1', 'ranges' => [[from, to], …]]].
	 *
	 * @param mixed $raw
	 *
	 * @return array<string, array<int, array{0:string, 1:string}>>|null Null when nothing was entered.
	 */
	public static function sanitize($raw): ?array
	{
		if (! is_array($raw)) {
			return null;
		}

		$hours = [];
		$any   = false;

		foreach (array_keys(self::DAYS) as $day) {
			$hours[$day] = [];
			$entry       = is_array($raw[$day] ?? null) ? $raw[$day] : [];

			if (array_key_exists('open', $entry)) {
				/* As posted by the editor: a switch per day. */
				if (empty($entry['open'])) {
					continue;
				}

				$ranges = (array) ($entry['ranges'] ?? []);
			} else {
				/* As stored: a plain list of ranges. */
				$ranges = $entry;
			}

			foreach (array_slice($ranges, 0, self::RANGES) as $range) {
				$from = self::time(is_array($range) ? (string) ($range[0] ?? '') : '');
				$to   = self::time(is_array($range) ? (string) ($range[1] ?? '') : '');

				if ($from !== '' && $to !== '') {
					$hours[$day][] = [$from, $to];
					$any           = true;
				}
			}
		}

		return $any ? $hours : null;
	}

	/**
	 * "9:5" → "09:05"; anything else → "".
	 */
	public static function time(string $value): string
	{
		if (! preg_match('/^\s*(\d{1,2}):(\d{2})/', $value, $m)) {
			return '';
		}

		$h = (int) $m[1];
		$i = (int) $m[2];

		/* 24:00 is allowed as a closing time ("open until midnight"). */
		if ($h > 24 || $i > 59 || ($h === 24 && $i > 0)) {
			return '';
		}

		return sprintf('%02d:%02d', $h, $i);
	}

	/**
	 * openingHoursSpecification entries, one per set of days sharing a time range.
	 *
	 * @param array<string, array<int, array{0:string, 1:string}>> $hours
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_schema(array $hours): array
	{
		$groups = [];

		foreach (self::DAYS as $day => $name) {
			foreach ($hours[$day] ?? [] as [$from, $to]) {
				$key = $from . '-' . $to;

				$groups[$key]['opens']       = $from;
				$groups[$key]['closes']      = $to === '24:00' ? '23:59' : $to;
				$groups[$key]['dayOfWeek'][] = $name;
			}
		}

		$specs = [];

		foreach ($groups as $group) {
			$specs[] = [
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => count($group['dayOfWeek']) === 1 ? $group['dayOfWeek'][0] : $group['dayOfWeek'],
				'opens'     => $group['opens'],
				'closes'    => $group['closes'],
			];
		}

		return $specs;
	}

	/**
	 * Days grouped for display: "Monday – Thursday: 9:00 am – 5:00 pm".
	 *
	 * @param array<string, array<int, array{0:string, 1:string}>> $hours
	 *
	 * @return array<int, array{days:string, hours:string}>
	 */
	public static function display_rows(array $hours): array
	{
		$labels = self::day_labels();
		$rows   = [];
		$prev   = null;

		foreach (array_keys(self::DAYS) as $day) {
			$text = self::ranges_text($hours[$day] ?? []);

			if ($prev !== null && $rows[$prev]['hours'] === $text) {
				$rows[$prev]['last'] = $labels[$day];
				continue;
			}

			$rows[] = ['first' => $labels[$day], 'last' => '', 'hours' => $text];
			$prev   = count($rows) - 1;
		}

		return array_map(static function (array $row): array {
			return [
				/* translators: 1: first day, 2: last day of a run of days with the same hours */
				'days'  => $row['last'] !== '' ? sprintf(__('%1$s – %2$s', 'mihdan-index-now'), $row['first'], $row['last']) : $row['first'],
				'hours' => $row['hours'],
			];
		}, $rows);
	}

	/**
	 * "9:00 am – 1:00 pm, 2:00 pm – 5:00 pm", or "Closed".
	 *
	 * @param array<int, array{0:string, 1:string}> $ranges
	 */
	public static function ranges_text(array $ranges): string
	{
		if ($ranges === []) {
			return __('Closed', 'mihdan-index-now');
		}

		$format = (string) get_option('time_format', 'g:i a');
		$parts  = [];

		foreach ($ranges as [$from, $to]) {
			if ($from === '00:00' && in_array($to, ['23:59', '24:00'], true)) {
				$parts[] = __('Open 24 hours', 'mihdan-index-now');
				continue;
			}

			/* translators: 1: opening time, 2: closing time */
			$parts[] = sprintf(__('%1$s – %2$s', 'mihdan-index-now'), self::format_time($from, $format), self::format_time($to, $format));
		}

		return implode(', ', $parts);
	}

	private static function format_time(string $time, string $format): string
	{
		if ($time === '24:00') {
			$time = '23:59';
		}

		$date = \DateTime::createFromFormat('H:i', $time, new \DateTimeZone('UTC'));

		return $date ? $date->format($format) : $time;
	}

	/**
	 * The hours editor: a row per day with an open switch and up to two time ranges.
	 *
	 * @param string                                                     $name  Field name prefix, e.g. crawlwp_local_seo[hours].
	 * @param array<string, array<int, array{0:string, 1:string}>>|null $hours Stored hours; null when none are set.
	 */
	public static function editor(string $name, ?array $hours): string
	{
		$html = '<div class="cwp-hours" data-cwp-hours>';

		foreach (self::day_labels() as $day => $label) {
			$ranges = $hours[$day] ?? [];
			/* Days start unticked, so nothing is sent to Google until hours are set; ticking one fills in 9–5. */
			$open = $ranges !== [];

			if ($ranges === []) {
				$ranges = [['09:00', '17:00']];
			}

			$field = $name . '[' . $day . ']';
			$html .= sprintf(
				'<div class="cwp-hours__day%1$s"><label class="cwp-hours__name"><input type="hidden" name="%2$s[open]" value="0"><input type="checkbox" class="cwp-hours__open" name="%2$s[open]" value="1"%3$s> %4$s</label><div class="cwp-hours__ranges">',
				$open ? '' : ' is-closed',
				esc_attr($field),
				checked($open, true, false),
				esc_html($label)
			);

			for ($i = 0; $i < self::RANGES; $i++) {
				$range = $ranges[$i] ?? null;

				/* Time inputs can't show 24:00 and would empty it; 23:59 means the same here. */
				if ($range !== null && $range[1] === '24:00') {
					$range[1] = '23:59';
				}
				$html .= sprintf(
					'<span class="cwp-hours__range"%1$s><input type="time" name="%2$s[ranges][%3$d][0]" value="%4$s" aria-label="%5$s"> – <input type="time" name="%2$s[ranges][%3$d][1]" value="%6$s" aria-label="%7$s">%8$s</span>',
					$i > 0 && $range === null ? ' hidden' : '',
					esc_attr($field),
					$i,
					esc_attr($range[0] ?? ''),
					/* translators: %s: day name */
					esc_attr(sprintf(__('%s opens', 'mihdan-index-now'), $label)),
					esc_attr($range[1] ?? ''),
					/* translators: %s: day name */
					esc_attr(sprintf(__('%s closes', 'mihdan-index-now'), $label)),
					$i > 0 ? ' <button type="button" class="button-link cwp-hours__remove">' . esc_html__('Remove', 'mihdan-index-now') . '</button>' : ''
				);
			}

			$html .= sprintf(
				'<button type="button" class="button-link cwp-hours__add"%1$s>%2$s</button><span class="cwp-hours__closed">%3$s</span></div></div>',
				isset($ranges[1]) ? ' hidden' : '',
				esc_html__('+ Add hours', 'mihdan-index-now'),
				esc_html__('Closed', 'mihdan-index-now')
			);
		}

		return $html . '</div>';
	}
}
