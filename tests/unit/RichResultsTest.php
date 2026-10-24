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
}
