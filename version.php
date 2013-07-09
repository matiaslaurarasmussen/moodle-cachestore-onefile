<?php

/**
 * Cache file store version information.
 *
 * This is used as a default cache store within the Cache API. It should never be deleted.
 *
 * @package    cachestore_onefile
 * @category   cache
 * @copyright  2013 Matias Rasmussen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$plugin->version = 2012112901;    // The current module version (Date: YYYYMMDDXX)
$plugin->requires = 2012112900;    // Requires this Moodle version.
$plugin->component = 'cachestore_onefile';  // Full name of the plugin.
