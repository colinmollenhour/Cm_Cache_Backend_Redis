# Zend_Cache_Backend_Redis with support for tags 

! proof of concept


## FEATURES

 - Uses redis PECL extension (https://github.com/owlient/phpredis)
 - Falls-back to redisent if phpredis isn't present or `use_redisent` is enabled (https://github.com/damz/redisent)
 - The use of tags is simulated by using the “set” datatype available in redis.
 - Unit tested!

## KNOWN ISSUES

 - No proper tag cleanup is done on item expiry

## INSTALLATION (Magento)

1. Install redis (2.4 required: http://redis.io/download).
** Default configuration works fine but could probably be tuned.
2. Install phpredis: https://github.com/nicolasff/phpredis
** For 2.4 support you must use the "variadic" branch: git checkout -t -b variadic origin/variadic
** phpredis is optional, but it is much faster than Redisent (auto fall-back)
3. Install my module
** modman rediscache clone git://github.com/colinmollenhour/Zend_Cache_Backend_Redis.git
4. Edit app/etc/local.xml: https://gist.github.com/1172386
