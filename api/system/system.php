<?php

/**
 * ОБщая работа с системой
 */

/** управление админами */
class System_Admin {

	protected static $time_work = array();

	/** admin_log */
	public static function log($write) {
		if (is_array($write))
			$write = dd($write);

		$date = date('Y-m-d H:i');
		$message = "$date\t$write";
		//$message	= str_replace("\n",'\n',$message);
		file_put_contents(CONFIG_LOG_ADMIN, $message . "\n", FILE_APPEND);
	}

	/** */
	public static function write($file, $data, $override = false) {
		if (is_array($data))
			$data = dd($data);

		file_put_contents(ROOT_PATH . '/logs/' . $file . '.log', $data . "\n", $override ? 0 : FILE_APPEND);
	}

	/** */
	public static function isAllowIp($ip = null) {
		if ($ip == null) {
			$ip = getip();
		}

		$ip_ar = explode('.', $ip);
		$list = getConfig('GLOBAL_ALLOW_IP');

		foreach ($list as $allow_ip) {
			$allow_ip_ar = explode('.', $allow_ip);
			$allow_result = true;
			foreach ($allow_ip_ar as $key => $d) {
				if ($d == '*') {
					continue;
				}

				$d = intval($d);

				if ($d != intval($ip_ar[$key]) || intval($ip_ar[$key]) < 1 || $d < 1) {
					$allow_result = false;
				}
			}

			if ($allow_result) {
				return true;
			}
		}

		//
		System_Admin::log("--------------- IsAllowIP Error --- " . date(DATE_FORMAT_FULL));
		System_Admin::log(dd($list));
		System_Admin::log("Client ip: {$ip} ");

		return false;
	}

	/** */
	public static function startTime($key) {
		if (!DEV_SERVER)
			return false;

		if (!isset(self::$time_work['start']))
			self::$time_work['start'] = array();

		self::$time_work['start'][$key] = microtime(true);
	}

	/** */
	public static function endTime($key) {
		if (!DEV_SERVER)
			return false;

		if (!isset(self::$time_work['start'][$key]))
			return;

		$end = microtime(true);
		$start = self::$time_work['start'][$key];

		if (!isset(self::$time_work['result']))
			self::$time_work['result'] = array();

		if (!isset(self::$time_work['result'][$key]))
			self::$time_work['result'][$key] = 0;

		self::$time_work['result'][$key] += round($end - $start, 3);
	}

	/** */
	public static function getTimeList() {
		if (!isset(self::$time_work['result']))
			self::$time_work['result'] = array();

		return self::$time_work['result'];
	}
}

/** */
class System_Player {

	/** возращает только то что можно передать на клиент - из общего массива пользователя */
	public static function getJs($user) {
		/**
		 * !ВНИМАНИЕ:
		 * - при добавлении ключей в этот массив, необходимо их добавить и конфиг сфинкса
		 * - в противном случае кеширование через Registry::addUserInfoFromSphinx из сфинкса не будет работать, а будет заново тянуть все записи из базы
		 */
		$allow = array(
			'user_id',
			'name'
		);
		$output = array();

		if (System_Admin::isAdmin())
			return $user;

		foreach ($allow as $key) {
			$output[$key] = isset($user[$key]) ? $user[$key] : '';
		}

		/** дополнительные данные игрока */

		return $output;
	}

	//
	public static function isCrm() {
		$is_crm = false;
		if (defined('IS_CRM'))
			return IS_CRM;

		$is_crm = Player::init()->isCrm();
		define('IS_CRM', $is_crm);

		return $is_crm;
	}

	/**
	 * не позволяет выполнять одинаковые действия в течении определенного времени, больше $count раз
	 * используется: защита от множества регистраций, брута паролей и /т/п
	 * использует блокировку по IP
	 */
	public static function isBlock($function_name, $count, $time = 3600) {

		//		if (DEV_SERVER && substr_count($_SERVER['HTTP_HOST'], '.dev'))
		//			return false;
		//echo __METHOD__;

		$ip = getip();
		$key = '_block_' . $function_name . '_' . $ip;

		$now = mCache::Init()->get($key, 0);
		//echo $now;

		if ($now >= $count) {
			return true;
		}

		$now++;
		mCache::Init()->set($key, $now, $time);

		return false;
	}

