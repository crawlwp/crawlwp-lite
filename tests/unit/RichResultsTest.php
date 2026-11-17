<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Schema\RichResults;
use Mihdan\IndexNow\SEOCore\Schema\RichResultTypes;
use PHPUnit\Framework\TestCase;

class RichResultsTest extends TestCase
{
	private function type(string $id): array
	{
		return RichResultTypes::all()[$id];
	}

	private function values(string $id, array $stored, array $defaults = []): array
	{
		return RichResults::values($this->type($id), $stored, $defaults + [
			'title' => 'Post title', 'description' => 'Post summary', 'image' => '', 'url' => 'https://example.com/p/',
			'date' => '2026-10-20', 'author' => 'Collins', 'organization' => 'Example Ltd', 'site' => 'https://example.com/',
		]);
	}

	public function test_sanitize_drops_unknown_fields_and_invalid_values(): void
	{
		$json = RichResults::sanitize(json_encode([
			'type'   => 'Event',
			'fields' => ['start' => '2026-11-18T14:00', 'end' => 'tomorrow', 'currency' => 'ngn', 'price' => '25abc', 'status' => 'EventCancelled', 'attendance' => 'Teleport', 'hack' => 'x'],
		]));

		$this->assertSame(
			['type' => 'Event', 'fields' => ['status' => 'EventCancelled', 'start' => '2026-11-18T14:00', 'currency' => 'NGN']],
			json_decode($json, true)
		);
	}

	public function test_sanitize_clears_unknown_type(): void
	{
		$this->assertSame('', RichResults::sanitize('{"type":"Spaceship","fields":{}}'));
		$this->assertSame('', RichResults::sanitize('not json'));
	}

	public function test_event_needs_venue_only_when_in_person(): void
	{
		$type = $this->type('Event');

		$this->assertSame(['Starts', 'Venue name', 'Venue address'], RichResults::missing($type, $this->values('Event', [])));

		$online = $this->values('Event', ['start' => '2026-11-18T14:00', 'attendance' => 'OnlineEventAttendanceMode']);
		$this->assertSame(['Online event link'], RichResults::missing($type, $online));
	}

	public function test_currency_required_only_for_a_non_zero_price(): void
	{
		$type = $this->type('Product');

		$this->assertSame([], RichResults::missing($type, $this->values('Product', ['price' => '0'])));
		$this->assertSame(['Currency'], RichResults::missing($type, $this->values('Product', ['price' => '19.99'])));
	}

	public function test_product_needs_a_price_or_a_rating(): void
	{
		$type = $this->type('Product');

		$this->assertSame(['A price or a rating'], RichResults::missing($type, $this->values('Product', [])));
		$this->assertSame([], RichResults::missing($type, $this->values('Product', ['rating_value' => '4.5', 'rating_count' => '12'])));
	}

	public function test_defaults_fill_empty_fields(): void
	{
		$values = $this->values('Product', ['name' => '']);

		$this->assertSame('Post title', $values['name']);
		$this->assertSame('Post summary', $values['description']);
	}

	public function test_product_node(): void
	{
		$node = RichResults::prune(RichResultTypes::build_product(
			$this->values('Product', ['price' => '19.99', 'currency' => 'USD', 'availability' => 'InStock', 'brand' => 'Acme']),
			['url' => 'https://example.com/p/', 'defaults' => []]
		));

		$this->assertSame('Product', $node['@type']);
		$this->assertSame(['@type' => 'Brand', 'name' => 'Acme'], $node['brand']);
		$this->assertSame(19.99, $node['offers']['price']);
		$this->assertSame('https://schema.org/InStock', $node['offers']['availability']);
		$this->assertArrayNotHasKey('aggregateRating', $node);
		$this->assertArrayNotHasKey('sku', $node);
	}

