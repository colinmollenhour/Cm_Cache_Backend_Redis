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

require_once 'vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class RedisBackendGarbageCollectionTest extends TestCase
{
    public function garbageCollectionOptions(): array
    {
        $options = array();
        foreach (array('standalone', 'phpredis') as $clientMode) {
            foreach (array(false, true) as $notMatchingTags) {
                foreach (array(1, 100) as $cacheSize) {
                    $name = $clientMode . '-ids-' . (int) $notMatchingTags . '-cache-' . $cacheSize;
                    $options[$name] = array($clientMode, $notMatchingTags, $cacheSize);
                }
            }
        }
        return $options;
    }

    /**
     * @dataProvider garbageCollectionOptions
     */
    public function testNonLuaGarbageCollection(string $clientMode, bool $notMatchingTags, int $cacheSize): void
    {
        if ($clientMode === 'phpredis' && !extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is not installed.');
        }
        $standalone = $clientMode === 'standalone';
        $server = getenv('REDIS_SERVER') ?: 'localhost';
        $port = getenv('REDIS_PORT') ?: '6379';
        $redis = new Credis_Client($server, $port);
        if ($standalone) {
            $redis->forceStandalone();
        }
        $redis->select(1);
        $backend = new Cm_Cache_Backend_Redis(array(
            'server' => $server,
            'port' => $port,
            'database' => 1,
            'force_standalone' => $standalone,
            'use_lua' => false,
            'notMatchingTags' => $notMatchingTags,
            'gc_scan_count' => 3,
            'gc_remove_chunk_size' => 2,
            'gc_exists_cache_size' => $cacheSize,
        ));
        $backend->clean();

        try {
            $liveIds = array();
            $expiredIds = array();
            $ttlExpiry = $standalone && $notMatchingTags && $cacheSize === 1;
            for ($i = 0; $i < 96; $i++) {
                $id = str_repeat('x', 65) . $i;
                $live = $i % 2 === 1;
                $tags = array('mixed-a', 'mixed-b', $live ? 'live' : 'expired');
                $this->assertTrue($backend->save('data-' . $i, $id, $tags, $ttlExpiry && $i === 0 ? 1 : 3600));
                if ($live) {
                    $liveIds[] = $id;
                } else {
                    $expiredIds[] = $id;
                    if (!$ttlExpiry || $i !== 0) {
                        $redis->unlink(Cm_Cache_Backend_Redis::PREFIX_KEY . $id);
                    }
                }
            }
            $this->assertTrue($backend->save('zero', '0', array('0'), 3600));
            if ($ttlExpiry) {
                sleep(2);
                $this->assertEquals(0, $redis->exists(Cm_Cache_Backend_Redis::PREFIX_KEY . $expiredIds[0]));
            }
            $redis->sAdd(Cm_Cache_Backend_Redis::SET_TAGS, 'empty');
            $scriptLoaded = $redis->script('exists', Cm_Cache_Backend_Redis::LUA_SAFE_DELETE_TAG_KEY_SH1);
            // Commandstats are server-wide; these assertions assume no other client on the test Redis.
            $before = $redis->info('commandstats');

            $this->assertTrue($backend->clean(Zend_Cache::CLEANING_MODE_OLD));

            $after = $redis->info('commandstats');
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $command) {
                if (preg_match('/^cmdstat_(eval|evalsha|script)/', $command)) {
                    $this->assertSame($before[$command] ?? null, $after[$command] ?? null, $command);
                }
            }
            if (!$scriptLoaded[0]) {
                $this->assertEquals(array(0), $redis->script('exists', Cm_Cache_Backend_Redis::LUA_SAFE_DELETE_TAG_KEY_SH1));
            }
            preg_match('/calls=(\d+)/', $before['cmdstat_sscan'] ?? 'calls=0', $beforeScans);
            preg_match('/calls=(\d+)/', $after['cmdstat_sscan'], $afterScans);
            $this->assertGreaterThan(30, (int) $afterScans[1] - (int) $beforeScans[1]);

            sort($liveIds);
            foreach (array('mixed-a', 'mixed-b', 'live') as $tag) {
                $members = $redis->sMembers(Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag);
                sort($members);
                $this->assertSame($liveIds, $members, $tag);
            }
            $tags = $backend->getTags();
            sort($tags);
            $this->assertSame(array('0', 'live', 'mixed-a', 'mixed-b'), $tags);
            $this->assertSame(array('0'), $redis->sMembers(Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . '0'));
            $this->assertSame('zero', $backend->load('0'));
            foreach (array('expired', 'empty') as $tag) {
                $this->assertEquals(0, $redis->exists(Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag));
            }
            foreach ($liveIds as $id) {
                $this->assertSame('data-' . substr($id, 65), $backend->load($id));
            }
            foreach ($expiredIds as $id) {
                $this->assertFalse($backend->load($id));
            }
            $ids = $redis->sMembers(Cm_Cache_Backend_Redis::SET_IDS);
            sort($ids);
            $expectedIds = array_merge(array('0'), $liveIds);
            sort($expectedIds);
            $this->assertSame($notMatchingTags ? $expectedIds : array(), $ids);
        } finally {
            $backend->clean();
        }
    }

    public function scanOptions(): array
    {
        return array(
            'standalone-non-lua' => array(true, false),
            'standalone-lua' => array(true, true),
            'phpredis-non-lua' => array(false, false),
            'phpredis-lua' => array(false, true),
        );
    }

    /**
     * @dataProvider scanOptions
     */
    public function testSparseTagSetsSurviveEmptyScanPages(bool $standalone, bool $useLua): void
    {
        if (!$standalone && !extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is not installed.');
        }
        $server = getenv('REDIS_SERVER') ?: 'localhost';
        $port = getenv('REDIS_PORT') ?: '6379';
        $redis = new Credis_Client($server, $port);
        if ($standalone) {
            $redis->forceStandalone();
        }
        $redis->select(1);
        $backend = new Cm_Cache_Backend_Redis(array(
            'server' => $server,
            'port' => $port,
            'database' => 1,
            'force_standalone' => $standalone,
            'use_lua' => $useLua,
            'gc_scan_count' => 1,
        ));
        $backend->clean();

        try {
            $expected = array();
            for ($tag = 0; $tag < 12; $tag++) {
                $tagKey = Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag;
                $ids = array();
                for ($i = 0; $i < 5000; $i++) {
                    $ids[] = str_repeat('s', 65) . '-' . $tag . '-' . $i;
                }
                $redis->sAdd(Cm_Cache_Backend_Redis::SET_TAGS, $tag);
                $redis->pipeline();
                foreach (array_chunk($ids, 500) as $chunk) {
                    $redis->sAdd($tagKey, $chunk);
                }
                foreach (array_chunk(array_slice($ids, 1100), 500) as $chunk) {
                    $redis->sRem($tagKey, $chunk);
                }
                $expected[$tag] = array_slice($ids, 0, 1100);
                foreach ($expected[$tag] as $id) {
                    $redis->hMSet(Cm_Cache_Backend_Redis::PREFIX_KEY . $id, array('d' => 'live'));
                }
                $redis->exec();
            }

            $emptyFirstPages = 0;
            foreach ($expected as $tag => $ids) {
                $cursor = null;
                $firstPage = true;
                do {
                    $members = $redis->sscan($cursor, Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag, null, 1);
                    $this->assertNotFalse($members);
                    if ($firstPage && $members === array() && (string) $cursor !== '0' && $cursor !== null) {
                        $emptyFirstPages++;
                    }
                    $firstPage = false;
                } while ((string) $cursor !== '0' && $cursor !== null);
            }
            if ($emptyFirstPages === 0) {
                $this->markTestSkipped('Redis produced no tag whose first SSCAN page is empty with a non-zero cursor.');
            }
            $this->assertGreaterThan(0, $emptyFirstPages);
            $this->assertTrue($backend->clean(Zend_Cache::CLEANING_MODE_OLD));

            foreach ($expected as $tag => $ids) {
                $tagKey = Cm_Cache_Backend_Redis::PREFIX_TAG_IDS . $tag;
                $this->assertEquals(1, $redis->exists($tagKey));
                $this->assertTrue((bool) $redis->sIsMember(Cm_Cache_Backend_Redis::SET_TAGS, (string) $tag));
                $members = $redis->sMembers($tagKey);
                sort($members);
                sort($ids);
                $this->assertSame($ids, $members);
            }
            $this->assertCount(12, $backend->getTags());
        } finally {
            $backend->clean();
        }
    }

    public function luaOptions(): array
    {
        return array('non-lua' => array(false), 'lua' => array(true));
    }

    private function backendWithClient(Credis_Client $redis, bool $useLua): Cm_Cache_Backend_Redis
    {
        return new class ($redis, $useLua) extends Cm_Cache_Backend_Redis {
            public function __construct(Credis_Client $redis, bool $useLua)
            {
                $this->_redis = $redis;
                $this->_useLua = $useLua;
                $this->_notMatchingTags = true;
                $this->_gcExistsCacheSize = 1;
                $this->_clientOptions = (object) array('forceStandalone' => true);
            }
        };
    }

    /**
     * @dataProvider luaOptions
     */
    public function testEmptyScanPageContinues(bool $useLua): void
    {
        $redis = $this->getMockBuilder(Credis_Client::class)->disableOriginalConstructor()
            ->onlyMethods(array('__call', 'sscan'))->getMock();
        $page = 0;
        $redis->expects($this->exactly(2))->method('sscan')->willReturnCallback(
            function (&$cursor) use (&$page) {
                if ($page === 0) {
                    $this->assertNull($cursor);
                }
                $cursor = $page++ === 0 ? 42 : '0';
                return $cursor === 42 ? array() : array('live');
            }
        );
        $redis->method('__call')->willReturnCallback(function ($command) use ($redis) {
            switch (strtolower($command)) {
                case 'script':
                    return array(1);
                case 'smembers':
                    return array('tag');
                case 'pipeline':
                case 'exists':
                    return $redis;
                case 'exec':
                    return array(true);
                default:
                    $this->fail('Unexpected command: ' . $command);
            }
        });
        $this->assertTrue($this->backendWithClient($redis, $useLua)->clean(Zend_Cache::CLEANING_MODE_OLD));
    }

    /**
     * @dataProvider luaOptions
     */
    public function testFalseScanDoesNotDeleteTag(bool $useLua): void
    {
        $redis = $this->getMockBuilder(Credis_Client::class)->disableOriginalConstructor()
            ->onlyMethods(array('__call', 'sscan'))->getMock();
        $redis->method('sscan')->willReturnCallback(function (&$cursor) {
            $cursor = 0;
            return false;
        });
        $redis->method('__call')->willReturnCallback(function ($command) {
            if (strtolower($command) === 'script') {
                return array(1);
            }
            $this->assertSame('smembers', strtolower($command));
            return array('tag');
        });
        $this->assertTrue($this->backendWithClient($redis, $useLua)->clean(Zend_Cache::CLEANING_MODE_OLD));
    }

    /**
     * @dataProvider luaOptions
     */
    public function testStringZeroCursorDeletesEmptyTag(bool $useLua): void
    {
        $redis = $this->getMockBuilder(Credis_Client::class)->disableOriginalConstructor()
            ->onlyMethods(array('__call', 'sscan'))->getMock();
        $redis->expects($this->once())->method('sscan')->willReturnCallback(function (&$cursor) {
            $this->assertNull($cursor);
            $cursor = '0';
            return array();
        });
        $commands = array();
        $redis->method('__call')->willReturnCallback(function ($command) use ($redis, &$commands) {
            $command = strtolower($command);
            if ($command === 'script') {
                return array(1);
            }
            if ($command === 'smembers') {
                return array('tag');
            }
            $commands[] = $command;
            return $command === 'exec' ? array(1, 1) : $redis;
        });
        $this->assertTrue($this->backendWithClient($redis, $useLua)->clean(Zend_Cache::CLEANING_MODE_OLD));
        $this->assertSame(array('pipeline', 'multi', 'unlink', 'srem', 'exec'), $commands);
    }

    public function invalidPipelineOptions(): array
    {
        $options = array();
        foreach (array(false, true) as $useLua) {
            foreach (array(false, array(), array(1), array(0, false), array(0, null), array(0, '0'), array(0, 1.0), array(0, array())) as $results) {
                $message = is_array($results) && count($results) === 2 ? 'Invalid' : 'Incomplete';
                $options[] = array($useLua, $results, $message);
            }
        }
        return $options;
    }

    /**
     * @dataProvider invalidPipelineOptions
     */
    public function testInvalidExistsPipelineStopsGc(bool $useLua, $results, string $message): void
    {
        $redis = $this->getMockBuilder(Credis_Client::class)->disableOriginalConstructor()
            ->onlyMethods(array('__call', 'sscan'))->getMock();
        $redis->method('sscan')->willReturnCallback(function (&$cursor) {
            $cursor = 0;
            return array('live-a', 'live-b');
        });
        $redis->method('__call')->willReturnCallback(function ($command) use ($redis, $results) {
            switch (strtolower($command)) {
                case 'script':
                    return array(1);
                case 'smembers':
                    return array('tag');
                case 'pipeline':
                case 'exists':
                    return $redis;
                case 'exec':
                    return $results;
                default:
                    $this->fail('Unexpected command: ' . $command);
            }
        });
        $this->expectException(Zend_Cache_Exception::class);
        $this->expectExceptionMessage($message . ' EXISTS pipeline result');
        $this->backendWithClient($redis, $useLua)->clean(Zend_Cache::CLEANING_MODE_OLD);
    }

    public function testNonLuaMissingIdIsLookedUpAgainForNextTag(): void
    {
        $redis = $this->getMockBuilder(Credis_Client::class)->disableOriginalConstructor()
            ->onlyMethods(array('__call', 'sscan'))->getMock();
        $redis->method('sscan')->willReturnCallback(function (&$cursor) {
            $cursor = 0;
            return array('resaved');
        });
        $lookups = 0;
        $removedTags = array();
        $results = array(array(0), array(1, 1), array(1, 1), array(1));
        $redis->method('__call')->willReturnCallback(
            function ($command, $args) use ($redis, &$lookups, &$removedTags, &$results) {
                switch (strtolower($command)) {
                    case 'smembers':
                        return array('first', 'second');
                    case 'exists':
                        $lookups++;
                        return $redis;
                    case 'srem':
                        $removedTags[] = $args[0];
                        return $redis;
                    case 'pipeline':
                    case 'multi':
                    case 'unlink':
                        return $redis;
                    case 'exec':
                        return array_shift($results);
                    default:
                        $this->fail('Unexpected command: ' . $command);
                }
            }
        );
        $this->assertTrue($this->backendWithClient($redis, false)->clean(Zend_Cache::CLEANING_MODE_OLD));
        $this->assertSame(2, $lookups);
        $this->assertSame(array('zc:ti:first', 'zc:ids', 'zc:tags'), $removedTags);
        $this->assertSame(array(), $results);
    }
}