	//
	public static function getBlockCount($function_name) {
		$ip = getip();
		$key = '_block_' . $function_name . '_' . $ip;
		return mCache::Init()->get($key, 0);
	}

	// стереть блок
	public static function clearBlock($function_name) {
		$ip = getip();
		$key = '_block_' . $function_name . '_' . $ip;

		mCache::Init()->delete($key);
	}
}

class System_Debug {

	protected static $_log_sphinx = array();
	protected static $_log_mcache = array();
	protected static $mcache_get_ok = 0;
	protected static $mcache_get_bad = 0;
	protected static $mcache_write = 0;

	public static function getHsLogHtml() {
		$log = type_mhs_debug::getLog($read, $write);

		if ($read == 0 && $write == 0)
			return false;

		$all = $read + $write;
		$output = array(
			'title' => "Hs all {$all} | Read {$read} | Write {$write}"
		);

		$text = '';
		#$output['text']	= dd(self::$log);
		foreach ($log as $value) {
			if ($value['type'] === 0) {
				$text .= self::getHsReadHtml($value);
			} else {
				$text .= self::getHsWriteHtml($value);
			}
		}
		$output['text'] = $text;

		return $output;
	}

	/** */
	public static function getShpinxLogHtml() {
		if (count(self::$_log_sphinx) == 0)
			return false;

		$output = array(
			'title' => "Sphinx Read " . count(self::$_log_sphinx)
		);

		$text = '';
		#$output['text']	= dd(self::$log);
		foreach (self::$_log_sphinx as $value) {
			$text .= self::getSphinxReadHtml($value);
		}
		$output['text'] = $text;

		return $output;
	}

	/** */
	public static function logSphinx($index, $where, $result) {
		if (!DEV_SERVER || defined('DEBUG_HS_STOP') || defined('IS_CRON'))
			return false;

		self::$_log_sphinx[] = array(
			'index'  => $index,
			'where'  => $where,
			'result' => $result
		);
	}

	/** */
	public static function logMCache($type, $key, $result) {
		if (!DEV_SERVER || defined('DEBUG_HS_STOP') || defined('IS_CRON'))
			return false;

		if ($type == 0) {
			if ($result === false)
				self::$mcache_get_bad++; else
				self::$mcache_get_ok++;
		} else {
			self::$mcache_write++;
		}

		self::$_log_mcache[] = array(
			'type'   => $type,
			'key'    => $key,
			'result' => $result
		);
	}

	/** */
	public static function getmCacheLogHtml() {
		if (count(self::$_log_mcache) == 0)
			return false;

		$a = self::$mcache_get_ok;
		$b = self::$mcache_get_bad;
		$write = self::$mcache_write;

		$output = array(
			'title' => "mCache Read [{$a},{$b}] | Write {$write}"
		);

		$text = '';
		#$output['text']	= dd(self::$log);
		foreach (self::$_log_mcache as $value) {
			if ($value['type'] == 0)
				$text .= self::getMCacheReadHtml($value); else
				$text .= self::getMCacheWriteHtml($value);
		}
		$output['text'] = $text;

		return $output;
	}

	/** */
	protected static function getHsReadHtml($value) {
		$where = dd($value['where']);
		$count_result = count($value['result']);
		$result = dd($value['result']);

		$html = <<<HTML
		<div class="mb15">
			<div class='mb5 pointer' onclick="$(this).parent().find('div div[rel=result]').toggleClass('hidden')"><span class="f_s18 green">{$value['init']} [{$count_result}]</span></div>
			<div>
				<div class="border_box_1 mb10 p10">{$where}</div>
				<div class="border_box_1 mb10 p10 hidden" rel="result">
					{$result}
				</div>
			</div>
		</div>

HTML;

		return $html;
	}

