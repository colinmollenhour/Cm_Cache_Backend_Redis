<?php

/**
 * @see Zend_Cache_Backend
 */
require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * Redis adapter for Zend_Cache
 * 
 * @author Soin Stoiana
 * @version 0.0.1
 */
class Zend_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Log message
     */
	
    const SET_IDS  = 'urn:all_ids';
    const SET_TAGS = 'urn:all_tags';
    
    const PREFIX_DATA = 'urn:data:';
    const PREFIX_TAGS = 'urn:tags:';
    const PREFIX_ID_TAGS = 'urn:id_tags:';

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

        $this->_redis = new Redis();
        
        $this->_redis->connect($options['server'], $options['port']);
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
        $tmp = $this->_redis->get(self::PREFIX_DATA . $id);
        
        return $tmp;
    }
    
    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $tmp = $this->_redis->get(self::PREFIX_DATA . $id);
        
        return $tmp;
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
        $this->_redis->sAdd(self::SET_IDS, $id);

        return $result;
    }

    protected function _remove($id)
    {
    	// remove data
    	$this->_redis->delete( self::PREFIX_DATA . $id );
    	
    	// remove id from list of all ids 
    	$this->_redis->sRemove( self::SET_IDS, $id );
    	
    	// get list of tags for this id
    	$tags = $this->_redis->sUnion(self::PREFIX_ID_TAGS . $id);
    	
    	foreach($tags as $tag) 
    	{
    		// update the id list for this tag
            $this->_redis->sRemove(self::PREFIX_TAGS . $tag, $id);
    	}
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
     * 'matchingTag'    => unsupported
     * 'notMatchingTag' => unsupported
     * 'matchingAnyTag' => unsupported
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
            	var_dump('CLEANING_MODE_OLD');
            	die('CLEANING_MODE_OLD');
                Zend_Cache::throwException("CLEANING_MODE_OLD is unsupported by the Redis backend");
                break;
                
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            	
        		$ids = $this->getIdsMatchingTags($tags);
            	
            	foreach($ids as $id) 
            	{
            		$this->_remove($id);
            	}
            	
            	foreach($tags as $tag) 
            	{
            		$this->_redis->delete(self::PREFIX_ID_TAGS . $tag);
            	}
            	break;
            	
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
        		$ids = $this->getIdsNotMatchingTags($tags);
            	
            	foreach($ids as $id) 
            	{
            		$this->_remove($id);
            	}
            	break;
            	
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
            	$ids = $this->getIdsMatchingAnyTags($tags);
            	
            	foreach($ids as $id) 
            	{
            		$this->_remove($id);
            	}
            	
            	foreach($tags as $tag) 
            	{
            		$this->_redis->delete(self::PREFIX_ID_TAGS . $tag);
            	}
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
        Zend_Cache::throwException("getIds()");
        return array();
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        Zend_Cache::throwException('getTags');
        return array();
    }

    protected function _preprocessTag(&$item, $key)
    {
    	$item = self::PREFIX_TAGS . $item;
    }
    
    protected function _preprocessTags($tags) 
    {
    	array_walk( $tags, array($this, '_preprocessTag'));
    	
    	return $tags;
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
        call_user_func_array( array($this->_redis, 'sIntersect'), $this->_preprocessTags($tags) );
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        call_user_func_array( array($this->_redis, 'sDiff'), array_unshift($this->_preprocessTags($tags), self::SET_IDS) );
        return array();
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
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
    	$mtime = time() - $ttl;
    	
    	if(!$ttl) return false; 
    	
        $tags = $this->_redis->sMembers(self::PREFIX_ID_TAGS . $id );
            
            
        return array(
	        'expire' => $ttl, 
	        'tags' => $tags, 
	        'mtime' => $mtime,
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
    	Zend_Cache::throwException("touch");
        
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
            'automatic_cleaning' => false,
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => false,
            'get_list'           => true
        );
    }
}