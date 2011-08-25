<?php

/**
 * @see Zend_Cache_Backend
 */
#require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
#require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * Redis adapter for Zend_Cache
 * 
 * @author Soin Stoiana
 * @author Colin Mollenhour
 * @version 0.0.1
 */
class Zend_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Log message
     */
	
    const SET_IDS  = 'zc:keys';
    const SET_TAGS = 'zc:tags';
    
    const PREFIX_DATA = 'zc:data:';
    const PREFIX_TAGS = 'zc:tag_ids:';
    const PREFIX_ID_TAGS = 'zc:id_tags:';

    /** @var Redis */
    protected $_redis;

    /** @var bool */
    protected $_notMatchingTags = FALSE;

    /**
     * Contruct Zend_Cache Redis backend
     */
    public function __construct($options = array())
    {
    	if (!extension_loaded('redis')) {
            Zend_Cache::throwException('The redis extension must be loaded for using this backend !');
        }
        
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }

        if( empty($options['server']) ) {
            Zend_Cache::throwException('Redis \'server\' not specified.');
        }

        if( empty($options['port']) ) {
            Zend_Cache::throwException('Redis \'port\' not specified.');
        }

        $this->_redis = new Redis;
        if( ! $this->_redis->connect($options['server'], $options['port']) ) {
            Zend_Cache::throwException("Could not connect to Redis server {$options['server']}:{$options['port']}");
        }

        if ( ! empty($options['database'])) {
            $this->_redis->select( (int) $options['database']) or Zend_Cache::throwException('The redis database could not be selected.');
        }

        if ( isset($options['notMatchingTags']) ) {
            $this->_notMatchingTags = (bool) $options['notMatchingTags'];
        }
    }
    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        return $this->_redis->get(self::PREFIX_DATA . $id);
    }
    
    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return bool (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        return $this->_redis->exists(self::PREFIX_DATA . $id);
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
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
    	if(!is_array($tags)) $tags = array($tags);
    	
        $lifetime = $this->getLifetime($specificLifetime);
        
        if ($lifetime) {
            $result = $this->_redis->setex(self::PREFIX_DATA . $id, $lifetime, $data);
            
        } else {
            $result = $this->_redis->set(self::PREFIX_DATA . $id, $data);
        }

        if (count($tags) > 0) {
            foreach($tags as $tag)
            {
            	// update the id list for this tag
            	$this->_redis->sAdd(self::PREFIX_TAGS . $tag, $id);
            	
            	// update the list with all the tags
            	$this->_redis->sAdd(self::SET_TAGS, $tag);
            	
            	// update the list of tags for this id 
            	$this->_redis->sAdd(self::PREFIX_ID_TAGS . $id, $tag);
            }
        }
        
        // update the list with all the ids
        if($this->_notMatchingTags) {
            $this->_redis->sAdd(self::SET_IDS, $id);
        }

        return $result;
    }

    protected function _remove($id)
    {
    	// remove data
    	$this->_redis->delete( self::PREFIX_DATA . $id );
    	
    	// remove id from list of all ids
        if($this->_notMatchingTags) {
    	    $this->_redis->sRemove( self::SET_IDS, $id );
        }
    	
    	// get list of tags for this id
    	$tags = $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id);

        // update the id list for each tag
        foreach($tags as $tag)
        {
            $this->_redis->sRemove(self::PREFIX_TAGS . $tag, $id);
    	}
    }
    
    protected function _removeByIds($ids)
    {
        if( ! $ids) {
            return;
        }

    	// remove data
        call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIds($ids));

    	// remove ids from list of all ids
        if($this->_notMatchingTags) {
            $args = $ids;
            array_unshift($args, self::SET_IDS);
            call_user_func_array( array($this->_redis, 'sRemove'), $args);
        }

        // update the id list for each tag
        $tags = call_user_func_array( array($this->_redis, 'sInter'), $this->_preprocessIdTags($ids) );
        foreach($tags as $tag)
        {
            $args = $ids;
            array_unshift($args, self::PREFIX_TAGS . $tag);
            call_user_func_array( array($this->_redis, 'sRemove'), $args);
    	}

        // remove tag lists for all ids
        call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIdTags($ids));
    }

    protected function _removeByIdsTags($ids, $tags)
    {
        if($ids) {
            // remove data
            call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIds($ids));

            // remove ids from list of all ids
            if($this->_notMatchingTags) {
                $args = $ids;
                array_unshift($args, self::SET_IDS);
                call_user_func_array( array($this->_redis, 'sRemove'), $args);
            }

            // remove tag lists for all ids
            call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIdTags($ids));
        }

        if($tags) {
            // remove tags
            call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessTags($tags));
        }
    }

    protected function _removeByTags($tags)
    {
        if( ! $tags) {
            return;
        }

        $ids = $this->getIdsMatchingAnyTags($tags);
        if($ids) {
            // remove data
            call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIds($ids));

            // remove tag lists for all ids
            call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessIdTags($ids));

            // remove ids from list of all ids
            if($this->_notMatchingTags) {
                $args = $ids;
                array_unshift($args, self::SET_IDS);
                call_user_func_array( array($this->_redis, 'sRemove'), $args);
            }
        }

        // remove tags
        call_user_func_array( array($this->_redis, 'delete'), $this->_preprocessTags($tags));
        $args = $tags;
        array_unshift($args, self::SET_TAGS);
        call_user_func_array( array($this->_redis, 'sRemove'), $args);
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        return $this->_remove($id);
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
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
        if(!is_array($tags)) $tags = array($tags);
        
        $this->_redis->multi();
            	
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                return $this->_redis->flushDb();
                break;
                
            case Zend_Cache::CLEANING_MODE_OLD:
                //Zend_Cache::throwException("CLEANING_MODE_OLD is unsupported by the Redis backend");
                break;
                
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            	
        		$ids = $this->getIdsMatchingTags($tags);
            	$this->_removeByIdsTags($ids, $tags);
            	break;
            	
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:

        		$ids = $this->getIdsNotMatchingTags($tags);
            	$this->_removeByIds($ids);
            	break;
            	
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:

                $this->_removeByTags($tags);
                break;

            default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                break;
        }
        
        $this->_redis->exec();
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return false;
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
        if ($lifetime > 2592000) {
            Zend_Cache::throwException('redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        if( ! $this->_notMatchingTags) {
            Zend_Cache::throwException("notMatchingTags must be enabled to use getIds.");
        }
        return $this->_redis->sMembers(self::SET_IDS);
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return $this->_redis->sMembers(self::SET_TAGS);
    }

    protected function _preprocess(&$item, $prefix)
    {
    	$item = $prefix . $item;
    }
    
    protected function _preprocessItems($items, $prefix)
    {
    	array_walk( $items, array($this, '_preprocess'), $prefix);
    	return $items;
    }
    
    protected function _preprocessTags($tags)
    {
    	return $this->_preprocessItems($tags, self::PREFIX_TAGS);
    }

    protected function _preprocessIds($ids)
    {
    	return $this->_preprocessItems($ids, self::PREFIX_DATA);
    }

    protected function _preprocessIdTags($ids)
    {
    	return $this->_preprocessItems($ids, self::PREFIX_ID_TAGS);
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
        call_user_func_array( array($this->_redis, 'sInter'), $this->_preprocessTags($tags) );
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a negated logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if( ! $this->_notMatchingTags) {
            Zend_Cache::throwException("notMatchingTags is currently disabled.");
        }

        $args = $this->_preprocessTags($tags);
        if($this->_notMatchingTags) {
          array_unshift($args, self::SET_IDS);
        }
        call_user_func_array( array($this->_redis, 'sDiff'), $args );
        return array();
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
		return call_user_func_array( array($this->_redis, 'sUnion'), $this->_preprocessTags($tags) );
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
//        Zend_Cache::throwException("Filling percentage not supported by the Redis backend");
        return 0;
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
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
    	$ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        if(!$ttl) return false;

        $tags = $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id );

        return array(
	        'expire' => $ttl, 
	        'tags' => $tags, 
	        'mtime' => time() - $ttl,
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
    	$ttl = $this->_redis->ttl(self::PREFIX_DATA . $id);
        if ($ttl) {
          return (bool) $this->_redis->expire(self::PREFIX_DATA . $id, $ttl + $extraLifetime);
        }
        return false;
    }

    /**
     * Required to pass unit tests? huh?
     *
     * @param  string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->expire(self::PREFIX_DATA . $id, 0);
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
            'automatic_cleaning' => false,
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => $this->_notMatchingTags,
        );
    }
}
