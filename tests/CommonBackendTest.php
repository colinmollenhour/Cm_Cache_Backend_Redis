<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

abstract class CommonBackendTest extends TestCase
{
    protected Cm_Cache_Backend_Redis $_instance;
    protected $_className;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        $this->_className = $name;
        date_default_timezone_set('UTC');
        parent::__construct($name, $data, $dataName);
    }

    public function setUp($noTag = false): void
    {
        $this->_instance->setDirectives(array('logging' => false));
        if ($noTag) {
            $this->_instance->save('bar : data to cache', 'bar');
            $this->_instance->save('bar2 : data to cache', 'bar2');
            $this->_instance->save('bar3 : data to cache', 'bar3');
        } else {
            $this->_instance->save('bar : data to cache', 'bar', array('tag3', 'tag4'));
            $this->_instance->save('bar2 : data to cache', 'bar2', array('tag3', 'tag1'));
            $this->_instance->save('bar3 : data to cache', 'bar3', array('tag2', 'tag3'));
        }
    }

    public function tearDown(): void
    {
        $this->_instance->clean();
    }

    public function testConstructorBadOption(): void
    {
        $this->expectException('Zend_Cache_Exception');
        new Cm_Cache_Backend_Redis(array(1 => 'bar'));
    }

    public function testSetDirectivesBadArgument(): void
    {
        $this->expectException('Zend_Cache_Exception');
        $this->_instance->setDirectives('foo');
    }

    public function testSetDirectivesBadDirective(): void
    {
        // A bad directive (not known by a specific backend) is possible
        // => so no exception here
        $this->expectNotToPerformAssertions();
        $this->_instance->setDirectives(array('foo' => true, 'lifetime' => 3600));
    }

    public function testSetDirectivesBadDirective2(): void
    {
        $this->expectException('Zend_Cache_Exception');
        $this->_instance->setDirectives(array('foo' => true, 12 => 3600));
    }

    public function testSaveCorrectCall(): void
    {
        $res = $this->_instance->save('data to cache', 'foo', array('tag1', 'tag2'));
        $this->assertTrue($res);
    }

    public function testSaveWithNullLifeTime(): void
    {
        $this->_instance->setDirectives(array('lifetime' => null));
        $res = $this->_instance->save('data to cache', 'foo', array('tag1', 'tag2'));
        $this->assertTrue($res);
    }

    public function testSaveWithSpecificLifeTime(): void
    {
        $this->_instance->setDirectives(array('lifetime' => 3600));
        $res = $this->_instance->save('data to cache', 'foo', array('tag1', 'tag2'), 10);
        $this->assertTrue($res);
    }

    public function testRemoveCorrectCall(): void
    {
        $this->assertTrue($this->_instance->remove('bar'));
        $this->assertFalse($this->_instance->test('bar'));
        $this->assertFalse($this->_instance->remove('barbar'));
        $this->assertFalse($this->_instance->test('barbar'));
    }

    public function testTestWithAnExistingCacheId(): void
    {
        $res = $this->_instance->test('bar');
        $this->assertNotEmpty($res);
        $this->assertGreaterThan(999999, $res);
    }

    public function testTestWithANonExistingCacheId(): void
    {
        $this->assertFalse($this->_instance->test('barbar'));
    }

    public function testTestWithAnExistingCacheIdAndANullLifeTime(): void
    {
        $this->_instance->setDirectives(array('lifetime' => null));
        $res = $this->_instance->test('bar');
        $this->assertNotEmpty($res);
        $this->assertGreaterThan(999999, $res);
    }

    public function testGetWithANonExistingCacheId(): void
    {
        $this->assertFalse($this->_instance->load('barbar'));
    }

    public function testGetWithAnExistingCacheId(): void
    {
        $this->assertEquals('bar : data to cache', $this->_instance->load('bar'));
    }

    public function testGetWithAnExistingCacheIdAndUTFCharacters(): void
    {
        $data = '"""""' . "'" . '\n' . 'ééééé';
        $this->_instance->save($data, 'foo');
        $this->assertEquals($data, $this->_instance->load('foo'));
    }

    public function testGetWithAnExpiredCacheId(): void
    {
        $this->_instance->___expire('bar');
        $this->_instance->setDirectives(array('lifetime' => -1));
        $this->assertFalse($this->_instance->load('bar'));
        $this->assertEquals('bar : data to cache', $this->_instance->load('bar', true));
    }

    public function testCleanModeAll(): void
    {
        $this->assertTrue($this->_instance->clean('all'));
        $this->assertFalse($this->_instance->test('bar'));
        $this->assertFalse($this->_instance->test('bar2'));
    }

    public function testCleanModeOld(): void
    {
        $this->_instance->___expire('bar2');
        $this->assertTrue($this->_instance->clean('old'));
        $this->assertTrue($this->_instance->test('bar') > 999999);
        $this->assertFalse($this->_instance->test('bar2'));
    }

    public function testCleanModeMatchingTags(): void
    {
        $this->assertTrue($this->_instance->clean('matchingTag', array('tag3')));
        $this->assertFalse($this->_instance->test('bar'));
        $this->assertFalse($this->_instance->test('bar2'));
    }

    public function testCleanModeMatchingTags2(): void
    {
        $this->assertTrue($this->_instance->clean('matchingTag', array('tag3', 'tag4')));
        $this->assertFalse($this->_instance->test('bar'));
        $this->assertTrue($this->_instance->test('bar2') > 999999);
    }

    public function testCleanModeNotMatchingTags(): void
    {
        $this->assertTrue($this->_instance->clean('notMatchingTag', array('tag3')));
        $this->assertTrue($this->_instance->test('bar') > 999999);
        $this->assertTrue($this->_instance->test('bar2') > 999999);
    }

    public function testCleanModeNotMatchingTags2(): void
    {
        $this->assertTrue($this->_instance->clean('notMatchingTag', array('tag4')));
        $this->assertTrue($this->_instance->test('bar') > 999999);
        $this->assertFalse($this->_instance->test('bar2'));
    }

    public function testCleanModeNotMatchingTags3(): void
    {
        $this->assertTrue($this->_instance->clean('notMatchingTag', array('tag4', 'tag1')));
        $this->assertTrue($this->_instance->test('bar') > 999999);
        $this->assertTrue($this->_instance->test('bar2') > 999999);
        $this->assertFalse($this->_instance->test('bar3'));
    }
}
