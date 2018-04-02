<?php

/**
 * Шард таблицы для snog:
 * База: 
 *	s_top.top_day_	- для расчета рейтинга - в ней таблице с шардингом
 *	u_data_1	- сама шардинг база
 * 
 * Что нужно для шарда?
 *	1. Увеличиваем конкстанту SHARDING_LIMIT
 *	2. api.php - добавляем новые константы для mysql && hs
 *	3. conf/sharding.php - добавляем новый шард (в самом низу)
 */

if (DEV_SERVER)
	ddefine('SHARDING_LIMIT',	1);

ddefine('SHARDING_LIMIT',	1);

class sharding {
	
	/** общий поток на систему */
	public static function globalMhs() {
		return self::UserMHs(0);
	}
	
	/** общий поток на систему */
	public static function hs() {
		return self::globalMhs();
	}
	
	/** */
	public static function userMhs($user_id,$use_local=false) {
		$key	= self::getShardingKey($user_id);
		
		if (!isset($GLOBALS['sharding_mhs']))
                    $GLOBALS['sharding_mhs'] = array();
            
		if (isset($GLOBALS['sharding_mhs'][$key])) {
			$GLOBALS['sharding_mhs'][$key]->setUserId($user_id);
			$GLOBALS['sharding_mhs'][$key]->userLocal($use_local);
			return $GLOBALS['sharding_mhs'][$key];
		}
		
		/** формируем новый поток */
		$GLOBALS['sharding_mhs'][$key]	= new Type_MHs($key,$user_id);	
		$GLOBALS['sharding_mhs'][$key]->userLocal($use_local);
		return $GLOBALS['sharding_mhs'][$key];
	}
	
	/** mysql replica - alias драйвера mysql */
	public static function local($key) {
		return Mysql::Init($key,3);
	}

	/**
	 * @return Mysql
	 */
	public static function mysql($key='main') {
		return Mysql::Init($key);
	}
	
	/** mysql - alias драйвера mysql */
	public static function tt($key=null) {
		if (!isset($GLOBALS['tarantool_driver']))
                    $GLOBALS['tarantool_driver'] = array();
		
		loadConfig('tarantool');
		$key		= $key == null ? TARANTOOL_DEFAULT_DB : $key;
		
		if (isset($GLOBALS['tarantool_driver'][$key])) {
			return $GLOBALS['tarantool_driver'][$key];
		}
		
		$GLOBALS['tarantool_driver'][$key]	= new Type_Tarantool($key);
		$GLOBALS['tarantool_driver'][$key]->onInit();
		return $GLOBALS['tarantool_driver'][$key];
	}
	
	/** закрываем все соединения */
	public static function end() {
		/** линки на разных пользователей mysql */
		if (isset($GLOBALS['sharding_mhs']))
		foreach ($GLOBALS['sharding_mhs'] as $key=>$value) {
			unset($GLOBALS['sharding_mhs'][$key]);
		}
		
		Mysql::end();
		Data::end();
		
		/** убиваем остатки */
		$need_unset	= array(
		    'players_data','mysql_driver','sharding_mhs','memcache_data','tarantool_driver','cron_cache',
			'Type_Instagram_Main','Type_Instagram_Client',
		);
		foreach($need_unset as $value) {
			if (isset($GLOBALS[$value]))
				unset($GLOBALS[$value]);
		}
		
		self::endHsConnect();
	}
	
	/** закрываем все соединения */
	public static function endHsConnect() {
		/** handler socket */
		if (isset($GLOBALS['sharding']))
		foreach ($GLOBALS['sharding'] as $key=>$value) {
			unset($GLOBALS['sharding'][$key]);
		}
			
		self::closeSockHs();
		
		/** убиваем остатки */
		$need_unset	= array(
		    'sharding', 'hs_threads','sock_hs','sharding_mhs'
		);
		foreach($need_unset as $value) {
			if (isset($GLOBALS[$value]))
				unset($GLOBALS[$value]);
		}
	}
	
	/** получаем шаред ключ для пользователя на основе его id */
	public static function getShardingKey($user_id) {
		return 1;
	}
	
	/** классы для работы с сокетами HS */
	public static function getSockHs($host,$port,$pass) {
		if (!isset($GLOBALS['sock_hs']))
			$GLOBALS['sock_hs']	= array();
		
		$key	= "$host:$port";
		if (isset($GLOBALS['sock_hs'][$key]))
			return $GLOBALS['sock_hs'][$key];
		
		/** отправляем запрос  */
		require_once ROOT_PATH . "api/includes/modules/hs_master/src/HSPHP/ReadCommandsInterface.php";
		require_once ROOT_PATH . "api/includes/modules/hs_master/src/HSPHP/ErrorMessage.php";
		require_once ROOT_PATH . "api/includes/modules/hs_master/src/HSPHP/ReadSocket.php";
		require_once ROOT_PATH . "api/includes/modules/hs_master/src/HSPHP/ReadHandler.php";
		require_once ROOT_PATH . "api/includes/modules/hs_master/src/HSPHP/IOException.php";

		$class = new \HSPHP\ReadSocket();
		$class->connect($host,$port,$pass);
		
		$GLOBALS['sock_hs'][$key]	= $class;
		return $class;
	}
	
	/** классы для работы с сокетами HS */
	public static function closeSockHs() {
		if (!isset($GLOBALS['sock_hs']))
			return false;
		
		foreach($GLOBALS['sock_hs'] as $key=>$class) 
			$class->disconnect();
			
		unset($GLOBALS['sock_hs']);
	}

}

/** memcache */
class mCache {
	protected $obj		= null;
	protected $real_key	= '';
	protected $_local	= [];
	
