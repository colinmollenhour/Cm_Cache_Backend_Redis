# Zend_Cache backend using Redis with full support for tags

This Zend_Cache backend allows you to use a Redis server as a central cache storage. Tags are fully supported
without the use of TwoLevels cache so this backend is great for use on a single machine or in a cluster.
Works with any Zend Framework project including all versions of Magento!

## FEATURES

 - Uses the [phpredis PECL extension](https://github.com/nicolasff/phpredis) for best performance (requires **master** branch or tagged version newer than Aug 19 2011).
 - Falls-back to standalone PHP if phpredis isn't available using the [Credis](https://github.com/colinmollenhour/credis) library.
 - Tagging is fully supported, implemented using the Redis "set" and "hash" datatypes for efficient tag management.
 - Key expiry is handled automatically by Redis, and the cache is safe to use with allkeys-lru maxmemory-policy option.
 - Uses Redis pipelining for solid performance even with very large number of tags or ids per tag.
 - Supports unix socket connection for even better performance on a single machine.
 - Unit tested!

## INSTALLATION (Magento)

 1. Install [redis](http://redis.io/download) (2.4+ required)

   * The only recommended "maxmemory-policy" is "allkeys-lru". If you use a "volitile-*" policy the non-volatile keys
     could push out all of the volatile keys so that volatile keys are constantly being pushed out.

 2. Install [phpredis](https://github.com/nicolasff/phpredis)

   * For 2.4 support you must use the "master" branch or a tagged version newer than Aug 19.
   * phpredis is optional, but it is much faster than standalone mode

 3. Install this module using [modman](http://code.google.com/p/module-manager/)

   * `modman rediscache clone git://github.com/colinmollenhour/Zend_Cache_Backend_Redis.git`

 4. Edit app/etc/local.xml to configure:

        <!-- this is a child node of config/global -->
        <cache>
          <backend>Zend_Cache_Backend_Redis</backend>
          <backend_options>
            <server>127.0.0.1</server> <!-- or absolute path to unix socket for better performance -->
            <port>6379</port>
            <database>2</database>
            <force_standalone>0</force_standalone>  <!-- 0 for phpredis, 1 for standalone PHP -->
            <automatic_cleaning_factor>20000</automatic_cleaning_factor> <!-- 20000 is the default, 0 disables garbage collection -->
          </backend_options>
        </cache>

## KNOWN ISSUES

 - Standalone mode on some environments appears to lose connection to Redis. Please provide details if you can reproduce this error!
 - In very rare circumstances it may be possible for a race-condition to cause tag data leaks. However, operations are
   pipelined to reduce these risks greatly and cached data is not subject to corruption. As far as I know the risk is no
   greater than with other backends.

## RELATED / TUNING

 - Automatic cleaning is optional and not necessary, but recommended in cases with frequently changing tags and keys or
   infrequent tag cleaning.
 - Monitor your redis cache statistics with my modified [munin plugin](https://gist.github.com/1177716).

## Release Notes

 - Feb 15, 2012: Changed from using separate keys for data, tags and mtime to a single hash per key.
 - Nov 10, 2011: Changed from using phpredis and redisent to Credis (which wraps phpredis). Implemented pipelining.
