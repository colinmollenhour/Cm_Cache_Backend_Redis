<?php

require_once 'vendor/autoload.php';
require_once 'RedisBackendTest.php';

/**
 * Runs the whole RedisBackendTest suite with use_lua disabled, so the non-Lua code paths are covered.
 */
class RedisBackendNoLuaTest extends RedisBackendTest
{
    protected $useLua = false;

    public function testScriptsCaching(): void
    {
        $this->markTestSkipped('Lua scripts are only loaded when use_lua is enabled');
    }
}
