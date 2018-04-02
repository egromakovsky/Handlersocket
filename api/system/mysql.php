<?php

class Mysql {
	
	/** к каим базам можно обратиться */
	public $mysql_link	= null;
	
	public static function init($db,$replica=false) {
		if (!isset($GLOBALS['mysql_driver']))
                    $GLOBALS['mysql_driver'] = array();
		
		/** если передали в качестве параметра базу из HS */
		if (substr($db, 0,1) == '#') {
			$config		= getMysqlConfigByDbName(substr($db, 1));
			$key		= $config['db'];
		} elseif ($replica) {
			$config		= getMysqlConfigReplica($db);
			$key		= $config['db'] . '_replica';
		} else {
			$config		= getMysqlConfig($db);
			$key		= $config['db'];
		}
		
		//echo dd($key,$config,$db);
		if (isset($GLOBALS['mysql_driver'][$key])) {
			$GLOBALS['mysql_driver'][$key]->selectDb($config);
			return $GLOBALS['mysql_driver'][$key];
		}
		
		$GLOBALS['mysql_driver'][$key]	= new Mysql($config);
		$GLOBALS['mysql_driver'][$key]->onInit();
		return $GLOBALS['mysql_driver'][$key];
	}
	
	public static function end() {
		if (!isset($GLOBALS['mysql_driver']) || !is_array($GLOBALS['mysql_driver']))	
			return;
		
		foreach($GLOBALS['mysql_driver'] as &$con) {
			foreach($con as &$v) {
				@mysql_close($v); 
				$v	= null;
			}
			unset($v);
			$con	= null;
		}
		
		unset($GLOBALS['mysql_driver']);	
	}
	
	/** вызывается после создания экземлпяра класса */
	protected function onInit() {
		
	}
	
	function __construct($config) {
		$this->mysql_link	= $this->Connect($config);
	}
	
	/** */
	public function selectDb($config) {
		mysql_select_db($config['db'], $this->mysql_link);
	}
	
	/** */
	public function Connect($config=null,$default=true){
		$start	= microtime(true);
		$force	= !$default;
		$mysql_link = @mysql_connect($config['host'], $config['user'],	$config['pass'],$force);
		if (!$mysql_link)
			self::error('Could not connect to DB.',mysql_error());


		mysql_select_db($config['db'], $mysql_link)
					or self::error('Can\'t select DB!',mysql_error());
		
		mysql_query("set names " . CONFIG_SQL_CHARSET ,$mysql_link);
		
		if (!isset($GLOBALS['mysql_time']))
			$GLOBALS['mysql_time']	= 0;
		$GLOBALS['mysql_time']	+=  microtime(true)-$start;
		return $mysql_link;
	}
	
	/** */
	public function query($query, $link=NULL,$canDie	= true){
		//echo "{$query}\n";
		
		if (defined('MYSQL_SHOW_QUERYS') && MYSQL_SHOW_QUERYS) {
			echo "------mysql query log ------\n{$query}\n-------\n";
		}
		
		if ($link==NULL) $link=$this->mysql_link;
		
		if (defined('ADMIN')&&  ADMIN && isset($_REQUEST['DEBUG']))
			$GLOBALS['debug_info'][] = $query;

		$GLOBALS['mysql_queries'][]	= $query;
		$start	= microtime(true);
		$result = mysql_query($query, $link);

		if ($canDie	&& !$result)
			self::error('Error in query.',$query."<br>".mysql_error());

		$end	= microtime(true);
		
		if (!isset($GLOBALS['mysql_count']))
			$GLOBALS['mysql_count'] = 0;
		
		$GLOBALS['mysql_count']++;
		$GLOBALS['mysql_time']	+= $end-$start;
		return $result;
	}

	/** */
	public function rows_changed($link=null){
		if ($link==NULL) $link=$this->mysql_link;
		return mysql_affected_rows($link);
	}

	/** */
	public function fetch($resource){
		return mysql_fetch_assoc($resource);
	}
	
	/** */
	public function fetch_array($result,$key	=null){
		$output	= array();
		while($f	= $this->fetch($result)){
			if ($key!=null && $key!==-1)
				$output[$f[$key]]= $f;
			else
				$output[]= $f;
		}
		return $output;
	}
	
	/** */
	public function fetch_array_query($query,$key	=null){
		return $this->fetch_array($this->query($query),$key);
	}
	
	/** */
	public function fetch_query($key,$query=null){
		if (empty($query)) {
			$query	= $key;
			$key	= null;
		}
		$result		= $this->query($query);
		$f		= $this->fetch($result);

		if (empty($key))
			return $f;

		return $f[$key];
	}

	/** */
	public static function Error($small, $description,$log_file=null){
		if (DISPLAY_ERRORS)
			echo nl2br($description)."<BR>";

		$description	= str_replace("\r","",		$description);
//		$desc		= str_replace("\n","<br>",$description);
//		$small		= str_replace("\n","<br>",$small);
		$date		= date("d.M.Y H:i:s");
		
		$ip		= isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "-LOCAL-";

		/** */
		if (true) {
			$handle	= fopen(CONFIG_LOG_MYSQL,"at")
					or die ("Can't write LOG file");
			
			$e = new Exception();
			$debug	= (str_replace('', '', $e->getTraceAsString()));
			
			fwrite($handle,	"[$date] [$ip] $small : \n$description \n\n" . "$debug \n\n");
			fclose($handle);
		}

		if ($small!==null)
			die($small);
	}
	
	/** */
	public function insert($table,$ar_set) {
		return $this->insert_ar($table, [$ar_set]);
		
	
	}
	
	/** */
	public function lastInsertId() {
		return mysql_insert_id($this->mysql_link);
	}
	
	/** */
	public function insert_ar($table,$ar_set,$ignore=true,$isDelayed=false) {
		$query	= self::formatFromAr($table, $ar_set, $ignore, $isDelayed);
		return $this->query($query,false);
	}
	
	/** */
	public static function formatFromAr($table,$ar_set,$ignore=true,$isDelayed=false) {
		if (!is_array($ar_set) || count($ar_set)<1)
			return false;

		$ins_key	= true;
		$keys	= ""; $values = ""; $qq = "";
		foreach ($ar_set as $ar_query)
		{
			foreach($ar_query as $key => $value)
			{
				if ($ins_key) 
					{$keys .= "`$key`" . ",";}
					
				$values .= "'$value',";
			}
			$values = substr($values,0,-1);
			$ins_key = false;
			$qq .= "($values),"; $values = "";
		}
		$keys = substr($keys,0,-1);

		$table	= strpos($table,'.')===false ? "`$table`" : $table;
		$extra	= $ignore? 'IGNORE' : '';
		$delayed	= $isDelayed ? 'delayed': '';
		$query	= "INSERT $delayed $extra INTO $table ($keys)  \n VALUES \n" . substr($qq,0,-1);
		return $query;
	}
	
	/** */
	public function isDebug($level){
		if (!defined('DEBUG'))
			return false;
		return DEBUG >= $level;
	}
	
}