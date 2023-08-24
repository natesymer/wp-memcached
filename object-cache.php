<?php

/*
Plugin Name: Memcached
Description: Memcached backend for the WP Object Cache.
Version: 0.0.1
*/

// Based on https://wordpress.org/plugins/memcached/ 

/*

TODO:

- Somehow, it's just as fast as the default cache...
- Better stats
	- Stats should appear in Query Monitor

 */

global $memcached_servers;
$memcached_servers = [
	'default' => [
		"foo-bar-memcached.gpabqv.0001.use1.cache.amazonaws.com:11211",
		"foo-bar-memcached.gpabqv.0002.use1.cache.amazonaws.com:11211"
	]
];

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
	public $prefix = WP_CACHE_PREFIX;

	private $global_flush_guid = null; // TODO: replace with a structure like for groups, but by $blog_id
	private $group_flush_guids = [];

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

	private $cache       = []; // In-memory local cache
	private $mc          = []; // group => memcached client

	private $stats       = [];
	private $group_ops   = [];

	private $connection_errors = [];

	public $time_total = 0;
	public $size_total = 0;
	public $slow_op_microseconds = 0.005; // 5 ms

	function __construct() {
		$this->stats = [
			'get' => 0,
			'get_local' => 0,
			'get_multi' => 0,
			'set' => 0,
			'set_local' => 0,
			'add' => 0,
			'delete' => 0,
			'delete_local' => 0,
			'slow-ops' => 0,
		];

		global $memcached_servers;
		$buckets = $memcached_servers ?? ['127.0.0.1:11211'];

		// If we specified raw list of buckets, just
		// use them all by default
		if (is_int(key($buckets))) {
			$buckets = ['default' => $buckets];
		}

		foreach ($buckets as $bucket => $servers) {
			$this->mc[$bucket] = $this->create_memcached_client();

			foreach ($servers as $i => $server) {
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

				if (!$this->mc[$bucket]->addServer($node, $port, 1)) {
					$this->connection_errors[] = [
						'host' => $node,
						'port' => $port,
					];
				}
			}
		}

		global $blog_id;
		$this->blog_prefix = is_multisite() ? $blog_id : '';

		$this->cache_hits   =& $this->stats['get'];
		$this->cache_misses =& $this->stats['add'];
	}

	private function create_memcached_client() {
		$m = new Memcached();
		$m->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
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

	private function get_data_size($data) {
		// TODO: implement
		return 0;
	}

	private function get_global_flush_guid() {
		if ($this->global_flush_guid !== null) {
			return $this->global_flush_guid;
		}

		$mc =& $this->get_mc();

		// Load the current global flush guid
                $remote_value = $mc->get(WP_CACHE_PREFIX);
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
		$mc->set(WP_CACHE_PREFIX, $guid, 0);
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
                $remote_value = $mc->get(WP_CACHE_PREFIX . ":$group");
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

		$prefix = WP_CACHE_PREFIX;
		$k = "$prefix:$group:$global_flush_guid:$flush_id:$key";
		
		return preg_replace( '/\s+/', '', $k);
	}

	private function timer_start() {
		$this->time_start = microtime( true );
		return true;
	}

	private function timer_stop() {
		$time_total = microtime(true) - $this->time_start;
		$this->time_total += $time_total;
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

		$size = $this->get_data_size($data);
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

		$this->group_ops_stats('delete', $key, $group, null, $elapsed);

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
				return $local_value;
			}
		}

		$mc =& $this->get_mc($group);

		$this->timer_start();
		$remote_value = $mc->get($key);
		$elapsed = $this->timer_stop();
		$found_remote = $mc->getResultCode() !== Memcached::RES_NOTFOUND;

		$this->local_cache_set($key, $remote_value, $group, true);

		$found = $found_remote;
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
//		$this->local_cache_flush();
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

		$size = $this->get_data_size($data);
		$this->timer_start();
		$result = $mc->replace($key, $data, false, $expire);
		$elapsed = $this->timer_stop();
		$this->group_ops_stats('replace', $key, $group, $size, $elapsed);

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
			$this->group_ops_stats('set_local', $key, $group, null, null);
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

	// TODO: figure this one out
	function switch_to_blog($blog_id) {
		$this->blog_prefix = is_multisite() ? (int)$blog_id : '';
	}





	private function increment_stat( $field, $num = 1 ) {
		if ( ! isset( $this->stats[ $field ] ) ) {
			$this->stats[ $field ] = $num;
		} else {
			$this->stats[ $field ] += $num;
		}
	}

	private function group_ops_stats( $op, $keys, $group, $size, $time, $comment = '' ) {
		/*
		$this->increment_stat( $op );

		// we have no use of the local ops details for now
		if ( strpos( $op, '_local' ) ) {
			return;
		}

		$this->size_total += $size;

		$keys = $this->strip_memcached_keys( $keys );

		if ( $time > $this->slow_op_microseconds && 'get_multi' !== $op ) {
			$this->increment_stat( 'slow-ops' );
			$backtrace = null;
			if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
				$backtrace = wp_debug_backtrace_summary();
			}
			$this->group_ops['slow-ops'][] = array( $op, $keys, $size, $time, $comment, $group, $backtrace );
		}

		$this->group_ops[ $group ][] = array( $op, $keys, $size, $time, $comment );
		 */
	}

	/**
	 * Takes a single (or list) of keys in memcached and returns the WP cache key
	 */
