<?php

/*
Plugin Name: Memcached
Description: Memcached backend for the WP Object Cache.
Version: 0.0.1
*/

// Based on https://wordpress.org/plugins/memcached/ 

/*

TODO:

- Better stats
	- Stats should appear in Query Monitor

 */

if (!defined("WP_CACHE_SERVERS")) {
	define("WP_CACHE_SERVERS", []);
}

if (!defined("WP_CACHE_PREFIX")) {
	define("WP_CACHE_PREFIX", 'default');
}

function wp_cache_add($key, $data, $group = 'default', $expire = 0) {
	global $wp_object_cache;
	return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_incr($key, $offset = 1, $group = 'default') {
	global $wp_object_cache;
	return $wp_object_cache->incr($key, $offset, $group);
}

function wp_cache_decr($key, $offset = 1, $group = 'default') {
	global $wp_object_cache;
	return $wp_object_cache->decr($key, $offset, $group);
}

function wp_cache_close() {
	return true;
}

function wp_cache_delete($key, $group = 'default') {
	global $wp_object_cache;
	return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_flush_group($group) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group($group);
}

function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}

function wp_cache_get($key, $group = 'default', $force = false, &$found = null) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple($keys, $group = 'default', $force = false) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple($keys, $group, $force);
}

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace($key, $data, $group = 'default', $expire = 0) {
	global $wp_object_cache;
	return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_set($key, $data, $group = 'default', $expire = 0) {
	global $wp_object_cache;
	return $wp_object_cache->set($key, $data, $group, (int) $expire);
}

function wp_cache_switch_to_blog($blog_id) {
	global $wp_object_cache;
	return $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_add_global_groups($groups) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups($groups);
}

/**
 * Determines whether the object cache implementation supports a particular feature.
 *
 * Possible values include:
 *  - `add_multiple`, `set_multiple`, `get_multiple` and `delete_multiple`
 *  - `flush_runtime` and `flush_group`
 *
 * @param string $feature Name of the feature to check for.
 * @return bool True if the feature is supported, false otherwise.
 */
function wp_cache_supports($feature) {
	switch ($feature) {
		case 'flush_runtime':
		case 'flush_group':
			return true;
		default:
			return false;
	}
}

/*
	Design:

	To generate a memcached cache key:

	WP_CACHE_PREFIX:$cache_group:$global_flush_guid:$flush_guid:$key_name

	$flush_guid is a guid that is generated on each group flush
	$global_flush_guid is a guid that is generated on each global flush
	$key_name is the name passed to the wp_cache_* function

	The $flush_guid is stored in the following key:

	WP_CACHE_PREFIX:$cache_group

	The $global_flush_guid is stored in the following key:

	WP_CACHE_PREFIX

	Each time the db is "flushed", a new guid is stored
	in $flush_guid.

	This way, the entirety of the server memory is exhausted
	and old/flushed entries get written over. This behavior
	can help with cost savings on AWS instances:

	https://aws.amazon.com/about-aws/whats-new/2021/11/data-tiering-amazon-elasticache-redis/

	The only downside to this is that the memory size of the
	database needs to be large enough to avoid cache thrashing
*/

class WP_Object_Cache {
	public $global_groups = [
		'blog-details',
		'blog-id-cache',
		'blog-lookup',
		'global-posts',
		'networks',
		'rss',
		'sites',
		'site-details',
		'site-lookup',
		'site-options',
		'site-transient',
		'users',
		'useremail',
		'userlogins',
		'usermeta',
		'user_meta',
		'userslugs',	
	];

	public $ignored_groups = [
		'counts',
		'plugins',
		'themes'
	];

	/**
	 * The amount of times the cache data was already stored in the cache.
	 *
	 * @var int
	 */
	public $cache_hits = 0;

	/**
	 * The amount of tmies the cache data was already stored in the runtime/local cache.
	 *
	 * @var int
	 */
	public $cache_local_hits = 0;

	/**
	 * Amount of times the cache did not have the request in cache.
	 *
	 * @var int
	 */
	public $cache_misses = 0;

	/**
	 * The number of memcached calls
	 *
	 * @var int
	 */
	public $cache_calls = 0;

	/**
	 * The amount of microseconds (μs) spent doing memcached calls
	 * @var float
	 */

	public $cache_time = 0;

	/**
	 * Holds error messages.
	 *
	 * @var array
	 */
	public $errors = [];

	private $prefix = WP_CACHE_PREFIX;
	private $global_flush_guid = null; // TODO: replace with a structure like for groups, but by $blog_id
	private $group_flush_guids = [];
	private $cache = []; // In-memory local cache
	private $mc = []; // group => memcached client

	function __construct() {
		$servers = WP_CACHE_SERVERS ?? ['127.0.0.1:11211'];

		// If we specified raw list of buckets, just
		// use them all by default
		if (is_int(key($servers))) {
			$servers = ['default' => $servers];
		}

		foreach ($servers as $group => $hosts) {
			$m = new Memcached();
			$m->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);

			foreach ($hosts as $i => $server) {
				if ('unix://' == substr($server, 0, 7)) {
					$node = $server;
					$port = 0;
				} else {
					list($node, $port) = explode(':', $server);

					$port = intval($port);

					if (!$port) {
						$port = 11211;
					}
				}

				if (!$m->addServer($node, $port, 1)) {
					$this->errors[] = "Failed to connect to $node:$port";
				}
			}

			$this->mc[$group] = $m;
		}

		global $blog_id;
		$this->blog_prefix = is_multisite() ? $blog_id : '';
	}

	private function create_memcached_client() {
		return $m;
	}

	private function local_cache_flush() {
		$this->cache = [];
	}

	private function local_cache_flush_group($group) {
		if (isset($this->cache[$group])) {
			unset($this->cache[$group]);
		}
	}

	private function local_cache_clear($key, $group = 'default') {
		if (isset($this->cache[$group]) && isset($this->cache[$group][$key])) {
			unset($this->cache[$group][$key]);
		}
	}

	private function local_cache_get($key, $group = 'default', &$found = false) {
		if (!isset($this->cache[$group]) || !isset($this->cache[$group][$key])) {
			$found = false;
			return false;
		}

		$x = $this->cache[$group][$key];
		$found = $x['found'];
		$res = $x['value'];
		if (is_object($res)) {
			$res = clone $res;
		}

		return $res;
	}

	private function local_cache_set($key, $data, $group = 'default', $found = true) {
		if (!isset($this->cache[$group])) {
			$this->cache[$group] = [];
		}

		if (is_object($data)) {
			$data = clone $data;
		}

		$this->cache[$group][$key] = [
			'value' => $data,
			'found' => $found
		];
	}

	private function local_cache_is_found($key, $group) {
		return isset($this->cache[$group])
			&& isset($this->cache[$group][$key])
			&& $this->cache[$group][$key]['found'];
	}

	private function get_global_flush_guid() {
		if ($this->global_flush_guid !== null) {
			return $this->global_flush_guid;
		}

		$mc =& $this->get_mc();

		// Load the current global flush guid
                $remote_value = $mc->get($this->prefix);
                $found_remote = $mc->getResultCode() !== Memcached::RES_NOTFOUND;
		if ($found_remote && $remote_value) {
			$this->global_flush_guid = $remote_value;
			return $remote_value;
		}

		// Reset it if it doesn't exist (reset null => some value)
		return $this->reset_global_flush_guid();
	}

	private function reset_global_flush_guid() {
		$guid = $this->generate_guid();

		$mc =& $this->get_mc();
		$mc->set($this->prefix, $guid, 0);
		$this->global_flush_guid = $guid;
		return $guid;
	}

	private function get_flush_guid($group) {
		if (isset($this->group_flush_guids[$group])) {
			$cached_v = $this->group_flush_guids[$group];
			if ($cached_v) {
				return $cached_v;
			}
		}

                $mc =& $this->get_mc();

                // Load the current global flush guid
                $found_remote = false;
                $remote_value = $mc->get($this->prefix . ":$group");
                $found_remote = $mc->getResultCode() !== Memcached::RES_NOTFOUND;
		if ($found_remote && $remote_value) {
			$this->group_flush_guids[$group] = $remote_value;
                        return $remote_value;
		}

                return $this->reset_flush_guid($group);
        }

        private function reset_flush_guid($group) {
                $guid = $this->generate_guid();

                $mc =& $this->get_mc();
		$mc->set(WP_CACHE_PREFIX . ":$group", $guid, 0);
		$this->group_flush_guids[$group] = $guid;
                return $guid;
        }

	private function key($key, $group = 'default') {
		$global_flush_guid = $this->get_global_flush_guid();
		$flush_id = $this->get_flush_guid($group);

		// TODO: multisite: replace $global_flush_guid with a blog-specific guid

		$prefix = $this->prefix;
		$k = "$prefix:$group:$global_flush_guid:$flush_id:$key";
		
		return preg_replace( '/\s+/', '', $k);
	}

	private function timer_start() {
		$this->time_start = microtime(true);
		return true;
	}

	private function timer_stop() {
		$time_total = microtime(true) - $this->time_start;
		$this->cache_time += $time_total;
		$this->cache_calls += 1;
		return $time_total;
	}

	/**
	 * Generate a GUID
	 * @return string GUID in string representation mode
	 */
	private function generate_guid() {
		// attempt to use a real GUID module
		if (function_exists('com_create_guid')) { 
			return trim(com_create_guid(), '{}');
    		}

		// Otherwise fake it.
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}

	/**
	 * Retrieves Memcached client.
	 * @param string $group The group whose memcached client you want to retrieve
	 * @return Memcached The memcached client for the group $group. If no matching client is found, defaults to the default group
	 */
	private function &get_mc($group = null) {
		if (isset($this->mc[$group])) {
			return $this->mc[$group];
		}

		return $this->mc['default'];
	}

	/**
	 * Adds data to the cache if it doesn’t already exist.
	 * @param int|string $key What to call the contents in the cache.
	 * @param mixed $data The contents to store in the cache.
	 * @param string $group Optional. Where to group the cache contents. Default `"default"`
	 * @param int $expire Optional. When to expire the cache contents, in seconds. Default 0 (no expiration).
	 * @return bool True on success, false if cache key and group already exist.
	 */
	public function add($key, $data, $group = 'default', $expire = 0) {
		if (in_array($group, $this->ignored_groups)) {
			return true;
		}

		$key = $this->key($key, $group);

		if ($this->local_cache_is_found($key, $group)) {
			return false;
		}

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$result = $mc->add($key, $data, $expire);
		$elapsed = $this->timer_stop();

		$already_exists = $mc->getResultCode() === Memcached::RES_NOTSTORED;

		if ($result && !$already_exists) {
			$this->local_cache_set($key, $data, $group, true);
		} else if (!$result && !$already_exists) {
			// Ensure the next access of this key loads it from memcached
			$this->local_cache_clear($key, $group);
		}

		return $result;
	}

	/**
	 * Adds multiple values to the cache in one call.
	 * @param array<mixed> $data Array of keys and values to be added,
	 * @param string $group Optional. Where the cache contents are grouped. Default: 'default'
	 * @param int $expire Optional. When to expire the cache contents, in seconds. Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key. Each value is either true on success, or false if cache key and group already exist.
	 */
	public function add_multiple(array $data, $group = 'default', $expire = 0) {
		$values = [];

		/* TODO Implement add_multiple via Memcached. */

		foreach ($data as $key => $value) {
			$values[$key] = $this->add($key, $value, $group, $expire);
		}

		return $values;
	}

	/**
	 * Sets the list of global cache groups.
	 * @param string|string[] $groups List of groups that are global.
	 */
	public function add_global_groups($groups) {
		if (!is_array($groups)) {
			$groups = (array)$groups;
		}

		$this->global_groups = array_merge($this->global_groups, $groups);
		$this->global_groups = array_unique($this->global_groups);
	}

	public function add_non_persistent_groups($groups) {
		if (!is_array($groups)) {
			$groups = (array)$groups;
		}

		$this->ignored_groups = array_merge($this->ignored_groups, $groups);
		$this->ignored_groups = array_unique($this->ignored_groups);
	}

	/**
	 * Increments numeric cache item’s value.
	 * @param int|string $key The cache key to increment.
	 * @param int $offset Optional. The amount by which to increment the item's value. Default: 1
	 * @param string $group Optional. The group the key is in. Default: 'default'
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function incr($key, $offset = 1, $group = 'default') {
		$key = $this->key($key, $group);
		$mc =& $this->get_mc($group);

		$incremented = $mc->increment($key, $offset);

		if ($incremented === false) {
			$this->local_cache_clear($key, $group);
			return false;
		}

		$this->local_cache_set($key, $incremented, $group, true);

		return $incremented;
	}

	/**
	 * Decrements numeric cache item’s value.
	 * @param int|string $key The cache key to decrement.
	 * @param int $offset The amount by which to decrement the item's value. Default: 1
	 * @param string $group The group the key is in. Default: 'default'
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function decr($key, $offset = 1, $group = 'default') {
		$key = $this->key($key, $group);
		$mc =& $this->get_mc($group);

		$decremented = $mc->decrement($key, $offset);

		if ($decremented === false) {
			$this->local_cache_clear($key, $group);
			return false;
		}

		$this->local_cache_set($key, $decremented, $group, true);

		return $decremented;
	}

	/**
	 * Removes the contents of the cache key in the group.
	 * @param int|string $key What the contents in the cache are called.
	 * @param string $group Optional. Where the cache contents are grouped. Default: 'default'.
	 * @return bool True on success, false if the contents were not deleted.
	 */
	public function delete($key, $group = 'default', $deprecated = false) {
		if (in_array($group, $this->ignored_groups)) {
			return true;
		}

		$key = $this->key($key, $group);

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$result = $mc->delete($key);
		$elapsed = $this->timer_stop();

		$not_found = $mc->getResultCode() === Memcached::RES_NOTFOUND;

		$this->local_cache_clear($key, $group);

		return $result && !$not_found;
	}

	/**
	 * Deletes multiple values from the cache in one call.
	 * @param array Array of keys to be deleted.
	 * @param string $group Where the cache contents are grouped. Default: 'default'
	 * @return bool[] Array of return values, grouped by key. Each value is either true on success, or false if the contents were not deleted.
	 */
	public function delete_multiple(array $keys, $group = 'default') {
		$values = [];

		foreach ($keys as $key) {
			$values[$key] = $this->delete($key, $group);
		}

		return $values;
	}

	/**
	 * Retrieves the cache contents, if it exists.
	 * @param int|string $key The key under which the cache contents are stored.
	 * @param string $group Where the cache contents are grouped. Default 'default'. Default: 'default'
	 * @param bool $force Optional. Whether to force an update of the local cache from the persistent cache. Default: false
	 * @param bool $found Optional. Whether the key was found in the cache (passed by reference). Disambiguates a return of false, a storable value. Default: null
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents. See $found
	 */
	public function get($key, $group = 'default', $force = false, &$found = null) {
		if (in_array($group, $this->ignored_groups)) {
			$found = false;
			return false;
		}

		$key = $this->key($key, $group);

		if (!$force) {
			$found_local = false;
			$local_value = $this->local_cache_get($key, $group, $found_local);

			if ($found_local) {
				$found = true;
				$this->cache_local_hits += 1;
				return $local_value;
			}
		}

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$remote_value = $mc->get($key);
		$elapsed = $this->timer_stop();
		$found_remote = $mc->getResultCode() !== Memcached::RES_NOTFOUND;

		$this->local_cache_set($key, $remote_value, $group, $found_remote);

		$found = $found_remote;

		if ($found) {
			$this->cache_hits += 1;
		} else {
			$this->cache_misses += 1;
		}

		return $remote_value;
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 * @param string[] $keys Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default: 'default'
	 * @param bool $force Optional. Whether to force an update of the local cache from the persistent cache. Default: false
	 * @return array Array of return values, grouped by key. Each value is either the cache contents on success, or false on failure.
	 */
	public function get_multiple($keys, $group = 'default', $force = false) {
		$values = [];

		// TODO implement more efficiently via memcached

		foreach ($keys as $key) {
			$values[$key] = $this->get($key, $group, $force);
		}

		return $values;
	}

	/**
	 * Clears the object cache of all data.
	 * @return bool Always returns true
	 */
	public function flush() {
		$this->local_cache_flush();
		$this->reset_global_flush_guid();
		return true;
	}

	/**
	 * Removes all cache items in a group.
	 * @param string $group Name of group to remove from cache.
	 * @return bool Always returns true
	 */
	public function flush_group($group) {
		$this->local_cache_flush_group($group);
		$this->reset_flush_guid($group);
		return true;
	}


	/**
	 * Removes all cache items from the in-memory runtime cache
	 *
	 * @return bool True on success, false on failure.
	 */
	public function flush_runtime() {
		$this->local_cache_flush();
		return true;
	}

	/**
	 * Replaces the contents in the cache, if contents already exist.
	 * @param int|string $key What to call the contents in the cache.
	 * @param muxed $data The contents to store in the cache.
	 * @param string $group Optional. Where to group the cache contents. Default: 'default'
	 * @param int $expire When to expire the cache contents, in seconds. Default 0 (no expiration)
	 * @return bool True if contents were replaced, false if original value does not exist.
	 */
	public function replace($key, $data, $group = 'default', $expire = 0) {
		$key = $this->key($key, $group);

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$result = $mc->replace($key, $data, false, $expire);
		$elapsed = $this->timer_stop();

		if (false !== $result) {
			$this->local_cache_set($key, $data, $group, true);
		}

		return $result;
	}

	/**
	 * Sets the data contents into the cache.
	 * @param int|string $key What to call the contents in the cache.
	 * @param mixed $data The contents to store in the cache.
	 * @param string $group Where to group the cache contents. Default: 'default'
	 * @param int $expire When to expire the cache contents, in seconds. Default 0 (no expiration) 
	 */
	public function set($key, $data, $group = 'default', $expire = 0) {
		if (in_array($group, $this->ignored_groups)) {
			return true;
		}

		$key = $this->key($key, $group);

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$result = $mc->set($key, $data, $expire);
		$elapsed = $this->timer_stop();

		$this->local_cache_set($key, $data, $group, true);

		return $result;
	}

	/**
	 * In multisite, switch blog prefix when switching blogs
	 *
	 * @param int $_blog_id Blog ID.
	 * @return bool Whether or not the blog prefix was switched
	 */
	public function switch_to_blog($blog_id) {
		if (!function_exists('is_multisite') || !is_multisite()) {
			return false;
		}
		$this->blog_prefix = is_multisite() ? (int)$blog_id : '';
		return true;	
	}

	/**
	 * Render data about current cache requests
	 * Used by the Debug bar plugin
	 *
	 * @return void
	 */
	public function stats() {
	?>
	<p>
        	<strong>Cache Hits (memcached):</strong>
		<?= (int)($this->cache_hits); ?>
		<br />
		<strong>Cache Hits (runtime):</strong>
		<?= (int)($this->cache_local_hits); ?>
		<br />
		<strong>Cache Misses:</strong>
		<?= (int)($this->cache_misses); ?>
		<br />
		<strong>Cache Calls:</strong>
		<?= (int)($this->cache_calls); ?>
		<br />
		<strong>Time spent in Memcached:</strong>
		<?= (float)($this->cache_time); ?>
		<br />
		<strong>Cache Size:</strong>
		<?= number_format_i18n( strlen( serialize( $this->cache ) ) / 1024, 2 ); ?> KB
	</p>
        <?php
	}
}