	/** */
	protected static function getHsWriteHtml($value) {
		$where = dd($value['where']);
		$set = dd($value['set']);
		$html = <<<HTML
		<div class="mb15">
			<div class='mb5 pointer' onclick="$(this).parent().find('div div[rel=result]').toggleClass('hidden')"><span class="f_s18 red">{$value['type']}&nbsp;&nbsp;</span><span class="f_s18 gray">{$value['init']}</span></div>
			<div>
				<div class="border_box_1 mb10 p10">{$where}</div>
				<div class="border_box_1 mb10 p10 hidden" rel="result">
					{$set}
				</div>
			</div>
		</div>

HTML;

		return $html;
	}

	/** */
	protected static function getMCacheReadHtml($value) {
		$result = dd($value['result']);

		if ($value['result'] == false)
			$class = 'red'; else
			$class = 'green';

		$html = <<<HTML
		<div class="mb15">
			<div class='mb5 pointer' onclick="$(this).parent().find('div div[rel=result]').toggleClass('hidden')"><span class="f_s18 {$class}">{$value['key']}</span></div>
			<div>
				<div class="border_box_1 mb10 p10 hidden" rel="result">
					{$result}
				</div>
			</div>
		</div>

HTML;

		return $html;
	}

	/** */
	protected static function getMCacheWriteHtml($value) {
		$set = dd($value['result']);
		$html = <<<HTML
		<div class="mb15">
			<div class='mb5 pointer' onclick="$(this).parent().find('div div[rel=result]').toggleClass('hidden')"><span class="f_s18">{$value['key']}</span></div>
			<div>
				<div class="border_box_1 mb10 p10 hidden" rel="result">
					{$set}
				</div>
			</div>
		</div>

HTML;

		return $html;
	}

	/** */
	protected static function getSphinxReadHtml($value) {
		$where = dd($value['where']);
		$count_result = count($value['result']);
		$result = dd($value['result']);

		$html = <<<HTML
		<div class="mb15">
			<div class='mb5 pointer' onclick="$(this).parent().find('div div[rel=result]').toggleClass('hidden')"><span class="f_s18 green">{$value['index']} [{$count_result}]</span></div>
			<div>
				<div class="border_box_1 mb10 p10">{$where}</div>
				<div class="border_box_1 mb10 p10 hidden" rel="result">
					{$result}
				</div>
			</div>
		</div>

HTML;

		return $html;
	}
}

class System {

	/** */
	public static function getNextId($key) {
		$inc = array(
			'value' => 1
		);

		/** получаем данные из служебной таблицы */
		$is_ok = sharding::globalMhs()->connect(HS_AUTO_INCREMENT, ak($inc))->inc($key, $inc);

		if (!$is_ok) {
			/** вставляем новое значение в служебную таблицу */
			$insert = array(
				'db_table_md5' => $key,
				'value'        => 1,
				'comment'      => $key
			);
			sharding::globalMhs()->insert(HS_AUTO_INCREMENT, $insert);
		}

		$info = sharding::globalMhs()->init('auto_increment')->getOne($key);

		return $info['value'];
	}

	/** */
	public static function getFlag($key) {
		$conf = getConfig('SHOP_FLAGS');

		if (isset($conf[$key]))
			return $conf[$key];

		return false;
	}

