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
            'use_redisent' => FALSE,
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

    public function testGarbageCleanup()
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
}
