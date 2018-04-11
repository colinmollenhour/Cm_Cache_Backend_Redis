# Zend_Cache backend using Redis with full support for tags

This Zend_Cache backend allows you to use a Redis server as a central cache storage. Tags are fully supported
without the use of TwoLevels cache so this backend is great for use on a single machine or in a cluster.
Works with any Zend Framework project including all versions of Magento!

## FEATURES

 - Uses the [phpredis PECL extension](https://github.com/nicolasff/phpredis) for best performance (requires **master** branch or tagged version newer than Aug 19 2011).
 - Falls back to standalone PHP if phpredis isn't available using the [Credis](https://github.com/colinmollenhour/credis) library.
 - Tagging is fully supported, implemented using the Redis "set" and "hash" datatypes for efficient tag management.
 - Key expiration is handled automatically by Redis.
 - Supports unix socket connection for even better performance on a single machine.
 - Supports configurable compression for memory savings. Can choose between gzip, lzf and snappy and can change configuration without flushing cache.
 - Uses transactions to prevent race conditions between saves, cleans or removes causing unexpected results.
 - Supports a configurable "auto expiry lifetime" which, if set, will be used as the TTL when the key otherwise wouldn't expire. In combination with "auto expiry refresh on load" offers a more sane cache management strategy for Magento's `Enterprise_PageCache` module.
 - __Unit tested!__

## INSTALLATION (Magento)

 1. Install [redis](http://redis.io/download) (2.4+ required)
 2. Install [phpredis](https://github.com/nicolasff/phpredis) (optional)

   * For 2.4 support you must use the "master" branch or a tagged version newer than Aug 19, 2011.
   * phpredis is optional, but it is much faster than standalone mode
   * phpredis does not support setting read timeouts at the moment (see pull request #260). If you receive read errors (“read error on connection”), this
     might be the reason.

 3. Install this module using [modman](https://github.com/colinmollenhour/modman):

    * `modman clone https://github.com/colinmollenhour/Cm_Cache_Backend_Redis`

 4. Edit app/etc/local.xml to configure:

        <!-- This is a child node of config/global -->
        <cache>
          <backend>Cm_Cache_Backend_Redis</backend>
          <backend_options>
            <server>127.0.0.1</server> <!-- or absolute path to unix socket -->
            <port>6379</port>
            <persistent></persistent> <!-- Specify unique string to enable persistent connections. E.g.: sess-db0; bugs with phpredis and php-fpm are known: https://github.com/nicolasff/phpredis/issues/70 -->
            <database>0</database> <!-- Redis database number; protection against accidental data loss is improved by not sharing databases -->
            <password></password> <!-- Specify if your Redis server requires authentication -->
            <force_standalone>0</force_standalone>  <!-- 0 for phpredis, 1 for standalone PHP -->
            <connect_retries>1</connect_retries>    <!-- Reduces errors due to random connection failures; a value of 1 will not retry after the first failure -->
            <read_timeout>10</read_timeout>         <!-- Set read timeout duration; phpredis does not currently support setting read timeouts -->
            <automatic_cleaning_factor>0</automatic_cleaning_factor> <!-- Disabled by default -->
            <compress_data>1</compress_data>  <!-- 0-9 for compression level, recommended: 0 or 1 -->
            <compress_tags>1</compress_tags>  <!-- 0-9 for compression level, recommended: 0 or 1 -->
            <compress_threshold>20480</compress_threshold>  <!-- Strings below this size will not be compressed -->
            <compression_lib>gzip</compression_lib> <!-- Supports gzip, lzf, lz4 (as l4z), snappy and zstd -->
            <use_lua>0</use_lua> <!-- Set to 1 if Lua scripts should be used for some operations (recommended) -->
            <load_from_slave>tcp://redis-slave:6379</load_from_slave> <!-- Perform reads from a different server --> 
          </backend_options>
        </cache>

        <!-- This is a child node of config/global for Magento Enterprise FPC -->
        <full_page_cache>
          <backend>Cm_Cache_Backend_Redis</backend>
          <backend_options>
            <server>127.0.0.1</server> <!-- or absolute path to unix socket -->
            <port>6379</port>
            <persistent></persistent> <!-- Specify unique string to enable persistent connections. E.g.: sess-db0; bugs with phpredis and php-fpm are known: https://github.com/nicolasff/phpredis/issues/70 -->
            <database>1</database> <!-- Redis database number; protection against accidental data loss is improved by not sharing databases -->
            <password></password> <!-- Specify if your Redis server requires authentication -->
            <force_standalone>0</force_standalone>  <!-- 0 for phpredis, 1 for standalone PHP -->
            <connect_retries>1</connect_retries>    <!-- Reduces errors due to random connection failures -->
            <lifetimelimit>57600</lifetimelimit>    <!-- 16 hours of lifetime for cache record -->
            <compress_data>0</compress_data>        <!-- DISABLE compression for EE FPC since it already uses compression -->
            <auto_expire_lifetime></auto_expire_lifetime> <!-- Force an expiry (Enterprise_PageCache will not set one) -->
            <auto_expire_refresh_on_load></auto_expire_refresh_on_load> <!-- Refresh keys when loaded (Keeps cache primed frequently requested resources) -->
          </backend_options>
        </full_page_cache>

## High Availability and Load Balancing Support

There are two supported methods of achieving High Availability and Load Balancing with Cm_Cache_Backend_Redis.

### Redis Sentinel

You may achieve high availability and load balancing using [Redis Sentinel](http://redis.io/topics/sentinel). To enable use of Redis Sentinel the `server`
specified should be a comma-separated list of Sentinel servers and the `sentinel_master` option should be specified
to indicate the name of the sentinel master set (e.g. 'mymaster'). If using `sentinel_master` you may also specify
`load_from_slaves` in which case a random slave will be chosen for performing reads in order to load balance across multiple Redis instances.
Using the value '1' indicates to only load from slaves and '2' to include the master in the random read slave selection.

Example configuration:

        <!-- This is a child node of config/global -->
        <cache>
          <backend>Cm_Cache_Backend_Redis</backend>
          <backend_options>
            <server>tcp://10.0.0.1:26379,tcp://10.0.0.2:26379,tcp://10.0.0.3:26379</server>
            <timeout>0.5</timeout>
            <sentinel_master>mymaster</sentinel_master>
            <sentinel_master_verify>1</sentinel_master_verify>
            <load_from_slaves>1</load_from_slaves>
          </backend_options>
        </cache>

### Load Balancer or Service Discovery

It is also possible to achieve high availability by using other methods where you can specify separate connection addresses for the
master and slave(s). The `load_from_slave` option has been added for this purpose and this option does *not*
connect to a Sentinel server as the example above, although you probably would benefit from still having a Sentinel setup purely for
the easier replication and failover.

Examples would be to use a TCP load balancer (e.g. HAProxy) with separate ports for master and slaves, or a DNS-based system that
uses service discovery health checks to expose master and slaves via different DNS names. 

Example configuration:

        <!-- This is a child node of config/global -->
        <cache>
          <backend>Cm_Cache_Backend_Redis</backend>
          <backend_options>
            <server>tcp://redis-master:6379</server>
            <load_from_slave>tcp://redis-slaves:6379</load_from_slave>
            <timeout>0.5</timeout>
          </backend_options>
        </cache>

## ElastiCache

The following example configuration lets you use ElastiCache Redis (cluster mode disabled) where the writes are sent to the Primary node and reads are sent to the replicas. This lets you distribute the read traffic between the different nodes.  

The instructions to find the primary and read replica endpoints are [here](http://docs.aws.amazon.com/AmazonElastiCache/latest/UserGuide/Endpoints.html#Endpoints.Find.Redis).

        <!-- This is a child node of config/global/cache -->
        <backend_options>
          <server>primary-endpoint.0001.euw1.cache.amazonaws.com</server>
          <port>6379</port>
          <database>0</database>        <!-- Make sure database is 0 -->
          .
          . <!-- Other settings -->
          .
          <cluster>
            <master>
              <node-001>
                <server>primary-endpoint.0001.euw1.cache.amazonaws.com</server>
                <port>6379</port>
              </node-001>
            </master>
            <slave>
              <node-001>
                <server>replica-endpoint-1.jwbaun.0001.euw1.cache.amazonaws.com</server>
                <port>6379</port>
              </node-001>
              <node-002>
                <server>replica-endpoint-2.jwbaun.0001.euw1.cache.amazonaws.com</server>
                <port>6379</port>
              </node-002>
            </slave>
          </cluster>
        </backend_options>

## RELATED / TUNING

 - The recommended "maxmemory-policy" is "volatile-lru". All tag metadata is non-volatile so it is
   recommended to use key expirations unless non-volatile keys are absolutely necessary so that tag
   data cannot get evicted. So, be sure that the "maxmemory" is high enough to accommodate all of
   the tag data and non-volatile data with enough room left for the volatile key data as well.
 - Automatic cleaning is optional and not recommended since it is slow and uses lots of memory.
 - Occasional (e.g. once a day) garbage collection is recommended if the entire cache is infrequently cleared and
   automatic cleaning is not enabled. The best solution is to run a cron job which does the garbage collection.
   (See "Example Garbage Collection Script" below.)
 - Compression will have additional CPU overhead but may be worth it for memory savings and reduced traffic.
   For high-latency networks it may even improve performance. Use the
   [Magento Cache Benchmark](https://github.com/colinmollenhour/magento-cache-benchmark) to analyze your real-world
   compression performance and test your system's performance with different compression libraries.
   - gzip — Slowest but highest compression. Most likely you will not want to use above level 1 compression.
   - lzf — Fastest compress, fast decompress. Install: `sudo pecl install lzf`
   - snappy — Fastest decompress, fast compress. Download and install: [snappy](http://code.google.com/p/snappy/) and [php-snappy](http://code.google.com/p/php-snappy/)
 - Monitor your redis cache statistics with my modified [munin plugin](https://gist.github.com/1177716).
 - Enable persistent connections. Make sure that if you have multiple configurations connecting the persistent
   string is unique for each configuration so that "select" commands don't cause conflicts.
 - Use the `stats.php` script to inspect your cache to find oversized or wasteful cache tags.

### Example Garbage Collection Script (Magento)

    <?php PHP_SAPI == 'cli' or die('<h1>:P</h1>');
    ini_set('memory_limit','1024M');
    set_time_limit(0);
    error_reporting(E_ALL | E_STRICT);
    require_once 'app/Mage.php';
    Mage::app()->getCache()->getBackend()->clean('old');
    // uncomment this for Magento Enterprise Edition
    // Enterprise_PageCache_Model_Cache::getCacheInstance()->getFrontend()->getBackend()->clean('old');

## Release Notes

 - March 2017: Added support for Redis Sentinel and loading from slaves. Thanks @Xon for the help!
 - Sometime in 2013: Ceased updating these release notes...
 - November 19, 2012: Added read_timeout option. (Feature only supported in standalone mode, will be supported by phpredis when pull request #260 is merged)
 - October 29, 2012: Added support for persistent connections. (Thanks samm-git!)
 - October 12, 2012: Improved memory usage and efficiency of garbage collection and updated recommendation.
 - September 17, 2012: Added connect_retries option (default: 1) to prevent errors from random connection failures.
 - July 10, 2012: Added password authentication support.
 - Mar 1, 2012: Using latest Credis_Client which adds auto-reconnect for standalone mode.
 - Feb 15, 2012: Changed from using separate keys for data, tags and mtime to a single hash per key.
 - Nov 10, 2011: Changed from using phpredis and redisent to Credis (which wraps phpredis). Implemented pipelining.

```
@copyright  Copyright (c) 2012 Colin Mollenhour (http://colin.mollenhour.com)
This project is licensed under the "New BSD" license (see source).
```
