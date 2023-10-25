<?php
/*
==New BSD License==

Copyright (c) 2013, Colin Mollenhour
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
    * The class name must remain as Cm_Cache_Backend_Redis.

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

/**
 * Redis adapter for Zend_Cache
 *
 * @copyright  Copyright (c) 2013 Colin Mollenhour (http://colin.mollenhour.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @author     Colin Mollenhour (http://colin.mollenhour.com)
 */
class Cm_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    public const SET_IDS         = 'zc:ids';
    public const SET_TAGS        = 'zc:tags';

    public const PREFIX_KEY      = 'zc:k:';
    public const PREFIX_TAG_IDS  = 'zc:ti:';

    public const FIELD_DATA      = 'd';
    public const FIELD_MTIME     = 'm';
    public const FIELD_TAGS      = 't';
    public const FIELD_INF       = 'i';

    public const MAX_LIFETIME    = 2592000; /* Redis backend limit */
    public const COMPRESS_PREFIX = ":\x1f\x8b";
    public const DEFAULT_CONNECT_TIMEOUT = 2.5;
    public const DEFAULT_CONNECT_RETRIES = 1;

    public const LUA_SAVE_SH1 = '1617c9fb2bda7d790bb1aaa320c1099d81825e64';
    public const LUA_CLEAN_SH1 = '39383dcf36d2e71364a666b2a806bc8219cd332d';
    public const LUA_GC_SH1 = '6990147f5d1999b936dac3b6f7e5d2071908bcf3';

    /** @var Credis_Client */
    protected $_redis;

    /** @var bool */
    protected $_notMatchingTags = false;

    /** @var int */
    protected $_lifetimelimit = self::MAX_LIFETIME; /* Redis backend limit */

    /** @var int|bool */
    protected $_compressTags = 1;

    /** @var int|bool */
    protected $_compressData = 1;

    /** @var int */
    protected $_compressThreshold = 20480;

    /** @var string */
    protected $_compressionLib;

    /** @var string */
    protected $_compressPrefix;

    /**
     * On large data sets SUNION slows down considerably when used with too many arguments
     * so this is used to chunk the SUNION into a few commands where the number of set ids
     * exceeds this setting.
     *
     * @var int
     */
    protected $_sunionChunkSize = 500;

    /**
     * Maximum number of ids to be removed at a time
     *
     * @var int
     */
    protected $_removeChunkSize = 10000;

    /** @var bool */
    protected $_useLua = true;

    /** @var integer */
    protected $_autoExpireLifetime = 0;

    /** @var string */
    protected $_autoExpirePattern = '/REQEST/';

    /** @var boolean */
    protected $_autoExpireRefreshOnLoad = false;

    /**
     * Lua's unpack() has a limit on the size of the table imposed by
     * the number of Lua stack slots that a C function can use.
     * This value is defined by LUAI_MAXCSTACK in luaconf.h and for Redis it is set to 8000.
     *
     * @see https://github.com/antirez/redis/blob/b903145/deps/lua/src/luaconf.h#L439
     * @var int
     */
    protected $_luaMaxCStack = 5000;

    /**
     * If 'retry_reads_on_master' is truthy then reads will be retried against master when slave returns "(nil)" value
     *
     * @var boolean
     */
    protected $_retryReadsOnMaster = false;

    /**
     * @var stdClass
     */
    protected $_clientOptions;

    /**
     * If 'load_from_slaves' is truthy then reads are performed on a randomly selected slave server
     *
     * @var Credis_Client
     */
    protected $_slave;

    protected function getClientOptions($options = array())
    {
        $clientOptions = new stdClass();
        $clientOptions->forceStandalone = isset($options['force_standalone']) && $options['force_standalone'];
        $clientOptions->connectRetries = isset($options['connect_retries']) ? (int) $options['connect_retries'] : self::DEFAULT_CONNECT_RETRIES;
        $clientOptions->readTimeout = isset($options['read_timeout']) ? (float) $options['read_timeout'] : null;
        $clientOptions->password = $options['password'] ?? null;
        $clientOptions->username = $options['username'] ?? null;
        $clientOptions->database = isset($options['database']) ? (int) $options['database'] : 0;
        $clientOptions->persistent = $options['persistent'] ?? '';
        $clientOptions->timeout = $options['timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        return $clientOptions;
    }

    /**
     * Construct Zend_Cache Redis backend
     * @param array $options
     * @throws Zend_Cache_Exception
     * @throws CredisException
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct($options = array())
    {
        if (empty($options['server']) && empty($options['cluster'])) {
            Zend_Cache::throwException('Redis \'server\' not specified.');
        }

        $this->_clientOptions = $this->getClientOptions($options);

        // If 'sentinel_master' is specified then server is actually sentinel and master address should be fetched from server.
        $sentinelMaster =  empty($options['sentinel_master']) ? null : $options['sentinel_master'];
        if ($sentinelMaster) {
            $sentinelClientOptions = isset($options['sentinel']) && is_array($options['sentinel'])
                                     ? $this->getClientOptions($options['sentinel'] + $options)
                                     : $this->_clientOptions;
            $servers = preg_split('/\s*,\s*/', trim($options['server']), -1, PREG_SPLIT_NO_EMPTY);
            $sentinel = null;
            $exception = null;
            for ($i = 0; $i <= $sentinelClientOptions->connectRetries; $i++) { // Try each sentinel in round-robin fashion
                foreach ($servers as $server) {
                    try {
                        $sentinelClient = new Credis_Client($server, null, $sentinelClientOptions->timeout, $sentinelClientOptions->persistent);
                        $sentinelClient->forceStandalone();
                        $sentinelClient->setMaxConnectRetries(0);
                        if ($sentinelClientOptions->readTimeout) {
                            $sentinelClient->setReadTimeout($sentinelClientOptions->readTimeout);
                        }
                        if ($sentinelClientOptions->password) {
                            $sentinelClient->auth($sentinelClientOptions->password) or Zend_Cache::throwException('Unable to authenticate with the redis sentinel.');
                        }
                        $sentinel = new Credis_Sentinel($sentinelClient);
                        $sentinel
                            ->setClientTimeout($this->_clientOptions->timeout)
                            ->setClientPersistent($this->_clientOptions->persistent);
                        $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                        $this->_applyClientOptions($redisMaster);

                        // Verify connected server is actually master as per Sentinel client spec
                        if (! empty($options['sentinel_master_verify'])) {
                            $roleData = $redisMaster->role();
                            if (! $roleData || $roleData[0] != 'master') {
                                usleep(100000); // Sleep 100ms and try again
                                $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                                $this->_applyClientOptions($redisMaster);
                                $roleData = $redisMaster->role();
                                if (! $roleData || $roleData[0] != 'master') {
                                    Zend_Cache::throwException('Unable to determine master redis server.');
                                }
                            }
                        }

                        $this->_redis = $redisMaster;
                        break 2;
                    } catch (Exception $e) {
                        unset($sentinelClient);
                        $exception = $e;
                    }
                }
            }
            if (! $this->_redis) {
                Zend_Cache::throwException('Unable to connect to a redis sentinel: '.$exception->getMessage(), $exception);
            }

            // Optionally use read slaves - will only be used for 'load' operation
            if (! empty($options['load_from_slaves'])) {
                $slaves = $sentinel->getSlaveClients($sentinelMaster);
                if ($slaves) {
                    if ($options['load_from_slaves'] == 2) {
                        $slaves[] = $this->_redis; // Also send reads to the master
                    }
                    $slaveSelect = isset($options['slave_select_callable']) && is_callable($options['slave_select_callable']) ? $options['slave_select_callable'] : null;
                    if ($slaveSelect) {
                        $slave = $slaveSelect($slaves, $this->_redis);
                    } else {
                        $slaveKey = array_rand($slaves);
                        $slave = $slaves[$slaveKey]; /* @var $slave Credis_Client */
                    }
                    if ($slave instanceof Credis_Client && $slave !== $this->_redis) {
                        try {
                            $this->_applyClientOptions($slave, true);
                            $this->_slave = $slave;
                        } catch (Exception $e) {
                            // If there is a problem with first slave then skip 'load_from_slaves' option
                        }
                    }
                }
            }
            unset($sentinel);
        }

        // Instantiate Credis_Cluster
        // DEPRECATED
        elseif (! empty($options['cluster'])) {
            $this->_setupReadWriteCluster($options);
        }

        // Direct connection to single Redis server and optional slaves
        else {
            $port = $options['port'] ?? 6379;
            $this->_redis = new Credis_Client($options['server'], $port, $this->_clientOptions->timeout, $this->_clientOptions->persistent);
            $this->_applyClientOptions($this->_redis);

            // Support loading from a replication slave
            if (isset($options['load_from_slave'])) {
                if (is_array($options['load_from_slave'])) {
                    if (isset($options['load_from_slave']['server'])) {  // Single slave
                        $server = $options['load_from_slave']['server'];
                        $port = $options['load_from_slave']['port'];
                        $clientOptions = $this->getClientOptions($options['load_from_slave'] + $options);
                        $totalServers = 2;
                    } else {  // Multiple slaves
                        $slaveKey = array_rand($options['load_from_slave']);
                        $slave = $options['load_from_slave'][$slaveKey];
                        $server = $slave['server'];
                        $port = $slave['port'];
                        $clientOptions = $this->getClientOptions($slave + $options);
                        $totalServers = count($options['load_from_slave']) + 1;
                    }
                } else {  // String
                    $server = $options['load_from_slave'];
                    $port = 6379;
                    $clientOptions = $this->_clientOptions;

                    // If multiple addresses are given, split and choose a random one
                    if (strpos($server, ',') !== false) {
                        $slaves = preg_split('/\s*,\s*/', $server, -1, PREG_SPLIT_NO_EMPTY);
                        $slaveKey = array_rand($slaves);
                        $server = $slaves[$slaveKey];
                        $port = null;
                        $totalServers = count($slaves) + 1;
                    } else {
                        $totalServers = 2;
                    }
                }
                // Skip setting up slave if master is not write only, and it is randomly chosen to be the read server
                $masterWriteOnly = isset($options['master_write_only']) ? (int) $options['master_write_only'] : false;
                if (is_string($server) && $server && ! (!$masterWriteOnly && rand(1, $totalServers) === 1)) {
                    try {
                        $slave = new Credis_Client($server, $port, $clientOptions->timeout, $clientOptions->persistent);
                        $this->_applyClientOptions($slave, true, $clientOptions);
                        $this->_slave = $slave;
                    } catch (Exception $e) {
                        // Slave will not be used
                    }
                }
            }
        }

        if (isset($options['notMatchingTags'])) {
            $this->_notMatchingTags = (bool) $options['notMatchingTags'];
        }

        if (isset($options['compress_tags'])) {
            $this->_compressTags = (int) $options['compress_tags'];
        }

        if (isset($options['compress_data'])) {
            $this->_compressData = (int) $options['compress_data'];
        }

        if (isset($options['lifetimelimit'])) {
            $this->_lifetimelimit = (int) min($options['lifetimelimit'], self::MAX_LIFETIME);
        }

        if (isset($options['compress_threshold'])) {
            $this->_compressThreshold = (int) $options['compress_threshold'];
            if ($this->_compressThreshold < 1) {
                $this->_compressThreshold = 1;
            }
        }

        if (isset($options['automatic_cleaning_factor'])) {
            $this->_options['automatic_cleaning_factor'] = (int) $options['automatic_cleaning_factor'];
        } else {
            $this->_options['automatic_cleaning_factor'] = 0;
        }

        if (isset($options['compression_lib'])) {
            $this->_compressionLib = (string) $options['compression_lib'];
        } elseif (function_exists('snappy_compress')) {
            $this->_compressionLib = 'snappy';
        } elseif (function_exists('lz4_compress')) {
            $version = phpversion("lz4");
            if (version_compare($version, "0.3.0") < 0) {
                $this->_compressTags = $this->_compressTags > 1;
                $this->_compressData = $this->_compressData > 1;
            }
            $this->_compressionLib = 'l4z';
        } elseif (function_exists('zstd_compress')) {
            $version = phpversion("zstd");
            if (version_compare($version, "0.4.13") < 0) {
                $this->_compressTags = $this->_compressTags > 1;
                $this->_compressData = $this->_compressData > 1;
            }
            $this->_compressionLib = 'zstd';
        } elseif (function_exists('lzf_compress')) {
            $this->_compressionLib = 'lzf';
        } else {
            $this->_compressionLib = 'gzip';
        }
        $this->_compressPrefix = substr($this->_compressionLib, 0, 2).self::COMPRESS_PREFIX;

        if (isset($options['sunion_chunk_size']) && $options['sunion_chunk_size'] > 0) {
            $this->_sunionChunkSize = (int) $options['sunion_chunk_size'];
        }

        if (isset($options['remove_chunk_size']) && $options['remove_chunk_size'] > 0) {
            $this->_removeChunkSize = (int) $options['remove_chunk_size'];
        }

        if (isset($options['use_lua'])) {
            $this->_useLua = (bool) $options['use_lua'];
        }

        if (isset($options['lua_max_c_stack'])) {
            $this->_luaMaxCStack = (int) $options['lua_max_c_stack'];
        }

        if (isset($options['retry_reads_on_master'])) {
            $this->_retryReadsOnMaster = (bool) $options['retry_reads_on_master'];
        }

        if (isset($options['auto_expire_lifetime'])) {
            $this->_autoExpireLifetime = (int) $options['auto_expire_lifetime'];
        }

        if (isset($options['auto_expire_pattern'])) {
            $this->_autoExpirePattern = (string) $options['auto_expire_pattern'];
        }

        if (isset($options['auto_expire_refresh_on_load'])) {
            $this->_autoExpireRefreshOnLoad = (bool) $options['auto_expire_refresh_on_load'];
        }
    }

    /**
     * Apply common configuration to client instances.
     *
     * @param Credis_Client $client
     * @param bool $forceSelect
     * @param null|stdClass $clientOptions
     * @throws CredisException
     * @throws Zend_Cache_Exception
     */
    protected function _applyClientOptions(Credis_Client $client, $forceSelect = false, $clientOptions = null)
    {
        if ($clientOptions === null) {
            $clientOptions = $this->_clientOptions;
        }

        if ($clientOptions->forceStandalone) {
            $client->forceStandalone();
        }

        $client->setMaxConnectRetries($clientOptions->connectRetries);

        if ($clientOptions->readTimeout) {
            $client->setReadTimeout($clientOptions->readTimeout);
        }

        if ($clientOptions->password) {
            if ($clientOptions->username) {
                $client->auth($clientOptions->password, $clientOptions->username) or Zend_Cache::throwException('Unable to authenticate with the redis server.');
            } else {
                $client->auth($clientOptions->password) or Zend_Cache::throwException('Unable to authenticate with the redis server.');
            }
        }

        // Always select database when persistent is used in case connection is re-used by other clients
        if ($forceSelect || $clientOptions->database || $client->getPersistence()) {
            $client->select($clientOptions->database) or Zend_Cache::throwException('The redis database could not be selected.');
        }
    }

    /**
     * @param $options
     * @throws CredisException
     * @throws Zend_Cache_Exception
     * @deprecated - Previously this setup an instance of Credis_Cluster but this class was not complete or flawed
     */
    protected function _setupReadWriteCluster($options)
    {
        if (!empty($options['cluster']['master'])) {
            foreach ($options['cluster']['master'] as $masterNode) {
                if (empty($masterNode['server']) || empty($masterNode['port'])) {
                    continue;
                }

                $this->_redis = new Credis_Client(
                    $masterNode['host'],
                    $masterNode['port'],
                    $masterNode['timeout'] ?? 2.5,
                    $masterNode['persistent'] ?? ''
                );
                $this->_applyClientOptions($this->_redis);
                break;
            }
        }

        if (!empty($options['cluster']['slave'])) {
            $slaveKey = array_rand($options['cluster']['slave']);
            $slave = $options['cluster']['slave'][$slaveKey];
            $this->_slave = new Credis_Client(
                $slave['host'],
                $slave['port'],
                $slave['timeout'] ?? 2.5,
                $slave['persistent'] ?? ''
            );
            $this->_applyClientOptions($this->_redis, true);
        }
    }

    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return bool|string
     * @throws CredisException
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        if ($this->_slave) {
            try {
                $data = $this->_slave->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);

                // Prevent compounded effect of cache flood on asynchronously replicating master/slave setup
                if ($this->_retryReadsOnMaster && $data === false) {
                    $data = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);
                }
            } catch (CredisException $e) {
                // Always retry reads on master when dataset is loading on slave
                if ($e->getMessage() === 'LOADING Redis is loading the dataset in memory') {
                    $data = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);
                } else {
                    throw $e;
                }
            }
        } else {
            try {
                $data = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);
            } catch (CredisException $e) {
                // Retry once after 1 second when dataset is loading
                if ($e->getMessage() === 'LOADING Redis is loading the dataset in memory') {
                    sleep(1);
                    $data = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);
                } else {
                    throw $e;
                }
            }
        }
        if ($data === null || $data === false || is_object($data)) {
            return false;
        }

        $decoded = $this->_decodeData($data);

        if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad) {
            return $decoded;
        }

        $matches = $this->_matchesAutoExpiringPattern($id);
        if (!$matches) {
            return $decoded;
        }

        $this->_redis->expire(self::PREFIX_KEY.$id, min($this->_autoExpireLifetime, self::MAX_LIFETIME));

        return $decoded;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return bool|int False if record is not available or "last modified" timestamp of the available cache record
     */
    public function test($id)
    {
        // Don't use slave for this since `test` is usually used for locking
        $mtime = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_MTIME);
        return ($mtime ? (int)$mtime : false);
    }

    /**
     * Get the lifetime
     *
     * if $specificLifetime is not false, the given specific lifetime is used
     * else, the global lifetime is used
     *
     * @param  int $specificLifetime
     * @return int Cache lifetime
     */
    public function getLifetime($specificLifetime)
    {
        // Lifetimes set via Layout XMLs get parsed as string so bool(false) becomes string("false")
        if ($specificLifetime === 'false') {
            $specificLifetime = false;
        }

        return parent::getLifetime($specificLifetime);
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @throws CredisException
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if (!is_array($tags)) {
            $tags = $tags ? array($tags) : array();
        } else {
            $tags = array_flip(array_flip($tags));
        }

        $lifetime = $this->_getAutoExpiringLifetime($this->getLifetime($specificLifetime), $id);
        $lifetime = $lifetime === null ? $lifetime : (int) $lifetime;

        if ($this->_useLua) {
            $sArgs = array(
                self::PREFIX_KEY,
                self::FIELD_DATA,
                self::FIELD_TAGS,
                self::FIELD_MTIME,
                self::FIELD_INF,
                self::SET_TAGS,
                self::PREFIX_TAG_IDS,
                self::SET_IDS,
                $id,
                $this->_encodeData($data, $this->_compressData),
                $this->_encodeData(implode(',', $tags), $this->_compressTags),
                time(),
                $lifetime ? 0 : 1,
                min($lifetime, self::MAX_LIFETIME),
                $this->_notMatchingTags ? 1 : 0
            );

            $res = $this->_redis->evalSha(self::LUA_SAVE_SH1, $tags, $sArgs);
            if (is_null($res)) {
                $script =
                    "local oldTags = redis.call('HGET', ARGV[1]..ARGV[9], ARGV[3]) ".
                    "redis.call('HMSET', ARGV[1]..ARGV[9], ARGV[2], ARGV[10], ARGV[3], ARGV[11], ARGV[4], ARGV[12], ARGV[5], ARGV[13]) ".
                    "if (ARGV[13] == '0') then ".
                        "redis.call('EXPIRE', ARGV[1]..ARGV[9], ARGV[14]) ".
                    "end ".
                    "if next(KEYS) ~= nil then ".
                        "redis.call('SADD', ARGV[6], unpack(KEYS)) ".
                        "for _, tagname in ipairs(KEYS) do ".
                            "redis.call('SADD', ARGV[7]..tagname, ARGV[9]) ".
                        "end ".
                    "end ".
                    "if (ARGV[15] == '1') then ".
                        "redis.call('SADD', ARGV[8], ARGV[9]) ".
                    "end ".
                    "if (oldTags ~= false) then ".
                        "return oldTags ".
                    "else ".
                        "return '' ".
                    "end";
                $res = $this->_redis->eval($script, $tags, $sArgs);
            }

            // Process removed tags if cache entry already existed
            if ($res) {
                $oldTags = explode(',', $this->_decodeData($res));
                if ($remTags = ($oldTags ? array_diff($oldTags, $tags) : false)) {
                    // Update the id list for each tag
                    foreach ($remTags as $tag) {
                        $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
                    }
                }
            }

            return true;
        }

        // Get list of tags previously assigned
        $oldTags = $this->_decodeData($this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_TAGS));
        $oldTags = $oldTags ? explode(',', $oldTags) : array();

        $this->_redis->pipeline()->multi();

        // Set the data
        $result = $this->_redis->hMSet(self::PREFIX_KEY.$id, array(
          self::FIELD_DATA => $this->_encodeData($data, $this->_compressData),
          self::FIELD_TAGS => $this->_encodeData(implode(',', $tags), $this->_compressTags),
          self::FIELD_MTIME => time(),
          self::FIELD_INF => is_null($lifetime) ? 1 : 0,
        ));
        if (! $result) {
            throw new CredisException("Could not set cache key $id");
        }

        // Set expiration if specified
        if ($lifetime !== false && !is_null($lifetime)) {
            $this->_redis->expire(self::PREFIX_KEY.$id, min($lifetime, self::MAX_LIFETIME));
        }

        // Process added tags
        if ($tags) {
            // Update the list with all the tags
            $this->_redis->sAdd(self::SET_TAGS, $tags);

            // Update the id list for each tag
            foreach ($tags as $tag) {
                $this->_redis->sAdd(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Process removed tags
        if ($remTags = ($oldTags ? array_diff($oldTags, $tags) : false)) {
            // Update the id list for each tag
            foreach ($remTags as $tag) {
                $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Update the list with all the ids
        if ($this->_notMatchingTags) {
            $this->_redis->sAdd(self::SET_IDS, $id);
        }

        $this->_redis->exec();

        return true;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        // Get list of tags for this id
        $tags = explode(',', $this->_decodeData($this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_TAGS)));

        $this->_redis->pipeline()->multi();

        // Remove data
        $this->_redis->unlink(self::PREFIX_KEY.$id);

        // Remove id from list of all ids
        if ($this->_notMatchingTags) {
            $this->_redis->sRem(self::SET_IDS, $id);
        }

        // Update the id list for each tag
        foreach ($tags as $tag) {
            $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
        }

        $result = $this->_redis->exec();

        return isset($result[0]) && (bool)$result[0];
    }

    /**
     * @param array $tags
     * @throws Zend_Cache_Exception
     */
    protected function _removeByNotMatchingTags($tags)
    {
        $ids = $this->getIdsNotMatchingTags($tags);
        $this->_removeByIds($ids);
    }

    /**
     * @param array $tags
     */
    protected function _removeByMatchingTags($tags)
    {
        $ids = $this->getIdsMatchingTags($tags);
        $this->_removeByIds($ids);
    }

    /**
     * @param array $ids
     */
    protected function _removeByIds($ids)
    {
        if ($ids) {
            $ids = array_chunk($ids, $this->_removeChunkSize);
            foreach ($ids as $idsChunk) {
                $this->_redis->pipeline()->multi();

                // Remove data
                $this->_redis->unlink($this->_preprocessIds($idsChunk));

                // Remove ids from list of all ids
                if ($this->_notMatchingTags) {
                    $this->_redis->sRem(self::SET_IDS, $idsChunk);
                }

                $this->_redis->exec();
            }
        }
    }

    /**
     * @param array $tags
     */
    protected function _removeByMatchingAnyTags($tags)
    {
        if ($this->_useLua) {
            $tags = array_chunk($tags, $this->_sunionChunkSize);
            foreach ($tags as $chunk) {
                $args = array(self::PREFIX_TAG_IDS, self::PREFIX_KEY, self::SET_TAGS, self::SET_IDS, ($this->_notMatchingTags ? 1 : 0), (int) $this->_luaMaxCStack);
                if (! $this->_redis->evalSha(self::LUA_CLEAN_SH1, $chunk, $args)) {
                    $script =
                        "for i = 1, #KEYS, ARGV[6] do " .
                            "local prefixedTags = {} " .
                            "for x, tag in ipairs(KEYS) do " .
                                "prefixedTags[x] = ARGV[1]..tag " .
                            "end " .
                            "local keysToDel = redis.call('SUNION', unpack(prefixedTags, i, math.min(#prefixedTags, i + ARGV[6] - 1))) " .
                            "for _, keyname in ipairs(keysToDel) do " .
                                "redis.call('UNLINK', ARGV[2]..keyname) " .
                                "if (ARGV[5] == '1') then " .
                                    "redis.call('SREM', ARGV[4], keyname) " .
                                "end " .
                            "end " .
                            "redis.call('UNLINK', unpack(prefixedTags, i, math.min(#prefixedTags, i + ARGV[6] - 1))) " .
                            "redis.call('SREM', ARGV[3], unpack(KEYS, i, math.min(#KEYS, i + ARGV[6] - 1))) " .
                        "end " .
                        "return true";
                    $this->_redis->eval($script, $chunk, $args);
                }
            }
            return;
        }

        $ids = $this->getIdsMatchingAnyTags($tags);

        $this->_redis->pipeline()->multi();

        if ($ids) {
            $ids = array_chunk($ids, $this->_removeChunkSize);
            foreach ($ids as $idsChunk) {
                // Remove data
                $this->_redis->unlink($this->_preprocessIds($idsChunk));

                // Remove ids from list of all ids
                if ($this->_notMatchingTags) {
                    $this->_redis->sRem(self::SET_IDS, $idsChunk);
                }

                // Commit each chunk in a separate transaction
                if (count($ids) > 1) {
                    $this->_redis->pipeline()->exec();
                    $this->_redis->pipeline()->multi();
                }
            }
        }

        // Remove tag id lists
        $this->_redis->unlink($this->_preprocessTagIds($tags));

        // Remove tags from list of tags
        $this->_redis->sRem(self::SET_TAGS, $tags);

        $this->_redis->exec();
    }

    /**
     * Clean up tag id lists since as keys expire the ids remain in the tag id lists
     */
    protected function _collectGarbage()
    {
        // Clean up expired keys from tag id set and global id set

        if ($this->_useLua) {
            $sArgs = array(self::PREFIX_KEY, self::SET_TAGS, self::SET_IDS, self::PREFIX_TAG_IDS, ($this->_notMatchingTags ? 1 : 0));
            $allTags = (array) $this->_redis->sMembers(self::SET_TAGS);
            $tagsCount = count($allTags);
            $counter = 0;
            $tagsBatch = array();
            foreach ($allTags as $tag) {
                $tagsBatch[] = $tag;
                $counter++;
                if (count($tagsBatch) == 10 || $counter == $tagsCount) {
                    if (! $this->_redis->evalSha(self::LUA_GC_SH1, $tagsBatch, $sArgs)) {
                        $script =
                            "local tagKeys = {} ".
                            "local expired = {} ".
                            "local expiredCount = 0 ".
                            "local notExpiredCount = 0 ".
                            "for _, tagName in ipairs(KEYS) do ".
                                "tagKeys = redis.call('SMEMBERS', ARGV[4]..tagName) ".
                                "for __, keyName in ipairs(tagKeys) do ".
                                    "if (redis.call('EXISTS', ARGV[1]..keyName) == 0) then ".
                                        "expiredCount = expiredCount + 1 ".
                                        "expired[expiredCount] = keyName ".
                                        /* Redis Lua scripts have a hard limit of 8000 parameters per command */
                                        "if (expiredCount == 7990) then ".
                                            "redis.call('SREM', ARGV[4]..tagName, unpack(expired)) ".
                                            "if (ARGV[5] == '1') then ".
                                                "redis.call('SREM', ARGV[3], unpack(expired)) ".
                                            "end ".
                                            "expiredCount = 0 ".
                                            "expired = {} ".
                                        "end ".
                                    "else ".
                                        "notExpiredCount = notExpiredCount + 1 ".
                                    "end ".
                                "end ".
                                "if (expiredCount > 0) then ".
                                    "redis.call('SREM', ARGV[4]..tagName, unpack(expired)) ".
                                    "if (ARGV[5] == '1') then ".
                                        "redis.call('SREM', ARGV[3], unpack(expired)) ".
                                    "end ".
                                "end ".
                                "if (notExpiredCount == 0) then ".
                                    "redis.call ('UNLINK', ARGV[4]..tagName) ".
                                    "redis.call ('SREM', ARGV[2], tagName) ".
                                "end ".
                                "expired = {} ".
                                "expiredCount = 0 ".
                                "notExpiredCount = 0 ".
                            "end ".
                            "return true";
                        $this->_redis->eval($script, $tagsBatch, $sArgs);
                    }
                    $tagsBatch = array();
                    /* Give Redis some time to handle other requests */
                    usleep(20000);
                }
            }
            return;
        }

        $exists = array();
        $tags = (array) $this->_redis->sMembers(self::SET_TAGS);
        foreach ($tags as $tag) {
            // Get list of expired ids for each tag
            $tagMembers = $this->_redis->sMembers(self::PREFIX_TAG_IDS . $tag);
            $numTagMembers = count($tagMembers);
            $expired = array();
            $numExpired = $numNotExpired = 0;
            if ($numTagMembers) {
                while ($id = array_pop($tagMembers)) {
                    if (! isset($exists[$id])) {
                        $exists[$id] = $this->_redis->exists(self::PREFIX_KEY.$id);
                    }
                    if ($exists[$id]) {
                        $numNotExpired++;
                    } else {
                        $numExpired++;
                        $expired[] = $id;

                        // Remove incrementally to reduce memory usage
                        if (count($expired) % 100 == 0 && $numNotExpired > 0) {
                            $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $expired);
                            if ($this->_notMatchingTags) { // Clean up expired ids from ids set
                                $this->_redis->sRem(self::SET_IDS, $expired);
                            }
                            $expired = array();
                        }
                    }
                }
                if (! count($expired)) {
                    continue;
                }
            }

            // Remove empty tags or completely expired tags
            if ($numExpired == $numTagMembers) {
                $this->_redis->unlink(self::PREFIX_TAG_IDS . $tag);
                $this->_redis->sRem(self::SET_TAGS, $tag);
            }
            // Clean up expired ids from tag ids set
            elseif (count($expired)) {
                $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $expired);
                if ($this->_notMatchingTags) { // Clean up expired ids from ids set
                    $this->_redis->sRem(self::SET_IDS, $expired);
                }
            }
            unset($expired);
        }

        // TODO
        // Clean up global list of ids for ids with no tag
//        if ($this->_notMatchingTags) {
//        }
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => runs _collectGarbage()
     * 'matchingTag'    => supported
     * 'notMatchingTag' => supported
     * 'matchingAnyTag' => supported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if ($tags && ! is_array($tags)) {
            $tags = array($tags);
        }

        try {
            if ($mode == Zend_Cache::CLEANING_MODE_ALL) {
                return $this->_redis->flushDb();
            }
            if ($mode == Zend_Cache::CLEANING_MODE_OLD) {
                $this->_collectGarbage();
                return true;
            }
            if (! count($tags)) {
                return true;
            }
            switch ($mode) {
                case Zend_Cache::CLEANING_MODE_MATCHING_TAG:

                    $this->_removeByMatchingTags($tags);
                    break;

                case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:

                    $this->_removeByNotMatchingTags($tags);
                    break;

                case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:

                    $this->_removeByMatchingAnyTags($tags);
                    break;

                default:
                    Zend_Cache::throwException('Invalid mode for clean() method: '.$mode);
            }
        } catch (CredisException $e) {
            Zend_Cache::throwException('Error cleaning cache by mode '.$mode.': '.$e->getMessage(), $e);
        }
        return true;
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return true;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > self::MAX_LIFETIME) {
            Zend_Cache::throwException('Redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Get the auto expiring lifetime.
     *
     * Mainly a workaround for the issues that arise due to the fact that
     * Magento's Enterprise_PageCache module doesn't set any expiry.
     *
     * @param int $lifetime
     * @param string $id
     * @return int Cache lifetime
     */
    protected function _getAutoExpiringLifetime($lifetime, $id)
    {
        if ($lifetime || !$this->_autoExpireLifetime) {
            // If it's already truthy, or there's no auto expire go with it.
            return $lifetime;
        }

        $matches = $this->_matchesAutoExpiringPattern($id);
        if (!$matches) {
            // Only apply auto expire for keys that match the pattern
            return $lifetime;
        }

        if ($this->_autoExpireLifetime > 0) {
            // Return the auto expire lifetime if set
            return $this->_autoExpireLifetime;
        }

        // Return whatever it was set to.
        return $lifetime;
    }

    protected function _matchesAutoExpiringPattern($id)
    {
        $matches = array();
        preg_match($this->_autoExpirePattern, $id, $matches);

        return !empty($matches);
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        if ($this->_notMatchingTags) {
            return (array) $this->_redis->sMembers(self::SET_IDS);
        } else {
            $keys = $this->_redis->keys(self::PREFIX_KEY . '*');
            $prefixLen = strlen(self::PREFIX_KEY);
            foreach ($keys as $index => $key) {
                $keys[$index] = substr($key, $prefixLen);
            }
            return $keys;
        }
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return (array) $this->_redis->sMembers(self::SET_TAGS);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        if ($tags) {
            return (array) $this->_redis->sInter($this->_preprocessTagIds($tags));
        }
        return array();
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a negated logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     * @throws Zend_Cache_Exception
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if (! $this->_notMatchingTags) {
            Zend_Cache::throwException("notMatchingTags is currently disabled.");
        }
        if ($tags) {
            return (array) $this->_redis->sDiff(self::SET_IDS, $this->_preprocessTagIds($tags));
        }
        return (array) $this->_redis->sMembers(self::SET_IDS);
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        $result = array();
        if ($tags) {
            $chunks = array_chunk($tags, $this->_sunionChunkSize);
            foreach ($chunks as $chunk) {
                $result = array_merge($result, (array) $this->_redis->sUnion($this->_preprocessTagIds($chunk)));
            }
            if (count($chunks) > 1) {
                $result = array_unique($result);    // since we are chunking requests, we must de-duplicate member names
            }
        }
        return $result;
    }

    /**
     * Return redis server info and stats
     *
     * @return array
     */
    public function getInfo()
    {
        return $this->_redis->info();
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        try {
            $maxMem = $this->_redis->config('GET', 'maxmemory');
        } catch (CredisException $e) {
            throw new Zend_Cache_Exception($e->getMessage(), 0, $e);
        }
        if (0 == (int) $maxMem['maxmemory']) {
            return 1;
        }
        $info = $this->_redis->info();
        return (int) round(
            ($info['used_memory']/$maxMem['maxmemory']*100)
        );
    }

    /**
     * Return the keyspace hit/miss percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getHitMissPercentage()
    {
        try {
            $info = $this->_redis->info();
        } catch (CredisException $e) {
            throw new Zend_Cache_Exception($e->getMessage(), 0, $e);
        }
        $hits = $info['keyspace_hits'];
        $misses = $info['keyspace_misses'];
        $total = $misses+$hits;
        $percentage = 0;
        if ($total > 0) {
            $percentage = round($hits*100/$total);
        }
        return $percentage;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array|bool array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        list($tags, $mtime, $inf) = array_values(
            $this->_redis->hMGet(self::PREFIX_KEY.$id, array(self::FIELD_TAGS, self::FIELD_MTIME, self::FIELD_INF))
        );
        if (! $mtime) {
            return false;
        }
        $tags = explode(',', $this->_decodeData($tags));
        $expire = $inf === '1' ? false : time() + $this->_redis->ttl(self::PREFIX_KEY.$id);

        return array(
            'expire' => $expire,
            'tags'   => $tags,
            'mtime'  => $mtime,
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $inf = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_INF);
        if ($inf === '0') {
            $expireAt = time() + $this->_redis->ttl(self::PREFIX_KEY.$id) + $extraLifetime;
            return (bool) $this->_redis->expireAt(self::PREFIX_KEY.$id, $expireAt);
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => ($this->_options['automatic_cleaning_factor'] > 0),
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => true,
        );
    }

    /**
     * @param string $data
     * @param int $level
     * @throws CredisException
     * @return string
     */
    protected function _encodeData($data, $level)
    {
        if ($this->_compressionLib && $level !== 0 && strlen($data) >= $this->_compressThreshold) {
            switch($this->_compressionLib) {
                case 'snappy': $data = snappy_compress($data);
                    break;
                case 'lzf':    $data = lzf_compress($data);
                    break;
                case 'l4z':    $data = lz4_compress($data, $level);
                    break;
                case 'zstd':   $data = zstd_compress($data, $level);
                    break;
                case 'gzip':   $data = gzcompress($data, $level);
                    break;
                default:       throw new CredisException("Unrecognized 'compression_lib'.");
            }
            if (! $data) {
                throw new CredisException("Could not compress cache data.");
            }
            return $this->_compressPrefix.$data;
        }
        return $data;
    }

    /**
     * @param bool|string $data
     * @return string
     */
    protected function _decodeData($data)
    {
        try {
            if (substr($data, 2, 3) == self::COMPRESS_PREFIX) {
                switch(substr($data, 0, 2)) {
                    case 'sn': return snappy_uncompress(substr($data, 5));
                    case 'lz': return lzf_decompress(substr($data, 5));
                    case 'l4': return lz4_uncompress(substr($data, 5));
                    case 'zs': return zstd_uncompress(substr($data, 5));
                    case 'gz': case 'zc': return gzuncompress(substr($data, 5));
                }
            }
        } catch(Exception $e) {
            // Some applications will capture the php error that these functions can sometimes generate and throw it as an Exception
            $data = false;
        }
        return $data;
    }

    /**
     * @param $item
     * @param $index
     * @param $prefix
     */
    protected function _preprocess(&$item, $index, $prefix)
    {
        $item = $prefix . $item;
    }

    /**
     * @param $ids
     * @return array
     */
    protected function _preprocessIds($ids)
    {
        array_walk($ids, array($this, '_preprocess'), self::PREFIX_KEY);
        return $ids;
    }

    /**
     * @param $tags
     * @return array
     */
    protected function _preprocessTagIds($tags)
    {
        array_walk($tags, array($this, '_preprocess'), self::PREFIX_TAG_IDS);
        return $tags;
    }

    /**
     * Required to pass unit tests
     *
     * @param  string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->unlink(self::PREFIX_KEY.$id);
    }

    /**
     * Only for unit tests
     */
    public function ___scriptFlush()
    {
        $this->_redis->script('flush');
    }

    /**
     * @return array
     */
    public function ___checkScriptsExist()
    {
        $scripts = [];
        $result = $this->_redis->script('exists', self::LUA_SAVE_SH1, self::LUA_CLEAN_SH1, self::LUA_GC_SH1);
        if ($result[0] ?? false) {
            $scripts[] = 'save';
        }
        if ($result[1] ?? false) {
            $scripts[] = 'clean';
        }
        if ($result[2] ?? false) {
            $scripts[] = 'garbage';
        }
        return $scripts;
    }
}
