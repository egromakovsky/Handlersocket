<?php

/**
 * Класс для легкой миграции из hs -> mysql
 * Полностью автономный класс для шардинга 
 */


/** модуль на для выборки из базы через Handler_Socket */
class MhsSock {
	protected $default_limit	= 1000000; # лимит по умолчанию
	protected $default_offset	= 0; 
	protected $debug		= array();
	
	public function userLocal($value) {
		$this->use_local_host	= $value;
	}
	
	/** Выполнить один запрос в базу \ параметры: $where, $limit, $offser */
	public function get() {
		$params		= func_get_args();
		$this->debug['where']	= $params[0];

		$conf		= $this->_key_used_array[$this->_active_key];
		$conf_db	= getHsConfig($this->_key_used_array[$this->_active_key]['db']);
		
		//echo dd($conf,$conf_db);
		
		$class		= Sharding::getSockHs($conf_db['host'],$conf_db['port'][0],$conf_db['pass']);
		$index		= $class->getIndexId($conf['db'], $conf['table'], $conf['index'], $conf['values']);
		
		/** выбираем */
		$limit		= isset($params[1]) ? intval($params[1]) : $this->default_limit;
		$offset		= isset($params[2]) ? intval($params[2]) : $this->default_offset;
		
		$info		= $class->select($index, '=', $params[0],$limit,$offset); 
		//echo dd($info,$index,$params);
		
		$info		= $this->_afterSelect(array($info));
		return $info;
	}

	/** Возращает только одно значение ввиде массива без ключа */
	public function getOne($where) {
		$this->debug['where']	= $where;
		$res	= each($this->get($where,1,0));
		return isset($res[1]) ? $res[1] : null;
	}

	/** Выполняем multi select запрос по одной таблице */
	public function getMulti($ar_params,$use_cache=true) {
		$ar_params	= (array) $ar_params;
		$conf		= $this->_key_used_array[$this->_active_key];
		$table		= "{$conf['db']}.{$conf['table']}#{$conf['key']}@{$conf['index']}@{$conf['values']}";
		$conf_db	= getHsConfig($this->_key_used_array[$this->_active_key]['db']);

		if (isset($this->use_local_host) && $this->use_local_host == true) {
			$conf_db['host']	= '127.0.0.1';
			$use_cache		= false;
		}
		
		/** проверяем кеш */
		//$cache_key		= MCACHE_HS_MULTI  . md5($table . "|" .  implode('@',$ar_params));
		//$result			= mCache::Init()->get($cache_key);
//		if (!DEV_SERVER && $use_cache && $result !==false) {
//			return $result;
//		}

		$class	= Sharding::getSockHs($conf_db['host'],$conf_db['port'][0],$conf_db['pass']);
		$index	= $class->getIndexId($conf['db'], $conf['table'], $conf['index'], $conf['values']);
		$info	= $class->selectMulti($index, '=', $ar_params); 
		$info	= $this->_afterSelect($info);
		if (isset($GLOBALS['hs_threads'][$conf_db['host']][$conf_db['port'][0]]))
			unset($GLOBALS['hs_threads'][$conf_db['host']][$conf_db['port'][0]]);

		/** пишем в кеш */
		//mCache::Init()->set($cache_key,$info,300);
		return $info;
	}

	/** Выполняем multi select запрос по одной таблице */
	public function getMultiLocal($ar_params) {
		if (defined('IS_CRON') && IS_CRON) {
			return $this->getMulti((array) $ar_params);
		}

		// 06.07.2016 - Аттавизм - Громаковский
		//$this->use_local_host	= true;
		$info	= $this->getMulti($ar_params);
		$this->use_local_host	= false;
		return $info;
	}
	
	/** Форматируем выборку Select в удобный вид */
	protected function _afterSelect($res) {
		$output = array();
		$key            = -1;
		$keys           = explode(",",$this->_key_used_array[$this->_active_key]['values']);
		$indexkey       = !empty($this->_key_used_array[$this->_active_key]['key']) && strlen($this->_key_used_array[$this->_active_key]['key']) ? $this->_key_used_array[$this->_active_key]['key'] : false;

		/** если первичный ключ составной */
		if (substr_count($indexkey, ','))
			$indexkey	= explode(',',$indexkey);

		if (is_array($res))
		foreach($res as $v)
			if (is_array($v))
			foreach ($v as $value) {
					$tt     = @array_combine($keys, $value);

					if (is_array($indexkey)) {
						/** если составной - делаем хеш */
						$hash = '';
						foreach ($indexkey as $mirco_key) {
							$hash.= $tt[$mirco_key] . "_";
						}
						$key	= substr($hash, 0,-1);

					} else {
						/** если ключ простой - одинарный */
				$key    = $indexkey ? $tt[$indexkey] : $key+1;
					}
				$output[$key]       = $tt;
			}
		return $output;
	}
}