	// is may be -> Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini
	public static function isMobile($is = null) {
		$type_mobile = type_ua::isMobile();

		if ($is != null) {
			if (substr_count(strtolower($type_mobile), strtolower($is))) {
				return true;
			} else {
				return substr_count(strtolower(getUa()), strtolower($is)) ? true : false;
			}
		}

		if (get('mobile', 0) == 1)
			return true;

		if ($type_mobile != false)
			return true;

		if (isset($GLOBALS['mobile']))
			return $GLOBALS['mobile'];

		$md5 = md5(getUa());
		$url = 'http://phd.yandex.net/detect';
		$query = http_build_query(array(
						  'user-agent'  => getUa(),
						  'wap-profile' => "",
					  ));

		$info = sharding::globalMhs()->init('user_agent')->getOne($md5);
		if (!isset($info['response'])) {
			$response = Type_XML2Array::createArray(file_get_contents($url . '?' . $query));
			mCache::init()->set($md5, $info, DAY1);

			// сохраняем в базу
			if (is_array($response) && count($response)) {
				sharding::mysql()->insert('user_agent', [
					'md5'      => $md5,
					'ua'       => mysql_escape_string(strongescape(getUa())),
					'response' => mysql_escape_string(toJson($response)),
				]);
			}
		} else {
			$response = fromJson($info['response']);
		}

		//echo dd($info);

//		if (isset($response['yandex-mobile-info'])) {
//			$GLOBALS['mobile'] = true;
//			return true;
//		}

		$GLOBALS['mobile'] = false;

		return false;
	}

	// статистика ip адреса
	public static function getIpStat($ip) {
		$ip_int = ip2long($ip);

		$info	= sharding::mysql('main')->fetch_query("SELECT * FROM ip_stat WHERE ip = '{$ip_int}' LIMIT 1");

		$output	= [];
		$allow	= ['add_account','give_bonus_reg','give_exchange_protect'];
		foreach($allow as $key) {
			if (isset($info[$key])) {
				$output[$key]	= $info[$key];
			} else {
				$output[$key]	= 0;
			}
		}

		return $output;
	}

	// записать в статистику ip
	public static function incIpStat($ip,$set) {
		$insert	= $set;
		$ip_int = ip2long($ip);
		$insert['ip']	= $ip_int;
		$insert['expire'] = time() + DAY1*2;

		$set	= makeQueryInc($set);
		$query	= mysql::formatFromAr('ip_stat', [$insert]);
		$query .= "on duplicate key update
			{$set}
		";

		//echo dd($query);
		sharding::mysql('main')->query($query);
	}

}

// класс для управления банами
class System_ban {

	const PAGE_404 		= '404.html';
	const PAGE_BAN		= 'ban.html';
	const PAGE_CLICKER 	= 'clicker.html';

	// баним по ip
	public static function doBanIp($expire, $comment,$page = null) {
		// пишем в таблицу бана
		$ip = getIp();
		$ip_int = ip2long($ip);

		if ($page == null) {
			$page = self::PAGE_404;
		}

		// 
		if ($ip != '127.1.1.1' && $ip != '') {
			$insert = array(
				'ip'     => $ip_int,
				'expire' => $expire > time() ? $expire : time() + $expire,
				'page'	=> $page
			);
			sharding::mysql('main')->insert('ban_ip', $insert);
		}
		
		// пишем в логи
		$insert = array(
			'id'         => null,
			'ip_int'     => $ip_int,
			'user_id'    => Player::init()->user_id,
			'date_added' => time(),
			'ip'         => $ip,
			'comment'    => mysql_escape_string(htmlentities($comment)),
			'params'     => mysql_escape_string(toJson(array(
									   'cookie' => $_COOKIE,
									   'get'    => $_GET,
									   'post'   => $_POST,
									   'debug'  => getDebugPath()
								   )))
		);
		sharding::mysql('admin')->insert('ban_ip_log', $insert);
		
		//завершаем все сеансы
		Type_Player_Login::tryLogout();

		// организуем редирект
		redirect("/static/page/{$page}");

		//
		die('Event System_ban::doBanIp...');

	}
	
	// проверяем на блок по ip
	public static function checkBanIp() {
		$ip = getIp();
		$ip_int = ip2long($ip);
		
		// 
		if ($ip == '127.1.1.1' || $ip == '')
			return false;
		
		$info = sharding::globalMhs()->init('ban_ip')->getOne($ip_int);
		if (isset($info['ip'])) {
			// организуем редирект
			$page = $info['page'] == '' ? self::PAGE_404 : $info['page'];

			redirect("/static/page/{$page}");
			//redirect('/static/page/ban.html');
			die('Event System_ban::checkBanIp...');
			return true;
		}
		
		return false;
	}
}