<?php

namespace Mihdan\IndexNow\Tests\Unit;

use Mihdan\IndexNow\SEOCore\Schema\Graph;
use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		// Reset static $nodes via reflection for isolated unit tests
		$ref = new \ReflectionClass(Graph::class);
		$prop = $ref->getProperty('nodes');
		$prop->setAccessible(true);
		$prop->setValue(null, []);
	}

	public function test_add_node_merges_by_id(): void
	{
		Graph::add_node([
			'@context' => 'https://schema.org',
			'@id'      => 'https://example.com/#org',
			'name'     => 'My Site',
		]);

		Graph::add_node([
			'@id' => 'https://example.com/#org',
			'url' => 'https://example.com',
		]);

		$nodes = Graph::get_nodes();
		$this->assertCount(1, $nodes);
		$this->assertSame('My Site', $nodes[0]['name']);
		$this->assertSame('https://example.com', $nodes[0]['url']);
		$this->assertArrayNotHasKey('@context', $nodes[0]);
	}

	public function test_add_node_empty_or_nested_graph(): void
	{
		Graph::add_node([]);
		$this->assertSame([], Graph::get_nodes());

		Graph::add_node([
			'@graph' => [
				['@id' => 'https://example.com/#page', 'name' => 'Page'],
			],
		]);

		$this->assertCount(1, Graph::get_nodes());
	}

	public function test_nodes_for_post_with_custom_schema(): void
	{
		$post = new \WP_Post((object) ['ID' => 42]);

		$custom_json = json_encode([
			'@type' => 'Organization',
			'name'  => 'Custom Org',
		]);

		$GLOBALS['crawlwp_test_state']['post_meta'][42][MetaFields::SCHEMA_CUSTOM] = $custom_json;

		$graph = new Graph();
		$nodes = $graph->nodes_for_post($post);

		$this->assertCount(1, $nodes);
		$this->assertSame('Organization', $nodes[0]['@type']);
		$this->assertSame('Custom Org', $nodes[0]['name']);
	}

	public function test_print_graph_outputs_json_ld_script(): void
	{
		Graph::add_node([
			'@id'   => 'https://example.com/#webpage',
			'@type' => 'WebPage',
			'name'  => 'Test Page',
		]);

		ob_start();
		Graph::print_graph();
		$output = ob_get_clean();

		$this->assertStringContainsString('<script type="application/ld+json">', $output);
		$this->assertStringContainsString('https://schema.org', $output);
		$this->assertStringContainsString('Test Page', $output);
	}
}
