<?php

// конфиг для таблиц доступных через HandlerSocket
// реальная структура таблицы должна строго совпадать со структурой в этом конфиге

$CONFIG['HS_PLAYER'] = array();

/**
 * -----------------------------------------------------------------------------
 *				MAIN TABLES
 * -----------------------------------------------------------------------------
 */

// user
ddefine('HS_MAIN'						, 'main_database.user_main#user_id@primary|user_id');


// user
$CONFIG['HS_MAIN']['user_main']		= HS_MAIN	. "@user_id,username,email,created_at,full_name";
