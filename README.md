# Zend_Cache backend using Redis with full support for tags

This Zend_Cache backend allows you to use a Redis server as a central cache storage. Tags are fully supported
without the use of TwoLevels cache so this backend is great for use on a single machine or in a cluster.
Works with any Zend Framework project including all versions of Magento!

## FEATURES

 - Uses the [phpredis PECL extension](https://github.com/nicolasff/phpredis) for best performance (requires **master** branch or tagged version newer than Aug 19 2011).
 - Falls-back to standalone PHP if phpredis isn't available using the [Credis](https://github.com/colinmollenhour/credis) library.
 - Tagging is fully supported, implemented using the Redis “set” datatype for efficient tag management.
 - Key expiry is handled automatically by Redis, and the cache is safe to use with maxmemory option.
 - Automatic cleaning is optional and not really necessary, but recommended in some cases (frequently changing tags and keys, infrequent tag cleaning).
 - Uses Redis pipelining for solid performance even with very large number of tags or ids per tag.
 - Supports unix socket connection for even better performance on a single machine.
 - Unit tested!

## INSTALLATION (Magento)

1. Install [redis](http://redis.io/download) (2.4 required)
2. Install [phpredis](https://github.com/nicolasff/phpredis)
** For 2.4 support you must use the "master" branch or a tagged version newer than Aug 19.
** phpredis is optional, but it is much faster than standalone mode
3. Install this module using [modman](http://code.google.com/p/module-manager/)
** `modman rediscache clone git://github.com/colinmollenhour/Zend_Cache_Backend_Redis.git`
4. Edit app/etc/local.xml to configure:

        <!-- this is a child node of config/global -->
        <cache>
          <backend>Zend_Cache_Backend_Redis</backend>
          <backend_options>
            <server>127.0.0.1</server> <!-- or path to unix socket for better performance -->
            <port>6379</port>
            <database>2</database>
            <force_standalone>0</force_standalone>  <!-- 0 for phpredis, 1 for standalone PHP (slower) -->
            <automatic_cleaning_factor>20000</automatic_cleaning_factor> <!-- optional, 20000 is the default, 0 disables auto clean -->
          </backend_options>
        </cache>

## KNOWN ISSUES

 - Transactions are not used so in very rare circumstances it may be possible for a race-condition to cause tag data
   leaks or corruption. However, atomic operations and pipelined operations reduce these risks greatly and cached data
   is not subject to any known corruption risks. The behavior in the case of a race condition is undefined.

## RELATED

 - Monitor your redis cache statistics with my modified [munin plugin](https://gist.github.com/1177716).

## Release Notes

 - Nov 10, 2011: Changed from using phpredis and redisent to Credis (which wraps phpredis). Implemented pipelining.
