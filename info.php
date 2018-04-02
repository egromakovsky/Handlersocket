<?php

// пример использования

require_once 'api/start.php';

// обычные запросы
sharding::mysql('logs')->fetch_query("SELECT * FROM `my_table` WHERE `status`=1");

// HandlerSocket
$user_id = 123;
$info = sharding::globalMhs()->init('user_main')->getOne($user_id);
