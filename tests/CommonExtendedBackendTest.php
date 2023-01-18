<?php

require_once 'vendor/autoload.php';
require_once 'CommonBackendTest.php';

abstract class CommonExtendedBackendTest extends CommonBackendTest
{
    private $_capabilities;

    public function setUp($noTag = false): void
    {
        parent::setUp($noTag);
        $this->_capabilities = $this->_instance->getCapabilities();
    }

    public function testGetFillingPercentage(): void
    {
        $res = $this->_instance->getFillingPercentage();
        $this->assertTrue(is_integer($res));
        $this->assertTrue($res >= 0);
        $this->assertTrue($res <= 100);
    }

    public function testGetFillingPercentageOnEmptyBackend(): void
    {
        $this->_instance->clean();
        $res = $this->_instance->getFillingPercentage();
        $this->assertTrue(is_integer($res));
        $this->assertTrue($res >= 0);
        $this->assertTrue($res <= 100);
    }

    public function testGetIds(): void
    {
        if (!($this->_capabilities['get_list'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIds();
        $this->assertTrue(count($res) == 3);
        $this->assertTrue(in_array('bar', $res));
        $this->assertTrue(in_array('bar2', $res));
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetTags(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getTags();
        $this->assertCount(4, $res);
        $this->assertTrue(in_array('tag1', $res));
        $this->assertTrue(in_array('tag2', $res));
        $this->assertTrue(in_array('tag3', $res));
        $this->assertTrue(in_array('tag4', $res));
    }

    public function testGetIdsMatchingTags(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsMatchingTags(array('tag3'));
        $this->assertTrue(count($res) == 3);
        $this->assertTrue(in_array('bar', $res));
        $this->assertTrue(in_array('bar2', $res));
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetIdsMatchingTags2(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsMatchingTags(array('tag2'));
        $this->assertTrue(count($res) == 1);
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetIdsMatchingTags3(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsMatchingTags(array('tag9999'));
        $this->assertEmpty($res);
    }


    public function testGetIdsMatchingTags4(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsMatchingTags(array('tag3', 'tag4'));
        $this->assertTrue(count($res) == 1);
        $this->assertTrue(in_array('bar', $res));
    }

    public function testGetIdsNotMatchingTags(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsNotMatchingTags(array('tag3'));
        $this->assertCount(0, $res);
    }

    public function testGetIdsNotMatchingTags2(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsNotMatchingTags(array('tag1'));
        $this->assertTrue(count($res) == 2);
        $this->assertTrue(in_array('bar', $res));
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetIdsNotMatchingTags3(): void
    {
        if (!($this->_capabilities['tags'])) {
            # unsupported by this backend
            return;
        }
        $res = $this->_instance->getIdsNotMatchingTags(array('tag1', 'tag4'));
        $this->assertTrue(count($res) == 1);
        $this->assertTrue(in_array('bar3', $res));
    }

    public function testGetMetadatas($noTag = false)
    {
        $res = $this->_instance->getMetadatas('bar');
        $this->assertTrue(isset($res['tags']));
        $this->assertTrue(isset($res['mtime']));
        $this->assertTrue(isset($res['expire']));
        if ($noTag) {
            $this->assertEmpty($res['tags']);
        } else {
            $this->assertTrue(count($res['tags']) == 2);
            $this->assertTrue(in_array('tag3', $res['tags']));
            $this->assertTrue(in_array('tag4', $res['tags']));
        }
        $this->assertTrue($res['expire'] > time());
        $this->assertTrue($res['mtime'] <= time());
    }

    public function testTouch(): void
    {
        $res = $this->_instance->getMetadatas('bar');
        $this->assertGreaterThan(time(), $res['expire']);
        $bool = $this->_instance->touch('bar', 30);
        $this->assertTrue($bool);
        $res2 = $this->_instance->getMetadatas('bar');
        $this->assertGreaterThanOrEqual(29, $res2['expire'] - $res['expire']);
        $this->assertTrue(($res2['mtime'] >= $res['mtime']));
    }

    public function testGetCapabilities(): void
    {
        $res = $this->_instance->getCapabilities();
        $this->assertTrue(isset($res['tags']));
        $this->assertTrue(isset($res['automatic_cleaning']));
        $this->assertTrue(isset($res['expired_read']));
        $this->assertTrue(isset($res['priority']));
        $this->assertTrue(isset($res['infinite_lifetime']));
        $this->assertTrue(isset($res['get_list']));
    }
}
