# Zend_Cache_Backend_Redis proof of concept


## FEATURES

 - Uses redis PECL extension (https://github.com/owlient/phpredis)
 - The use of tags is simulated by using the “set” datatype available in redis.

## KNOWN ISSUES

 - Some methods are not implemented yet
 - No proper tag cleanup is done on item expiry
 - Needs to be unit tested