<?php

/**
 * Zend_Cache
 */

require_once 'app/Mage.php';

require_once 'Zend/Cache.php';
require_once 'Zend/Cache/Backend/Redis.php';

/**
 * Common tests for backends
 */
require_once 'CommonExtendedBackendTest.php';

class Zend_Cache_RedisBackendTest extends Zend_Cache_CommonExtendedBackendTest {

    /** @var Zend_Cache_Backend_Redis */
    protected $_instance;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct('Zend_Cache_Backend_Redis', $data, $dataName);
    }

    public function setUp($notag = false)
    {
        $this->_instance = new Zend_Cache_Backend_Redis(array(
            'server' => '127.0.0.1',
            'port'   => '6379',
            'database' => '1',
            'notMatchingTags' => TRUE,
            'force_standalone' => FALSE,
        ));
        $this->_instance->clean(Zend_Cache::CLEANING_MODE_ALL);
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

    public function testExpiredCleanup()
    {
        $this->_instance->clean();
        $this->_instance->save('BLAH','foo', array('TAG1', 'TAG2'), 1);
        $this->_instance->save('BLAH','bar', array('TAG1', 'TAG3'), 1);
        $this->assertTrue($this->_instance->getIdsMatchingAnyTags(array('TAG1','TAG2','TAG3')) == array('foo','bar'));

        // sleep(2);
        $this->_instance->___expire('foo');
        $this->_instance->___expire('bar');

        $this->_instance->clean(Zend_Cache::CLEANING_MODE_OLD);
        $this->assertTrue($this->_instance->getIdsMatchingAnyTags(array('TAG1','TAG2','TAG3')) === array());
        $this->assertTrue($this->_instance->getTags() === array());
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

}
