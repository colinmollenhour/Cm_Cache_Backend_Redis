<?php

/**
 * Zend_Cache
 */

require_once 'RedisBackendTest.php';

class Zend_Cache_RedisStandaloneBackendTest extends Zend_Cache_RedisBackendTest {

    protected $forceStandalone = TRUE;

}