/*	private function strip_memcached_keys($keys) {
		if (!is_array($keys)) {
			$keys = [$keys];
		}

		foreach ($keys as $key => $value) {
			$offset = 0;

			list($prefix, $group, $global_flush_guid, $flush_guid, $actual_key) = explode(':', $value, 5);
			$keys[$key] = $actual_key;
		}

		if (1 === count($keys)) {
			return $keys[0];
		}

		return $keys;
}*/
/*
	function colorize_debug_line( $line, $trailing_html = '' ) {
		$colors = [
			'get' => 'green',
			'get_local' => 'lightgreen',
			'get_multi' => 'fuchsia',
			'set' => 'purple',
			'set_local' => 'orchid',
			'add' => 'blue',
			'delete' => 'red',
			'delete_local' => 'tomato',
			'slow-ops' => 'crimson',
		];

		$cmd = substr( $line, 0, strpos( $line, ' ' ) );

		// Start off with a neutral default color...
		$color_for_cmd = 'brown';
		// And if the cmd has a specific color, use that instead
		if ( isset( $colors[ $cmd ] ) ) {
			$color_for_cmd = $colors[ $cmd ];
		}

		$cmd2 = "<span style='color:" . esc_attr( $color_for_cmd ) . "; font-weight: bold;'>" . esc_html( $cmd ) . "</span>";

		return $cmd2 . esc_html( substr( $line, strlen( $cmd ) ) ) . "$trailing_html\n";
	}

	function js_toggle() {
		echo "
		<script>
		function memcachedToggleVisibility( id, hidePrefix ) {
			var element = document.getElementById( id );
			if ( ! element ) {
				return;
			}

			// Hide all element with `hidePrefix` if given. Used to display only one element at a time.
			if ( hidePrefix ) {
				var groupStats = document.querySelectorAll( '[id^=\"' + hidePrefix + '\"]' );
				groupStats.forEach(
					function ( element ) {
					    element.style.display = 'none';
					}
				);
			}

			// Toggle the one we clicked.
			if ( 'none' === element.style.display ) {
				element.style.display = 'block';
			} else {
				element.style.display = 'none';
			}
		}
		</script>
		";
	}
 */
	function stats() {
		/*
		$this->js_toggle();

		echo '<h2><span>Total memcache query time:</span>' . number_format_i18n( sprintf( '%0.1f', $this->time_total * 1000 ), 1 ) . ' ms</h2>';
		echo "\n";
		echo '<h2><span>Total memcache size:</span>' . esc_html( size_format( $this->size_total, 2 ) ) . '</h2>';
		echo "\n";

		foreach ( $this->stats as $stat => $n ) {
			if ( empty( $n ) ) {
				continue;
			}

			echo '<h2>';
			echo $this->colorize_debug_line( "$stat $n" );
			echo '</h2>';
		}

		echo "<ul class='debug-menu-links' style='clear:left;font-size:14px;'>\n";
		$groups = array_keys( $this->group_ops );
		usort( $groups, 'strnatcasecmp' );

		$active_group = $groups[0];
		// Always show `slow-ops` first
		if ( in_array( 'slow-ops', $groups ) ) {
			$slow_ops_key = array_search( 'slow-ops', $groups );
			$slow_ops = $groups[ $slow_ops_key ];
			unset( $groups[ $slow_ops_key ] );
			array_unshift( $groups, $slow_ops );
			$active_group = 'slow-ops';
		}

		$total_ops = 0;
		$group_titles = array();
		foreach ( $groups as $group ) {
			$group_name = $group;
			if ( empty( $group_name ) ) {
				$group_name = 'default';
			}
			$group_ops = count( $this->group_ops[ $group ] );
			$group_size = size_format( array_sum( array_map( function ( $op ) { return $op[2]; }, $this->group_ops[ $group ] ) ), 2 );
			$group_time = number_format_i18n( sprintf( '%0.1f', array_sum( array_map( function ( $op ) { return $op[3]; }, $this->group_ops[ $group ] ) ) * 1000 ), 1 );
			$total_ops += $group_ops;
			$group_title = "{$group_name} [$group_ops][$group_size][{$group_time} ms]";
			$group_titles[ $group ] = $group_title;
			echo "\t<li><a href='#' onclick='memcachedToggleVisibility( \"object-cache-stats-menu-target-" . esc_js( $group_name ) . "\", \"object-cache-stats-menu-target-\" );'>" . esc_html( $group_title ) . "</a></li>\n";
		}
		echo "</ul>\n";

		echo "<div id='object-cache-stats-menu-targets'>\n";
		foreach ( $groups as $group ) {
			$group_name = $group;
			if ( empty( $group_name ) ) {
				$group_name = 'default';
			}
			$current = $active_group == $group ? 'style="display: block"' : 'style="display: none"';
			echo "<div id='object-cache-stats-menu-target-" . esc_attr( $group_name ) . "' class='object-cache-stats-menu-target' $current>\n";
			echo '<h3>' . esc_html( $group_titles[ $group ] ) . '</h3>' . "\n";
			echo "<pre>\n";
			foreach ( $this->group_ops[ $group ] as $index => $arr ) {
				printf( '%3d ', $index );
				echo $this->get_group_ops_line( $index, $arr );
			}
			echo "</pre>\n";
			echo "</div>";
		}

		echo "</div>";*/
	}
/*
	function get_group_ops_line( $index, $arr ) {
		// operation
		$line = "{$arr[0]} ";

		// key
		$json_encoded_key = json_encode( $arr[1] );
		$line .= $json_encoded_key . " ";

		// comment
		if ( ! empty( $arr[4] ) ) {
			$line .= "{$arr[4]} ";
		}

		// size
		if ( isset( $arr[2] ) ) {
			$line .= '(' . size_format( $arr[2], 2 ) . ') ';
		}

		// time
		if ( isset( $arr[3] ) ) {
			$line .= '(' . number_format_i18n( sprintf( '%0.1f', $arr[3] * 1000 ), 1 ) . ' ms)';
		}

		// backtrace
		$bt_link = '';
		if ( isset( $arr[6] ) ) {
			$key_hash = md5( $index . $json_encoded_key );
			$bt_link = " <small><a href='#' onclick='memcachedToggleVisibility( \"object-cache-stats-debug-$key_hash\" );'>Toggle Backtrace</a></small>";
			$bt_link .= "<pre id='object-cache-stats-debug-$key_hash' style='display:none'>" . esc_html( $arr[6] ) . "</pre>";
		}

		return $this->colorize_debug_line( $line, $bt_link );
	}*/
}
