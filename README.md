# Zend_Cache backend using Redis with full support for tags

This Zend_Cache backend allows you to use a Redis server as a central cache storage. Tags are fully supported
without the use of TwoLevels cache so this backend is great for use on a single machine or in a cluster.
Works with any Zend Framework project including all versions of Magento!

## FEATURES

 - Uses the [phpredis PECL extension](https://github.com/nicolasff/phpredis) for best performance (requires **master** branch or tagged version newer than Aug 19 2011).
 - Falls-back to standalone PHP if phpredis isn't available using the [Credis](https://github.com/colinmollenhour/credis) library.
 - Tagging is fully supported, implemented using the Redis "set" and "hash" datatypes for efficient tag management.
 - Key expiry is handled automatically by Redis, and the cache is safe to use with the "allkeys-lru" maxmemory-policy config option.
 - Supports unix socket connection for even better performance on a single machine.
 - Supports configurable compression for memory savings. Can choose between gzip, lzf and snappy and can change configuration without flushing cache.
 - Uses transactions to prevent race conditions between saves, cleans or removes causing unexpected results.
 - Unit tested!

## INSTALLATION (Magento)

 1. Install [redis](http://redis.io/download) (2.4+ required)

   * The recommended "maxmemory-policy" is "volatile-lru". All data keys are volatile and tag sets are not to prevent
     tag data from being lost. Just be sure the "maxmemory" is high enough to accomodate all of the tag data with lots
     of room left for the key data.

 2. Install [phpredis](https://github.com/nicolasff/phpredis)

   * For 2.4 support you must use the "master" branch or a tagged version newer than Aug 19.
   * phpredis is optional, but it is much faster than standalone mode

 3. Install this module using [modman](http://code.google.com/p/module-manager/)

   * `modman clone git://github.com/colinmollenhour/Cm_Cache_Backend_Redis.git`

 4. Edit app/etc/local.xml to configure:

        <!-- this is a child node of config/global -->
        <cache>
          <backend>Cm_Cache_Backend_Redis</backend>
          <backend_options>
            <server>127.0.0.1</server> <!-- or absolute path to unix socket for better performance -->
            <port>6379</port>
            <database>2</database>
            <force_standalone>0</force_standalone>  <!-- 0 for phpredis, 1 for standalone PHP -->
            <automatic_cleaning_factor>20000</automatic_cleaning_factor> <!-- 20000 is the default, 0 disables garbage collection -->
            <compress_data>1</compress_data>  <!-- 0-9 for compression level, recommended: 0 or 1 -->
            <compress_tags>1</compress_tags>  <!-- 0-9 for compression level, recommended: 0 or 1 -->
            <compress_threshold>204800</compress_threshold>  <!-- Strings below this size will not be compressed -->
            <compression_lib>gzip</compression_lib> <!-- Supports gzip, lzf and snappy -->
          </backend_options>
        </cache>

## RELATED / TUNING

 - Automatic cleaning is optional and not necessary, but recommended in cases with frequently changing tags and keys or
   infrequent tag cleaning.
 - Compression will have additional CPU overhead but may be worth it for memory savings and reduced traffic.
   For high-latency networks it may even improve performance. Use the
   [Magento Cache Benchmark](https://github.com/colinmollenhour/magento-cache-benchmark) to analyze your real-world
   compression performance and test your system's performance with different compression libraries.
   - gzip - Slowest but highest compression. Most likely you will not want to use above level 1 compression.
   - lzf - Fastest compress, fast decompress. Install: `sudo pecl install lzf`
   - snappy - Fastest decompress, fast compress. Download and install: [snappy](http://code.google.com/p/snappy/) and [php-snappy](http://code.google.com/p/php-snappy/)
 - Monitor your redis cache statistics with my modified [munin plugin](https://gist.github.com/1177716).

## Release Notes

 - Mar 1, 2012: Using latest Credis_Client which adds auto-reconnect for standalone mode.
 - Feb 15, 2012: Changed from using separate keys for data, tags and mtime to a single hash per key.
 - Nov 10, 2011: Changed from using phpredis and redisent to Credis (which wraps phpredis). Implemented pipelining.
