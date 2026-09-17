<?php

namespace Mihdan\IndexNow\SEOCore;

trait GetInstanceTrait
{
	/**
	 * Shared singleton instances, keyed by the late-static-bound class name so
	 * a subclass never silently receives the parent's instance.
	 *
	 * @var array<string, static>
	 */
	private static $instances = [];

	/**
	 * @return static
	 */
	final public static function get_instance()
	{
		$class = static::class;

		if (!isset(self::$instances[$class])) {
			self::$instances[$class] = new static();
		}

		return self::$instances[$class];
	}

	/**
	 * True when get_instance() has already created the shared instance.
	 *
	 * Lets callers avoid double-registering hooks when a class is both
	 * get_instance()'d and new'ed up directly.
	 */
	final public static function has_instance(): bool
	{
		return isset(self::$instances[static::class]);
	}
}