class MhsMysql extends MhsSock {
	protected $default_update_limit	= 1; # лимит по умолчанию
	protected $default_update_offset= 0; 
	
	/** для дебага на тестовом сервере */
	protected	$debug	= array();
	
	/** работа с проксями на другом сервере - используется для multi запросов к одному физическому серверу */
	protected	$use_proxy	= false;
	protected	$proxy_conf	= array();
	protected	$_key_used_array = array();
	
	/** 
	 * ----------------------------------------- methods -----------------------------------------
	 */
        public function __construct($init_keys=array()) {
		$this->init_keys	= $init_keys;
		$this->onConstruct();
        }
	
	/** выполняем перед update  */
	public function connect($table,$rows='') {
		if (is_array($rows))
			$rows	= implode(',',$rows);
		
		$key = abs(crc32($table.$rows));
		if (!isset($this->key_used[$key])) {
			$this->key_used[$key] = "{$table}@$rows";
		}
		
		System_Admin::startTime('_initKeys');
		$this->_initKeys();
		System_Admin::endTime('_initKeys');
		return $this->init($key);
	}
	
	/** инициализируем ключ с которым будем работать */
        public function init($key) {
                $this->_active_key = $key;
                return $this;
        }
	
	/** */
        public function update($where_param,$set_param,$limit_param=null,$offset_param=null,$mode='U?') {
		System_Admin::startTime('in update');
		$conf	= $this->_key_used_array[$this->_active_key];
		$driver	= Mysql::init("#{$conf['db']}");
		
		/** проверяем чтобы были указаны ключи  */
		if (!is_array($conf['index_key']) || count($conf['index_key']) == 0) {
			$driver->Error('type_Mysql',"Index key not found. Try edit /conf/hs.php | Db - {$conf['db']} | Table - {$conf['table']}");
			die('type_Mysql' . "Index key not found. Try edit /conf/hs.php \ Db - {$conf['db']} \ Table - {$conf['table']}");
		}
		
		/** формируем where */
		$where		= array();
		$tt		= is_array($where_param) ? array_values($where_param) : explode(',',$where_param);
		foreach($tt as $key=>$value) {
			$k		= $conf['index_key'][$key];
			$v		= mysql_escape_string($value);
			$where[]	= " `$k` = '$v' ";
		}
		$where		= ' WHERE ' . implode(' AND ',$where);
		
		/** формируем SET */
		$set		= array();
		foreach ($set_param as $key => $value) {
			$value		= mysql_escape_string($value);
			switch ($mode) {
				case 'U?':
					$set[]		= " `{$key}` =  '$value' ";
					break;
				case '+?':
					$set[]		= " `{$key}` =  `{$key}` + $value ";
					break;
				case '-?':
					$set[]		= " `{$key}` =  `{$key}` - $value ";
					break;
			}
		}
		$set		= ' SET ' . implode(',',$set);
		
		/** формируем limit */
		$limit		= ' LIMIT 1 ';
		if ($limit_param != null) {
			$limit	= $offset_param != null ? " LIMIT {$offset_param},{$limit_param}" : " LIMIT {$limit_param}";
		}
		
		/** формируем QUERY */
		switch ($mode) {
			case 'U?':
			case '+?':
			case '-?':
				$query		= "UPDATE {$conf['table']} {$set} {$where} {$limit}";
				break;
			default:
				$driver->Error('type_Mysql',"Error mode in update funciton");
				die('type_Mysql' . "Error mode in update funciton");
				break;
		}
		
		//echo "{$query}<br>";
		/** выполняем */
		System_Admin::endTime('in update');
		
		System_Admin::startTime('Wait Query');
		$driver->query($query);
		System_Admin::endTime('Wait Query');
		
//		echo dd($where_param,$set_param,$this->_key_used_array[$this->_active_key],$where,$set);
//		echo dd($query);
//		exit;
		
		$this->update_res	= $driver->rows_changed();
		return $this->update_res;
        }
	
	/** */
	public function delete($where,$limit=null,$offest=null) {
                return $this->update($where,array(),$limit,$offest,'D?'); 
        }
	
