<?php

require_once 'vendor/autoload.php';
require_once 'RedisBackendNoLuaTest.php';

/**
 * Non-Lua code paths with the pure-PHP (standalone) Credis client, i.e. without the phpredis extension.
 */
class RedisBackendNoLuaStandaloneTest extends RedisBackendNoLuaTest
{
    protected $forceStandalone = true;
}