	function __construct() {
		$this->obj = new Memcache;
		//$this->obj->pconnect(PASSWORD_MCACHE_HOST,PASSWORD_MCACHE_PORT);
		$this->obj->connect(PASSWORD_MCACHE_HOST,PASSWORD_MCACHE_PORT);
	}
	
	function getLastRealKey() {
		return $this->real_key;
	}
	
	function get($key,$default=false){
		$key	= self::_getKey($key);
		$this->real_key	= $key;
		
		$local	= $this->getLocal($key);
		if ($local != false)
			return $local;
		
		$output	=  $this->obj->get($key);
		
		$this->setLocal($key, $output);
		System_Debug::logMCache(0,$key,$output);
		return $output == false ? $default : $output;
	}
	
	function delete($key){
		$key	= self::_getKey($key);
		
		$cache_real_key	= $key;
		$this->real_key	= $key;
		$this->obj->set($key,false,MEMCACHE_COMPRESSED,1);
		
		$this->_local[$key]	= false;
		
	}
	
	function set($key,$value, $expire=3600){
		$key	= self::_getKey($key);
		
		if (is_int($value))
			$value	= strval($value);

				
		System_Debug::logMCache(1,$key,$value);
		$this->real_key	= $key;
		$this->obj->set($key,$value,MEMCACHE_COMPRESSED,$expire);
		
		$this->setLocal($key, $value);
	}
	
	//
	protected function getLocal($key) {
		$info	= isset($this->_local[$key]) ? $this->_local[$key] : false;
		
		if ($info == false)
			return false;
		
		if (isset($info['expire']) && $info['expire'] > time())
			return $info['data'];
		
		return false;
		
	}
	
	//
	protected function setLocal($key,$value) {
		$info	= array(
			'expire'	=> is_cron() ? time() + 1 : time() + 2,
			'data'		=> $value,
		);
		
		$this->_local[$key]	= $info;
	}
	
	/** */
	protected function _getKey($key) {
		$ver	= DEV_SERVER ? CACHE_VERSION_DEV : CACHE_VERSION;
		return md5(ROOT_PATH . $ver . $key);
	}
	
	public static function Init() {
		if (isset($GLOBALS['memcache_data']))
			return $GLOBALS['memcache_data'];
		
		/** формируем новый поток */
		$GLOBALS['memcache_data']	= new mCache();
		return $GLOBALS['memcache_data'];
	}
}


// для работы с rabbit
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// класс для управления учередью
class Rabbit {

	//
	protected $queue_name		= '';
	protected $delivery_mode	= AMQPMessage::DELIVERY_MODE_PERSISTENT;
	protected $durable		= true;	// храним очердь на диске

	// технические
	protected $channel		= null;	// храним экземпляр потока
	protected $connection		= null;	// храним экземпляр соединения

	// инициализируем соединение с определенным потоком и очередью
	public function __construct($queue_name) {
		$this->queue_name	= $queue_name;

		// устанавилваем соединение и получаем канал
		$this->connection 	= new AMQPStreamConnection(RABBIT_HOST, RABBIT_PORT, RABBIT_USER, RABBIT_PASS);
		$this->channel 		= $this->connection->channel();

		// создаем очередь
		$this->channel->queue_declare($queue_name, false, $this->durable, false, false);
		return $this->channel;
	}

	// возвращает экземпляр канала для работы с очередью
	public static function Init($queue_name) {
		if (isset($GLOBALS['rabbit_data'][$queue_name])) {
			return $GLOBALS['rabbit_data'][$queue_name];
		}

		//
		if (!isset($GLOBALS['rabbit_data'])) {
			$GLOBALS['rabbit_data']	= [];
		}

		/** формируем новый поток */
		$GLOBALS['rabbit_data'][$queue_name]	= new Rabbit($queue_name);
		return $GLOBALS['rabbit_data'][$queue_name];
	}

	// закрываем все потоки соединения
	public static function End() {
		if (!isset($GLOBALS['rabbit_data']) || count($GLOBALS['rabbit_data']) == 0) {
			return false;
		}

		// перебираем экземпляры и закрываем все соединения
		foreach($GLOBALS['rabbit_data'] as $key => $value) {
			unset($GLOBALS['rabbit_data'][$key]);
			$value->closeAll();
		}

		unset($GLOBALS['rabbit_data']);
	}

	// --------------------------------------------------
	// РАБОЧИЕ ФУНКЦИИ
	// --------------------------------------------------

	// сколько сообщений в очереди
	public function getQueueSize() {
		$info = $this->channel->queue_declare($this->queue_name, false, $this->durable, false, false);
		return isset($info[1]) ? $info[1] : 0;
	}

	// отправить сообщение
	public function sendMessage($txt) {
		if (is_array($txt)) {
			$txt	= toJson($txt);
		}

		$msg = new AMQPMessage($txt, array('delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
		$this->channel->basic_publish($msg, '', $this->queue_name);
	}

	// получить сообщение
	public function waitMessages($callback,$max_queue_size=5) {
		$this->channel->basic_qos(null, $max_queue_size, null);
		$this->channel->basic_consume($this->queue_name, $this->queue_name, false, false, false, false, function($msg) use ($callback) {
			// полезная работа
			$message = $msg->body;

			if (substr($message,0,1) == '[' || substr($message,0,1) == '{') {
				$message	= fromJson($message);
			}

			$result 	= $callback($message);

			// сообщить что элемент очередь можно удалть
			$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);

			if ($result == 'die') {
				$this->channel->basic_cancel($this->queue_name);
			}
		});

		while(count($this->channel->callbacks)) {
			$this->channel->wait();
		}
	}

	// закрываем все соединения
	public function closeAll() {
		$this->channel->close();
		$this->connection->close();
	}

}