	/** alias */
	public function del($table,$where,$limit=null,$offest=null) {
                return $this->connect($table)->update($where,array(),$limit,$offest,'D?'); 
        }
        
	/** alias update */
	public function set($where,$set,$limit=null,$offest=null) {
		return $this->update($where,$set,$limit,$offest);
	}
	
	/** увеличиваем счетчик и возврщаем новое значение */
	public function inc($where,$inc=1) {
		return $this->update($where,$inc,1,0,'+?');
        }
	
	/** уменьшаем счетчик и возврщаем новое значение */
        public function dec($where,$dec=1) {
		return $this->update($where,$dec,1,0,'-?');
        }
	
	/** не надежно - работает только когда одиночный ключ  */
	public function isUpdateOk() {
		return $this->update_res;
	}
	
	/** Вставка напрямую, в нужную таблицу */
	public function insert($table,$set) {
		$this->connect($table, array_keys($set));
		$conf	= $this->_key_used_array[$this->_active_key];
		$driver	= Mysql::init("#{$conf['db']}");
		$driver->insert_ar($conf['table'], array($set));
		$this->update_res	= $driver->rows_changed();
		return $this->isUpdateOk();
	}
	
	/** ------------------------------------------------------- PROTECTED */
	
	/** вызывается при создания класса */
	protected function onConstruct() {
		if (count($this->init_keys)) {
			foreach ($this->init_keys as $key=>$value) {
				if (!isset($this->key_used[$key])) 
					$this->key_used[$key]	= $value;
			}
		}
			
                $this->_initkeys();
	}
	
	/** вызывается при создания класса */
	protected function _initKeys($keys = null,$force=false) {
		/** мы можем передать сюда ключи из дочернего класса ,предварительно их обработав */
		if ($keys == null) {
			$keys = $this->key_used;
		} 
		
		$i = 0;
		
		if (!isset($this->_key_used_array) || $force)
			$this->_key_used_array = array();
		
                /** формируем ключи в удобный вид */
                foreach ($keys as $key=>$value){
			if (isset($this->_key_used_array[$key]))
				continue;

			$i++;
			$tt_dog		= explode('@',$value);
			$tt_dot		= explode('.',$tt_dog[0]);
			$tt_hash	= explode('#',$tt_dot[1]);
			$index_key	= explode('|',$tt_dog[1]);
			$tt_dog[1]	= $index_key[0];
			$index_key	= isset($index_key[1]) ? explode(',',$index_key[1]) : '';
			
			$this->_key_used_array[$key] = array(
				'id'         => $i,
				'db'         => $tt_dot[0],
				'table'      => $tt_hash[0],
				'index'      => !isset($tt_dog[1]) || $tt_dog[1] =='' || $tt_dog[1]== 'primary' ? HandlerSocket::PRIMARY : $tt_dog[1],
				'index_key'  => $index_key,
				'values'     => isset($tt_dog[2]) ? $tt_dog[2] : '',
				'key'        => isset($tt_hash[1]) && strlen($tt_hash[1]) ? $tt_hash[1] : null,
				'filters'    => array(),
				'active_r'   => false,
				'active_w'   => false,
			);
                }
	}
}




class Type_MHS extends MhsMysql {
	public $_cache		= null; // С ним работает класс Sharding_Mysql - он общий для двоих 
	protected $SHARED_KEY	= null;
	protected $user_id	= null;		
	
	function __construct($shred_key=null,$user_id=null) {
		$this->SHARED_KEY	= $shred_key;
		$init_keys		= getConfig('HS_PLAYER');
		$this->user_id		= $user_id;
		if (!isset($this->_cache[$user_id]))
			$this->_cache[$user_id]	= array();
		
		parent::__construct($init_keys);
	}
	
	/**
	 * Одна из главных функций. Обертка над родительской.
	 * Ее идея в том, что каждый раз когда мы добавляем рабочий ключ в окружение, мы сначала заменяем его SHARED ключ, а потом отдаем в работу.
	 */
	protected function _initKeys($force=false) {
		$keys	= array();
		foreach ($this->key_used as $key=>$value){
			$value = str_replace("%SHARD%", $this->SHARED_KEY, $value);
			$keys[$key]	= $value;
		}
		parent::_initKeys($keys,$force);
	}
	
	/** вызываем после создания Player \ для пересчета префиксов */
	public function reloadKeys() {
		$this->_initKeys(true);
	}
	