	public function test_recipe_times_and_lists(): void
	{
		$node = RichResults::prune(RichResultTypes::build_recipe(
			$this->values('Recipe', ['prep_time' => '20', 'cook_time' => '70', 'ingredients' => "2 cups rice\n\n1 onion\n", 'instructions' => "Wash the rice\nCook"]),
			['url' => '', 'defaults' => ['author' => 'Collins', 'date' => '2026-10-20']]
		));

		$this->assertSame('PT20M', $node['prepTime']);
		$this->assertSame('PT1H10M', $node['cookTime']);
		$this->assertSame('PT1H30M', $node['totalTime']);
		$this->assertSame(['2 cups rice', '1 onion'], $node['recipeIngredient']);
		$this->assertSame(['@type' => 'HowToStep', 'text' => 'Cook'], $node['recipeInstructions'][1]);
	}

	public function test_iso_helpers(): void
	{
		$this->assertSame('2026-11-18T14:00:00+01:00', RichResults::iso_datetime('2026-11-18T14:00'));
		$this->assertSame('PT12M30S', RichResults::iso_duration('12:30'));
		$this->assertSame('PT1H5M', RichResults::iso_duration('1:05:00'));
		$this->assertSame('', RichResults::iso_minutes('0'));
	}

	public function test_mixed_event_has_both_locations(): void
	{
		$node = RichResultTypes::build_event(
			$this->values('Event', ['start' => '2026-11-18T14:00', 'attendance' => 'MixedEventAttendanceMode', 'venue' => 'Hall', 'address' => 'Lagos', 'online_url' => 'https://example.com/live']),
			['url' => 'https://example.com/p/', 'defaults' => ['site' => 'https://example.com/']]
		);

		$this->assertSame(['Place', 'VirtualLocation'], array_column($node['location'], '@type'));
	}

	public function test_premium_types_are_locked_in_free(): void
	{
		$types = RichResultTypes::all();

		$this->assertNull($types['JobPosting']['build']);
		$this->assertTrue($types['JobPosting']['premium']);
		$this->assertArrayNotHasKey('FAQPage', $types);
	}

	public function test_prune_removes_empty_nested_nodes(): void
	{
		$this->assertSame(
			['@type' => 'Event', 'name' => 'A'],
			RichResults::prune(['@type' => 'Event', 'name' => 'A', 'offers' => [], 'organizer' => ['@type' => 'Organization', 'name' => ''], 'image' => ''])
		);
	}

	/**
	 * The save path: sanitize, then wp_slash() before update_post_meta(), which unslashes.
	 * Quotes and line breaks must survive.
	 */
	public function test_quotes_and_line_breaks_survive_the_save_path(): void
	{
		$posted = json_encode(['type' => 'Recipe', 'fields' => [
			'ingredients'  => "1 cup flour\n2 eggs\nA 9\" pie dish",
			'instructions' => "Mix \"well\".\nBake.",
		]]);

		$stored = wp_unslash(wp_slash(RichResults::sanitize($posted)));
		$fields = RichResults::decode($stored)['fields'];

		$this->assertSame('Recipe', RichResults::decode($stored)['type']);
		$this->assertSame("1 cup flour\n2 eggs\nA 9\" pie dish", $fields['ingredients']);
		$this->assertSame("Mix \"well\".\nBake.", $fields['instructions']);

		/* Without the slash, the stored JSON breaks: this is what used to happen. */
		$this->assertSame('', RichResults::decode(wp_unslash(RichResults::sanitize($posted)))['type']);
	}

	public function test_a_less_than_sign_does_not_cut_the_text_off(): void
	{
		$posted = json_encode(['type' => 'Recipe', 'fields' => [
			'name'         => 'I <3 Coffee Cake',
			'instructions' => "Mix the flour\nBake at <200C for 10 min\nLeave to cool",
			'ingredients'  => "1 cup <b>flour</b>\n<script>alert(1)</script>2 eggs\n<img src=x onerror=alert(1)",
		]]);

		/* The same path as MetaFields::save(): the JSON field, then the per-value clean-up. */
		$json   = \Mihdan\IndexNow\SEOCore\MetaBox\FieldProcessor::json(wp_slash($posted), false);
		$fields = RichResults::decode(RichResults::sanitize($json))['fields'];

		$this->assertSame('I <3 Coffee Cake', $fields['name']);
		$this->assertSame("Mix the flour\nBake at <200C for 10 min\nLeave to cool", $fields['instructions']);

		/* Markup is still removed, and a "<" that could begin a tag stays encoded. */
		$this->assertStringNotContainsString('<b>', $fields['ingredients']);
		$this->assertStringNotContainsString('<script', $fields['ingredients']);
		$this->assertStringNotContainsString('<img', $fields['ingredients']);
		$this->assertStringContainsString('1 cup flour', $fields['ingredients']);
	}

