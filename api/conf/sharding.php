<?php

// конфиги для подключения к базам

$CONFIG['SHARDING_MAP'] = [
	'main'    => [
		'db'      => 'database',
		'mysql'   => [
			'host' => MAIN_MYSQL_HOST,
			'pass' => MAIN_MYSQL_PASS,
			'user' => MAIN_MYSQL_USER,
		],
		'replica' => [
			'host' => MAIN_MYSQL_HOST,
			'pass' => MAIN_MYSQL_PASS,
			'user' => MAIN_MYSQL_USER,
		],
		'hs'      => [
			'host' => MAIN_HS_HOST,
			'pass' => MAIN_HS_PASS,
			'port' => [MAIN_HS_RPORT, MAIN_HS_WPORT],
		],
	],
	'logs'    => [
		'db'      => 'database_logs',
		'mysql'   => [
			'host' => MAIN_MYSQL_HOST,
			'pass' => MAIN_MYSQL_PASS,
			'user' => MAIN_MYSQL_USER,
		],
		'replica' => [
			'host' => MAIN_MYSQL_HOST,
			'pass' => MAIN_MYSQL_PASS,
			'user' => MAIN_MYSQL_USER,
		],
		'hs'      => [
			'host' => MAIN_HS_HOST,
			'pass' => MAIN_HS_PASS,
			'port' => [MAIN_HS_RPORT, MAIN_HS_WPORT],
		],
	]
];