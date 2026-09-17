<?php

namespace Mihdan\IndexNow\SEOCore\Schema;

use Mihdan\IndexNow\SEOCore\MetaBox\MetaFields;

/**
 * The JSON-LD graph of the current request.
 *
 * Every producer (title & meta output, breadcrumbs, integrations, custom nodes
 * built from post meta) pushes its nodes in with {@see self::add_node()}. They
 * are printed once, in a single `<script type="application/ld+json">` holding
 * one `@graph`, so the nodes can reference each other by `@id`.
 */
class Graph
{
	/**
	 * Encoding flags for every JSON-LD payload the plugin prints.
	 *
	 * The `JSON_HEX_*` flags keep `<`, `&`, `'` and `"` out of the script body so
	 * no value can break out of the `<script>` element. Lives here, on the class
	 * that owns the printing, so producers cannot drift from it.
	 */
	public const JSON_LD_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * Nodes collected for the current request, keyed by `@id` when they have one.
	 *
	 * @var array<int|string, array<string,mixed>>
	 */
	private static array $nodes = [];

	public function __construct()
	{
		add_action('wp_head', [$this, 'output'], 3);
	}

	/**
	 * Add one node to the request graph.
	 *
	 * The `@context` of a standalone node is dropped — the printed graph carries
	 * a single one. Nodes sharing an `@id` are merged, so a later producer can
	 * extend a node another one already registered. Lists of nodes and payloads
	 * wrapped in their own `@graph` are unwrapped.
	 *
	 * @param array<string,mixed> $node
	 */
	public static function add_node(array $node): void
	{
		unset($node['@context']);

		if ($node === []) {
			return;
		}

		if (isset($node['@graph']) && is_array($node['@graph'])) {
			self::add_nodes($node['@graph']);

			return;
		}

		if (array_values($node) === $node) {
			/** @var array<int, mixed> $node */
			self::add_nodes($node);

			return;
		}

		$id = isset($node['@id']) ? (string) $node['@id'] : '';

		if ($id === '') {
			self::$nodes[] = $node;

			return;
		}

		self::$nodes[$id] = isset(self::$nodes[$id])
			? array_merge(self::$nodes[$id], $node)
			: $node;
	}

	/**
	 * Add several nodes to the request graph.
	 *
	 * @param array<int,mixed> $nodes
	 */
	public static function add_nodes(array $nodes): void
	{
		foreach ($nodes as $node) {
			if (is_array($node)) {
				self::add_node($node);
			}
		}
	}

	/**
	 * Every node collected so far.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_nodes(): array
	{
		return array_values(self::$nodes);
	}

	public function output(): void
	{
		if (is_admin() || is_feed() || is_trackback() || is_robots()) {
			return;
		}

		if (is_singular()) {
			$post = get_queried_object();

			if ($post instanceof \WP_Post) {
				self::add_nodes($this->nodes_for_post($post));
			}
		}

		self::print_graph();
	}

	/**
	 * Print the collected nodes as one `@graph`.
	 */
	public static function print_graph(): void
	{
		/**
		 * Filter every JSON-LD node collected for the current request.
		 *
		 * Each entry is one node of the printed `@graph`; nodes reference each
		 * other through their `@id`. Return an empty array to print nothing.
		 *
		 * @param array<int,array<string,mixed>> $nodes The collected nodes.
		 */
		$nodes = (array) apply_filters('crawlwp_schema_graph', self::get_nodes());
		$nodes = array_values(array_filter($nodes, 'is_array'));

		if ($nodes === []) {
			return;
		}

		$flags = self::JSON_LD_FLAGS;

		/* Readable markup while debugging, compact <head> in production. */
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$flags |= JSON_PRETTY_PRINT;
		}

		$json = wp_json_encode(
			[
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			],
			$flags
		);

		if (! is_string($json) || $json === '') {
			return;
		}

		echo '<script type="application/ld+json">' . "\n";
		echo $json;
		echo "\n" . '</script>' . "\n";
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function nodes_for_post(\WP_Post $post): array
	{
		$nodes = [];

		$custom = (string) MetaFields::get($post->ID, MetaFields::SCHEMA_CUSTOM, '');

		if ($custom !== '') {
			$decoded = json_decode($custom, true);
			if (is_array($decoded)) {
				$nodes[] = $decoded;
			}
		}

		return $nodes;
	}
}
