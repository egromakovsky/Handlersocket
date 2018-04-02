<?php

//date_default_timezone_set('Europe/Minsk');
date_default_timezone_set('Europe/Moscow');

//
define('ROOT_PATH'			, dirname(__FILE__).'/../');
define('API_PATH'			      , dirname(__FILE__).'/');
define('TEMPLATE_PATH'		      , dirname(__FILE__).'/templates/');
define('TEMP_PATH'			, dirname(__FILE__).'/../cache/');
define('PHOTO_PATH'			, dirname(__FILE__).'/../www/data/upload/');

//
define('CONFIG_MAX_EXECUTION_TIME'	, 30);
define('CONFIG_MEMORY_LIMIT'		, '60M');
define('CONFIG_WEB_CHARSET'		, 'UTF-8');
define('CONFIG_SQL_CHARSET'		, 'utf8mb4');
define('CONFIG_LOG_MYSQL'		, ROOT_PATH . '/logs/__mysql.log');

// Check Version
if (version_compare(phpversion(), '5.1.0', '<') == true) {
	exit('PHP5.1+ Required');
}

require_once API_PATH . 'define.php';
require_once API_PATH . 'system/mysql.php';
require_once API_PATH . 'system/sharding.php';
require_once API_PATH . 'system/system.php';
require_once API_PATH . 'system/functions.php';

spl_autoload_register('__autoload');
function __autoload($class){
	/* автоподгрузка */
	$file	= str_replace('_','/',$class).'.php';

	$path	= API_PATH. "includes/" . $file;
	$path	= strtolower($path);

	//echo dd($path);

	if (file_exists($path)){
		include $path;
		return;
	}
}