	/** переопределенна функция */
	public function getOne($where=null) {
		if ($where===null)
			$where	= $this->user_id;
		
		return parent::getOne($where);
	}
	
	/** изменяем user_id для работы в хранилищами */
	public function setUserId($user_id) {
		if ($this->user_id == $user_id)
			return true;
		
		$this->user_id = $user_id;
		if (!isset($this->_cache[$user_id]))
			$this->_cache[$user_id]	= array();
		
		$this->_initKeys(true);
	}
	
	/**
	 * У пользователей есть хрнилища разных вещей, например медалей вида (user_id,data)
	 * Где в data храняться через | RESPONSE_DELIMITER - значения
	 * Чтобы автоматизировать и упростить процесс работы с такими хранилищами используем эту функцию
	 */
	public function updateData($hs_const,$id,$action='add',$limit=null) {
		$list	= $this->getData($hs_const);
		
		foreach ($list as $key=>$value)
			if ($value == $id || intval($value)==0)
				unset($list[$key]);
			
		$set	= array();
		if ($action=='add') 
			array_unshift($list,$id);
		
		if ($limit!=null && count($list)>$limit) {
			$id_delete	= array_pop($list);
		}
		
		$this->setData($hs_const,$list);
	}
	
	
	/** очищаем хранилище - пример медальки ZERO*/
	public function clearData($hs_const) {
		$this->setData($hs_const,array());
		if (isset($this->_cache[$this->user_id][$hs_const]))
			unset($this->_cache[$this->user_id][$hs_const]);
	}
	
	/** 
	 * Возращает $list - список объектов из локального хранилища пользователя описываемого контактой $hs_const - HS_USER_...
	 * Если хранилище не создано, создает его и возращает пустоц массив
	 */
	public function getData($hs_const) {
		if (isset($this->_cache[$this->user_id][$hs_const])) 
			return $this->_cache[$this->user_id][$hs_const];
		
		$info		= $this->connect($hs_const,"user_id,data")->getOne($this->user_id);
		if (isset($info['data'])) {
			$list	= getnotnull(explode(RESPONSE_DELIMITER,$info['data']));
		} else {
			$this->insert($hs_const,array('user_id'=>$this->user_id));
			$list	= array();
		}
		
		$this->_cache[$this->user_id][$hs_const]	= $list;
		return $list;
	}
	
	/** */
	public function resetCache() {
		unset($this->_cache[$this->user_id]);
	}
	
	/** добавления \ удаления */
	public function setData($hs_const,$data) {
		if (is_array($data))
			$data	= implode(RESPONSE_DELIMITER, $data);
		
		/** 
		 * если кеш не читался - читаем принудилтьено, чтобы проверить что в таблице есть запись для пользователя 
		 * А если записи нет -чтение автоматические ее создаст
		 */
		if (!isset($this->_cache[$this->user_id][$hs_const]))
			$this->getData($hs_const);
		
		$this->connect($hs_const,'data')->set($this->user_id,array('data'=>$data));
		$this->_cache[$this->user_id][$hs_const]	= getnotnull(explode(RESPONSE_DELIMITER, $data));
	}
}



/**
 * Класс для отлова всех запросов к MHS
 * Пока не работает.
 */
class type_mhs_debug {
	protected static $log		= array();
	protected static $read_count	= 0;
	protected static $write_count	= 0;
	
	/** собираем запросы к бд */
	public static function logRead($init,&$info,$result) {
		if (!DEV_SERVER || defined('DEBUG_HS_STOP') || defined('IS_CRON'))
			return false;
		
		if (!isset($info['where']))
			return false;
		
		self::$log[]	= array(
			'type'	=> 0,
			'init'	=> $init,
			'where'	=> $info['where'],
			'result'=> $result
		);
		
		self::$read_count++;
		
		$where	= array();
	}
	
	/** собираем запросы к бд */
	public static function logWrite($type,$init,&$info) {
		if (!DEV_SERVER || defined('DEBUG_HS_STOP') || defined('IS_CRON'))
			return false;
		
		self::$log[]	= array(
			'type'	=> $type,
			'init'	=> $init,
			'where'	=> isset($info['where'])? $info['where'] : '', 
			'set'	=> isset($info['set'])? $info['set'] : ''
		);
		
		self::$write_count++;
		$where	= array();
	}
	
	/** */
	public static function getLog(&$read,&$write) {
		$read	= self::$read_count;
		$write	= self::$write_count;
		return self::$log;
	}
}
