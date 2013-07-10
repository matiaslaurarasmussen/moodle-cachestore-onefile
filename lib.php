<?php

/**
 * The library file for the file cache store.
 *
 * This file is part of the file cache store, it contains the API for interacting with an instance of the store.
 * This is used as a default cache store within the Cache API. It should never be deleted.
 *
 * @package    cachestore_onefile
 * @category   cache
 * @copyright  2013 Matias Rasmussen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The file store class.
 *
 * Configuration options
 *      path:           string: path to the cache directory, if left empty one will be created in the cache directory
 *      autocreate:     true, false
 *      prescan:        true, false
 *
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_onefile extends cache_store implements cache_is_key_aware, cache_is_configurable, cache_is_searchable  {

    /**
     * The name of the store.
     * @var string
     */
    protected $name;

    /**
     * The path used to store files for this store and the definition it was initialised with.
     * @var string
     */
    protected $path = false;

    /**
     * The path in which definition specific sub directories will be created for caching.
     * @var string
     */
    protected $filestorepath = false;


    /**
     * Set to true if we should store files within a single directory.
     * By default we use a nested structure in order to reduce the chance of conflicts and avoid any file system
     * limitations such as maximum files per directory.
     * @var bool
     */
    protected $singledirectory = false;

    /**
     * Set to true when the path should be automatically created if it does not yet exist.
     * @var bool
     */
    protected $autocreate = false;

    /**
     * Set to true if a custom path is being used.
     * @var bool
     */
    protected $custompath = false;

    /**
     * An array of keys we are sure about presently.
     * @var array
     */
    protected $keys = array();

    /**
     * True when the store is ready to be initialised.
     * @var bool
     */
    protected $isready = false;

    /**
     * The cache definition this instance has been initialised with.
     * @var cache_definition
     */
    protected $definition;

    /**
     * A reference to the global $CFG object.
     *
     * You may be asking yourself why on earth this is here, but there is a good reason.
     * By holding onto a reference of the $CFG object we can be absolutely sure that it won't be destroyed before
     * we are done with it.
     * This makes it possible to use a cache within a destructor method for the purposes of
     * delayed writes. Like how the session mechanisms work.
     *
     * @var stdClass
     */
    private $cfg = null;

    /*
     *	File handle that points to the file storing the array of files
     *	@var file
     */
    private $bc_file_handle = null;
    private $bc_file = null;
    private $bc_filename = null;
    private $bc_config_filename = null;
    private $bc_array = null;
    private $bc_modified = false;
    private $bc_ttl = 8640000; // 100 days cache time-to-live
    private $last_purge = 0;
    private $initialized = false;

    private $debug = false;
    /**
     * Constructs the store instance.
     *
     * Noting that this function is not an initialisation. It is used to prepare the store for use.
     * The store will be initialised when required and will be provided with a cache_definition at that time.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        global $CFG;
	
        if (isset($CFG)) {
            // Hold onto a reference of the global $CFG object.
            $this->cfg = $CFG;
        }

        $this->name = $name;
        if (array_key_exists('path', $configuration) && $configuration['path'] !== '') {
            $this->custompath = true;
            $this->autocreate = !empty($configuration['autocreate']);
            $path = (string)$configuration['path'];
            if (!is_dir($path)) {
                if ($this->autocreate) {
                    if (!make_writable_directory($path, false)) {
                        $path = false;
                        debugging('Error trying to autocreate file store path. '.$path, DEBUG_DEVELOPER);
                    }
                } else {
                    $path = false;
                    debugging('The given file cache store path does not exist. '.$path, DEBUG_DEVELOPER);
                }
            }
            if ($path !== false && !is_writable($path)) {
                $path = false;
                debugging('The file cache store path is not writable for `'.$name.'`', DEBUG_DEVELOPER);
            }
        } else {
            $path = make_cache_directory('cachestore_onefile/'.preg_replace('#[^a-zA-Z0-9\.\-_]+#', '', $name));
        }
        $this->isready = $path !== false;
        $this->filestorepath = $path;
        // This will be updated once the store has been initialised for a definition.
        $this->path = $path;

        // Check if we should be storing in a single directory.
        if (array_key_exists('singledirectory', $configuration)) {
            $this->singledirectory = (bool)$configuration['singledirectory'];
        } else {
            // Default: No, we will use multiple directories.
            $this->singledirectory = false;
        }
    }

    /*
     * 	Saves the global cache file
     */
    public function __destruct(){
	if($this->initialized and $this->bc_modified){
		//  write bc array to file
		if($this->debug) {echo "WRITING filename = {$this->bc_filename}<br>";}
		
		@file_put_contents($this->bc_config_filename,serialize(  array("last_purge"=>time())   ));
		@file_put_contents($this->bc_filename,serialize( $this->bc_array));
	} else {
		if($this->debug){ echo "NO CACHE WRITES<br>";}
	}
    }

    /**
     * Performs any necessary operation when the file store instance has been created.
     */
    public function instance_created() {
    }

    /**
     * Returns true if this store instance is ready to be used.
     * @return bool
     */
    public function is_ready() {
        return $this->isready;
    }

    /**
     * Returns true once this instance has been initialised.
     *
     * @return bool
     */
    public function is_initialised() {
        return true;
    }

    /**
     * Returns the supported features as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        $supported = self::SUPPORTS_DATA_GUARANTEE +
                     self::SUPPORTS_NATIVE_TTL +
                     self::IS_SEARCHABLE;
        return $supported;
    }

    /**
     * Returns false as this store does not support multiple identifiers.
     * (This optional function is a performance optimisation; it must be
     * consistent with the value from get_supported_features.)
     *
     * @return bool False
     */
    public function supports_multiple_identifiers() {
        return false;
    }

    /**
     * Returns the supported modes as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        //return self::MODE_APPLICATION + self::MODE_SESSION;
        return self::MODE_APPLICATION;
    }

    /**
     * Returns true if the store requirements are met.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        return true;
    }

    /**
     * Returns true if the given mode is supported by this store.
     *
     * @param int $mode One of cache_store::MODE_*
     * @return bool
     */
    public static function is_supported_mode($mode) {
        //return ($mode === self::MODE_APPLICATION || $mode === self::MODE_SESSION);
        return ($mode === self::MODE_APPLICATION);
    }


    /*
     *   Read the entire file in one single read
     */
    public function get_filedata($filename){
	$file = fopen($filename,"r");
	$filesize = filesize($filename);
	$data = fread($file,$filesize);
	fclose($file);	
	return $data;//file_get_contents($filename);
    }

    /**
     * Initialises the cache.
     *
     * Once this has been done the cache is all set to be used.
     *
     * @param cache_definition $definition
     */
    public function initialise(cache_definition $definition) {
        $this->definition = $definition;
	$hash = preg_replace('#[^a-zA-Z0-9]+#', '_', $this->definition->get_id());
        $this->path = $this->filestorepath.'/'.$hash;
        make_writable_directory($this->path);

	$this->bc_filename = $this->path ."/big.cache";
	$this->bc_config_filename = $this->path ."/big.config";

	// open the big cache
	if(file_exists($this->bc_filename)){
		// fetch cache array
		$data = $this->get_filedata($this->bc_filename);
		$this->bc_array = unserialize($data);
		if($this->debug) { 
			echo $this->bc_filename . " was loaded  <br>";
			echo "<pre>";
			var_dump(array_keys($this->bc_array));
			echo "</pre>";
		}
	} else {
		// initialize empty cache array
		file_put_contents($this->bc_filename,serialize(array()),LOCK_EX);
		// update purge time
		$this->last_purge = time();
		if( $this->debug){
			echo  $this->bc_filename . " was created<br>";
		}
	}

	// open cache config file
	if(file_exists($this->bc_config_filename)){
		$config = unserialize(file_get_contents($this->bc_config_filename));
		$this->last_purge = $config["last_purge"];
	} else {
		file_put_contents($this->bc_config_filename, serialize(array("last_purge"=>$this->last_purge)) );
	}	

	if($this->is_cache_stale()){
		$this->purge();
	}

	// the cache has now been initialized
	$this->initialized = true;
    }




    /**
     *	Determine whether the cache has expired or not
     *
     */
    public function is_cache_stale(){
	$tid = time();
	return ($this->bc_ttl + $this->last_purge) < time() ;
    }

    /**
     * Retrieves an item from the cache store given its key.
     *
     * @param string $key The key to retrieve
     * @return mixed The data that was associated with the key, or false if the key did not exist.
     */
    public function get($key) {
	if($this->debug){echo "GET $key <br>";}

	if($this->bc_array and array_key_exists($key,$this->bc_array)){
		if($this->debug){echo "CACHE HIT<br>";}
		return $this->bc_array[$key];	
	} else {
		if($this->debug){echo "CACHE MISS<br>";}
		return false;
	}
    }

    /**
     * Retrieves several items from the cache store in a single transaction.
     *
     * If not all of the items are available in the cache then the data value for those that are missing will be set to false.
     *
     * @param array $keys The array of keys to retrieve
     * @return array An array of items from the cache. There will be an item for each key, those that were not in the store will
     *      be set to false.
     */
    public function get_many($keys) {
        $result = array();
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Deletes an item from the cache store.
     *
     * @param string $key The key to delete.
     * @return bool Returns true if the operation was a success, false otherwise.
     */
    public function delete($key) {

	unset($this->bc_array[$key]);
	$this->bc_modified = true;

        return true;
    }

    /**
     * Deletes several keys from the cache in a single action.
     *
     * @param array $keys The keys to delete
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys) {
        $count = 0;
        foreach ($keys as $key) {
            if ($this->delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Sets an item in the cache given its key and data value.
     *
     * @param string $key The key to use.
     * @param mixed $data The data to set.
     * @return bool True if the operation was a success false otherwise.
     */
    public function set($key, $data) {

	$this->bc_array[$key] = $data;	
	$this->bc_modified = true;	

	if($this->debug){echo "SET $key";}
	return true;	
    }

    /**
     * Prepares data to be stored in a file.
     *
     * @param mixed $data
     * @return string
     */
    protected function prep_data_before_save($data) {
        return serialize($data);
    }

    /**
     * Prepares the data it has been read from the cache. Undoing what was done in prep_data_before_save.
     *
     * @param string $data
     * @return mixed
     * @throws coding_exception
     */
    protected function prep_data_after_read($data) {
        $result = @unserialize($data);
        if ($result === false) {
            throw new coding_exception('Failed to unserialise data from file. Either failed to read, or failed to write.');
        }
        return $result;
    }

    /**
     * Sets many items in the cache in a single transaction.
     *
     * @param array $keyvaluearray An array of key value pairs. Each item in the array will be an associative array with two
     *      keys, 'key' and 'value'.
     * @return int The number of items successfully set. It is up to the developer to check this matches the number of items
     *      sent ... if they care that is.
     */
    public function set_many(array $keyvaluearray) {
        $count = 0;
        foreach ($keyvaluearray as $pair) {
            if ($this->set($pair['key'], $pair['value'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Checks if the store has a record for the given key and returns true if so.
     *
     * @param string $key
     * @return bool
     */
    public function has($key) {
    	return array_key_exists($key,$this->bc_array);
    }

    /**
     * Returns true if the store contains records for all of the given keys.
     *
     * @param array $keys
     * @return bool
     */
    public function has_all(array $keys) {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if the store contains records for any of the given keys.
     *
     * @param array $keys
     * @return bool
     */
    public function has_any(array $keys) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Purges the cache definition deleting all the items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        $this->bc_array = array();
	$this->bc_modified = true;
	$this->last_purge = time();
	return true;
    }

    /**
     * Purges all the cache definitions deleting all items within them.
     *
     * @return boolean True on success. False otherwise.
     */
    protected function purge_all_definitions() {
        // Warning: limit the deletion to what file store is actually able
        // to create using the internal {@link purge()} providing the
        // {@link $path} with a wildcard to perform a purge action over all the definitions.
        $currpath = $this->path;
        $this->path = $this->filestorepath.'/*';
        $result = $this->purge();
        $this->path = $currpath;
        return $result;
    }

    /**
     * Given the data from the add instance form this function creates a configuration array.
     *
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        $config = array();

        return $config;
    }

    /**
     * Allows the cache store to set its data against the edit form before it is shown to the user.
     *
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config) {
        $data = array();
        $editform->set_data($data);
    }


    /**
     * Performs any necessary clean up when the file store instance is being deleted.
     *
     * 1. Purges the cache directory.
     * 2. Deletes the directory we created for the given definition.
     */
    public function instance_deleted() {



        $this->purge_all_definitions();
        @rmdir($this->filestorepath);
    }

    /**
     * Generates an instance of the cache store that can be used for testing.
     *
     * Returns an instance of the cache store, or false if one cannot be created.
     *
     * @param cache_definition $definition
     * @return cachestore_onefile
     */
    public static function initialise_test_instance(cache_definition $definition) {
        $name = 'OneFile test';
        $path = make_cache_directory('cachestore_onefile_test');
        $cache = new cachestore_onefile($name, array('path' => $path));
        $cache->initialise($definition);
        return $cache;
    }

    /**
     * Returns the name of this instance.
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Finds all of the keys being used by this cache store instance.
     *
     * @return array
     */
    public function find_all() {
    	return array_keys($this->bc_array);
    }

    /**
     * Finds all of the keys whose keys start with the given prefix.
     *
     * @param string $prefix
     */
    public function find_by_prefix($prefix) {
        $this->ensure_path_exists();
        $prefix = preg_replace('#(\*|\?|\[)#', '[$1]', $prefix);
        $files = glob($this->glob_keys_pattern($prefix), GLOB_MARK | GLOB_NOSORT);
        $return = array();
        if ($files === false) {
            return $return;
        }
        foreach ($files as $file) {
            // Trim off ".cache" from the end.
            $return[] = substr(basename($file), 0, -6);
        }
        return $return;
    }
}
