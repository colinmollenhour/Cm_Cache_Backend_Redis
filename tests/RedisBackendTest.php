<?php
/*
==New BSD License==

Copyright (c) 2012, Colin Mollenhour
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

require_once 'app/Mage.php';
require_once 'Zend/Cache.php';
require_once 'Cm/Cache/Backend/Redis.php';
require_once 'CommonExtendedBackendTest.php';

/**
 * @copyright  Copyright (c) 2012 Colin Mollenhour (http://colin.mollenhour.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_RedisBackendTest extends Zend_Cache_CommonExtendedBackendTest {

    const LUA_MAX_C_STACK = 1000;

    protected $forceStandalone = FALSE;

    protected $autoExpireLifetime = 0;

    protected $autoExpireRefreshOnLoad = 0;

    /** @var Cm_Cache_Backend_Redis */
    protected $_instance;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct('Cm_Cache_Backend_Redis', $data, $dataName);
    }

    public function setUp($notag = false)
    {
        $this->_instance = new Cm_Cache_Backend_Redis(array(
            'server' => '127.0.0.1',
            'port'   => '6379',
            'database' => '1',
            'notMatchingTags' => TRUE,
            'force_standalone' => $this->forceStandalone,
            'compress_threshold' => 100,
            'compression_lib' => 'gzip',
            'use_lua' => TRUE,
            'lua_max_c_stack' => self::LUA_MAX_C_STACK,
            'auto_expire_lifetime' => $this->autoExpireLifetime,
            'auto_expire_refresh_on_load' => $this->autoExpireRefreshOnLoad,
        ));
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_ALL);
        $this->_instance->___scriptFlush();
        parent::setUp($notag);
    }

    public function tearDown()
    {
        parent::tearDown();
        unset($this->_instance);
    }

    public function testConstructorCorrectCall()
    {
        // nah
    }

    public function testGetWithAnExpiredCacheId()
    {
        // not supported
    }

    public function testCompression()
    {
        $longString = str_repeat(md5('asd')."\r\n", 50);
        $this->assertTrue($this->_instance->save($longString, 'long', array('long')));
        $this->assertTrue($this->_instance->load('long') == $longString);
    }

    public function testExpiredCleanup()
    {
        $this->assertTrue($this->_instance->clean());
        $this->assertTrue($this->_instance->save('BLAH','foo', array('TAG1', 'TAG2'), 1));
        $this->assertTrue($this->_instance->save('BLAH','bar', array('TAG1', 'TAG3'), 1));
        $ids = $this->_instance->getIdsMatchingAnyTags(array('TAG1','TAG2','TAG3'));
        sort($ids);
        $this->assertEquals(array('bar','foo'), $ids);

        // sleep(2);
        $this->_instance->___expire('foo');
        $this->_instance->___expire('bar');

        $this->_instance->clean(Zend_Cache::CLEANING_MODE_OLD);
        $this->assertEquals(array(), $this->_instance->getIdsMatchingAnyTags(array('TAG1','TAG2','TAG3')));
        $this->assertEquals(array(), $this->_instance->getTags());
    }

    /**
            $this->_instance->save('bar : data to cache', 'bar', array('tag3', 'tag4'));
            $this->_instance->save('bar2 : data to cache', 'bar2', array('tag3', 'tag1'));
            $this->_instance->save('bar3 : data to cache', 'bar3', array('tag2', 'tag3'));
     */
    public function testGetIdsMatchingAnyTags()
    {
        $res = $this->_instance->getIdsMatchingAnyTags(array('tag999'));
        $this->assertEquals(0, count($res));
    }

    public function testGetIdsMatchingAnyTags2()
    {
        $res = $this->_instance->getIdsMatchingAnyTags(array('tag1', 'tag999'));
        $this->assertEquals(1, count($res));
        $this->assertTrue(in_array('bar2', $res));
    }

    public function testGetIdsMatchingAnyTags3()
    {
        $res = $this->_instance->getIdsMatchingAnyTags(array('tag3', 'tag999'));
        $this->assertEquals(3, count($res));
        $this->assertTrue(in_array('bar', $res));
        $this->assertTrue(in_array('bar2', $res));
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetIdsMatchingAnyTags4()
    {
        $res = $this->_instance->getIdsMatchingAnyTags(array('tag1', 'tag4'));
        $this->assertEquals(2, count($res));
        $this->assertTrue(in_array('bar', $res));
        $this->assertTrue(in_array('bar2', $res));
    }

    public function testCleanModeMatchingAnyTags()
    {
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, array('tag999'));
        $this->assertTrue(!!$this->_instance->load('bar'));
        $this->assertTrue(!!$this->_instance->load('bar2'));
        $this->assertTrue(!!$this->_instance->load('bar3'));
    }

    public function testCleanModeMatchingAnyTags2()
    {
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, array('tag1', 'tag999'));
        $this->assertTrue(!!$this->_instance->load('bar'));
        $this->assertFalse(!!$this->_instance->load('bar2'));
        $this->assertTrue(!!$this->_instance->load('bar3'));
    }

    public function testCleanModeMatchingAnyTags3()
    {
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, array('tag3', 'tag999'));
        $this->assertFalse(!!$this->_instance->load('bar'));
        $this->assertFalse(!!$this->_instance->load('bar2'));
        $this->assertFalse(!!$this->_instance->load('bar3'));
    }

    public function testCleanModeMatchingAnyTags4()
    {
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, array('tag1', 'tag4'));
        $this->assertFalse(!!$this->_instance->load('bar'));
        $this->assertFalse(!!$this->_instance->load('bar2'));
        $this->assertTrue(!!$this->_instance->load('bar3'));
    }

    public function testCleanModeMatchingAnyTags5()
    {
        $tags = array('tag1', 'tag4');
        for ($i = 0; $i < self::LUA_MAX_C_STACK*5; $i++) {
            $this->_instance->save('foo', 'foo'.$i, $tags);
        }
        $this->assertGreaterThan(self::LUA_MAX_C_STACK, count($this->_instance->getIdsMatchingAnyTags($tags)));
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, $tags);
        $this->assertEquals(0, count($this->_instance->getIdsMatchingAnyTags($tags)));
    }

    public function testCleanModeMatchingAnyTags6()
    {
        $tags = array();
        for ($i = 0; $i < self::LUA_MAX_C_STACK*5; $i++) {
            $tags[] = 'baz'.$i;
        }
        $this->_instance->save('foo', 'foo', $tags);
        $_tags = array(end($tags));
        $this->assertEquals(1, count($this->_instance->getIdsMatchingAnyTags($_tags)));
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, $_tags);
        $this->assertEquals(0, count($this->_instance->getIdsMatchingAnyTags($_tags)));
    }

}
