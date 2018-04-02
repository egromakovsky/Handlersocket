<?php

function getConfig($code,$key=-1){
	global $CONFIG;
	$code	= strtoupper($code);
	if (isset($CONFIG[$code]))
		return $key==-1 ? $CONFIG[$code] : $CONFIG[$code][$key];


	$codes	= explode('_',$code);

	$file	= strtolower($codes[0]);

	loadConfig($file);
	if (!isset($CONFIG[$code])) {
		return false;
	}

	return $key==-1 ? $CONFIG[$code] : $CONFIG[$code][$key];
}

function setConfig($code,$data){
	global $CONFIG;
	$code	= strtoupper($code);
	$CONFIG[$code]	= $data;
}

function loadConfig($file, $module = null){
	global $CONFIG;
	$path	= API_PATH.'/conf/'.$file.'.php';
	if (file_exists($path))
		include $path;

}

function getHsConfig($key) {
	static $hs_conf	= null;

	if ($hs_conf!= null && isset($hs_conf[$key]))
		return $hs_conf[$key];

	$config	= getConfig('SHARDING_MAP');
	foreach($config as $value) {
		$hs_conf[$value['db']]	= $value['hs'];
	}

	return $hs_conf[$key];
}

/** возращает настройки для подключения к базе данных */
function getMysqlConfig($key=null) {
	static $mysql_conf	= null;

	if ($mysql_conf!= null && isset($mysql_conf[$key]))
		return $mysql_conf[$key];

	$config	= getConfig('SHARDING_MAP');
	foreach($config as $k=>$value) {
		$mysql_conf[$k]	= $value['mysql'] + array('db'=>$value['db']);
	}

	return $mysql_conf[$key];
}

/** возращает настройки для подключения к базе данных - по настоящему ее имени */
function getMysqlConfigByDbName($key=null) {
	static $mysql_conf_by_name	= null;

	if ($mysql_conf_by_name!= null && isset($mysql_conf_by_name[$key]))
		return $mysql_conf_by_name[$key];

	$config	= getConfig('SHARDING_MAP');;
	foreach($config as $k=>$value) {
		$mysql_conf_by_name[$value['db']]	= $value['mysql'] + array('db'=>$value['db']);
	}

	return $mysql_conf_by_name[$key];
}

/** конфиг для реплики */
function getMysqlConfigReplica($key = null) {
	static $replica_conf	= null;

	if ($replica_conf!= null && isset($replica_conf[$key]))
		return $replica_conf[$key];

	$config	= getConfig('SHARDING_MAP');
	foreach($config as $k=>$value) {
		$replica_conf[$k]	= $value['replica'] + array('db'=>$value['db']);
	}

	return $replica_conf[$key];
}