	public function test_quotes_and_ampersands_after_a_less_than_sign_are_kept_as_typed(): void
	{
		$text = ['type' => 'text'];

		$this->assertSame('Kids <5 don\'t pay & "eat" free', RichResults::clean_value('Kids <5 don\'t pay & "eat" free', $text));
		$this->assertSame("Step 1: heat to <90C\nDon't stir & \"rest\"", RichResults::clean_value("Step 1: heat to <90C\nDon't stir & \"rest\"", ['type' => 'textarea']));

		/* A typed entity means the value is left exactly as WordPress cleaned it. */
		$this->assertSame('5 &lt; 6 &amp; 7 &lt;8', RichResults::clean_value('5 &lt; 6 &amp; 7 <8', $text));

		/* Nothing that could begin a tag comes back, with or without a closing bracket. */
		foreach ([
			'<script>alert("x")</script> a <3 b',
			'a <3 <img src=x onerror=alert(\'1\')',
			'a <3 <svg/onload=alert(1)',
			'"><script>alert(1)</script> <5',
			'a <3 </textarea><script>x</script',
			'a <3 <!-- x',
			'a <3 <?php x',
			"a <3 <\0script>",
		] as $hostile) {
			foreach (['text', 'textarea', 'list'] as $type) {
				$this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!?]/', RichResults::clean_value($hostile, ['type' => $type]), $hostile);
			}
		}
	}

	public function test_password_protected_post_outputs_no_rich_result(): void
	{
		$post                = new \WP_Post();
		$post->ID            = 4521;
		$post->post_password = 'secret';

		update_post_meta($post->ID, '_crawlwp_schema_rich', json_encode(['type' => 'Product', 'fields' => ['name' => 'Mug', 'price' => '12', 'currency' => 'USD']]));

		$this->assertSame([], RichResults::nodes_for_post($post));
	}

	public function test_editor_gets_an_empty_field_list_as_an_object(): void
	{
		/* A type saved with nothing filled in: [] here would make the editor script lose every value typed later. */
		$stored = RichResults::sanitize(json_encode(['type' => 'Recipe', 'fields' => []]));

		$this->assertStringContainsString('"fields":{}', json_encode(RichResults::editor_value(RichResults::decode($stored))));
		$this->assertStringContainsString('"fields":{"name":"Pancakes"}', json_encode(RichResults::editor_value(['type' => 'Recipe', 'fields' => ['name' => 'Pancakes']])));
		$this->assertStringContainsString('"fields":{}', json_encode(RichResults::editor_value(RichResults::decode(''))));
	}

	public function test_numbers_must_be_finite_and_in_range(): void
	{
		$field = ['type' => 'number', 'min' => 1, 'max' => 5];

		$this->assertSame('', RichResults::clean_value('1e999', ['type' => 'number']));
		$this->assertSame('', RichResults::clean_value('-1e999', ['type' => 'number']));
		$this->assertSame('', RichResults::clean_value('7', $field));
		$this->assertSame('', RichResults::clean_value('0', $field));
		$this->assertSame('4.5', RichResults::clean_value('4.5', $field));
		$this->assertSame('25', RichResults::clean_value('25', ['type' => 'number']));

		/* Old stored values like "INF" no longer break the page. */
		$this->assertSame(0, RichResults::number('INF'));
		$this->assertSame(0, RichResults::number('abc'));
		$this->assertSame(12.5, RichResults::number('12.5'));
		$this->assertSame([], RichResults::aggregate_rating('4', '0'));

		$offer = RichResults::offer('INF', 'USD');
		$this->assertSame(0, $offer['price']);
	}

	public function test_rating_out_of_range_is_dropped_on_save(): void
	{
		$json = RichResults::sanitize(json_encode(['type' => 'SoftwareApplication', 'fields' => ['rating_value' => '7', 'rating_count' => '0', 'price' => '1e999']]));

		$this->assertSame([], json_decode($json, true)['fields']);
	}
}
