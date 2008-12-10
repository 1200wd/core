<?php
/**
 * Kohana process control file, loaded by the front controller.
 *
 * $Id: bootstrap.php 3733 2008-11-27 01:12:41Z Shadowhand $
 *
 * @package    Core
 * @author     Kohana Team
 * @copyright  (c) 2007-2008 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */

define('KOHANA_VERSION',  '3.0');
define('KOHANA_CODENAME', 'renaissance');

// The fastest way to detect a Windows system
define('KOHANA_IS_WIN', DIRECTORY_SEPARATOR === '\\');

if (extension_loaded('mbstring'))
{
	// Use mb_* utf8 functions when possible
	mb_internal_encoding('UTF-8');
	define('SERVER_UTF8', TRUE);
}
else
{
	// Use internal utf8 functions
	define('SERVER_UTF8', FALSE);
}

// Load the main Kohana class
require SYSPATH.'classes/kohana'.EXT;

// Initialize the evironment
Kohana::init();

// Convert global variables to UTF-8
$_GET    = utf8::clean($_GET, Kohana::$charset);
$_POST   = utf8::clean($_POST, Kohana::$charset);
$_COOKIE = utf8::clean($_COOKIE, Kohana::$charset);
$_SERVER = utf8::clean($_SERVER, Kohana::$charset);

$route = Route::factory('(:controller(/:method(/:id)))')
	->defaults(array('controller' => 'welcome', 'method' => 'index'));

$route = Route::factory('(:path/):file(.:format)', array('path' => '.*'));

echo Kohana::debug($route->matches('templates/awesome.haml'));

// Shutdown the environment
Kohana::shutdown();