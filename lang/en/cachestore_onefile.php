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

defined('MOODLE_INTERNAL') || die();

$string['autocreate'] = 'Auto create directory';
$string['autocreate_help'] = 'If enabled the directory specified in path will be automatically created if it does not already exist.';
$string['path'] = 'Cache path';
$string['path_help'] = 'The directory that should be used to store files for this cache store. If left blank (default) a directory will be automatically created in the moodledata directory. This can be used to point a file store towards a directory on a better performing drive (such as one in memory).';
$string['pluginname'] = 'OneFile cache';
$string['prescan'] = 'Prescan directory';
$string['prescan_help'] = 'If enabled the directory is scanned when the cache is first used and requests for files are first checked against the scan data. This can help if you have a slow file system and are finding that file operations are causing you a bottle neck.';
$string['singledirectory'] = 'Single directory store';
$string['singledirectory_help'] = 'If enabled files (cached items) will be stored in a single directory rather than being broken up into multiple directories.<br />
Enabling this will speed up file interactions but comes at the cost of increased risk of hitting file system limitations.<br />
It is advisable to only turn this on if the following is true:<br />
  - If you know the number of items in the cache is going to be small enough that it won\'t cause issues on the file system you are running with.<br />
  - The data being cached is not expensive to generate. If it is then sticking with the default may still be the better option as it reduces the chance of issues.';

/**
 * This is is like the file store, but designed for siutations where:
 *   - many more things are likely to be stored in the cache, so CRC hashing is
 *     too likely to give collisions, and storing everything in a completely flat
 *     directory structure is inadvisable.
 *   - the things we are caching are more expensive to calculate, so the extra
 *     time to computer a better hash is a worthwhile trade-off.
 */
