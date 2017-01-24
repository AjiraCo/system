<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing factory class
 *
 * @package  Factory
 * @since    0.5
 */
class Billrun_Factory {

	/**
	 * Log instance
	 * 
	 * @var Billrun_Log
	 */
	protected static $log = null;

	/**
	 * Config instance
	 * 
	 * @var Yaf config
	 */
	protected static $config = null;

	/**
	 * Database instance
	 * 
	 * @var Mongoloid db
	 */
	protected static $db = array();

	/**
	 * Cache instance
	 * 
	 * @var Billrun_Billrun Cache
	 */
	protected static $cache = null;

	/**
	 * Chain instance
	 * 
	 * @var Billrun_Billrun Chain
	 */
	protected static $chain = null;

	/**
	 * Chain instance
	 * 
	 * @var Billrun_Billrun Chain
	 */
	protected static $importer = null;

	/**
	 * Subscriber instance
	 * 
	 * @var Billrun_Billrun Subscriber
	 */
	protected static $subscriber = null;
	
	/**
	 * Account instance
	 * 
	 * @var Billrun_Billrun Account
	 */
	protected static $account = null;
	
	/**
	 * Collection Steps instance
	 * 
	 * @var Billrun_Billrun Collection Steps
	 */
	protected static $collectionSteps = null;
	
	/**
	 * Collection Steps instance
	 * 
	 * @var Billrun_Billrun Collection Steps
	 */
	protected static $templateTokens = null;
	
	/**
	 * Balance instance
	 * 
	 * @var Billrun_Billrun Balance
	 */
	protected static $balance = null;

	/**
	 * Tariff instance
	 * 
	 * @var Billrun_Billrun Tariff
	 */
	protected static $tariff = null;

	/**
	 * Plan instance
	 * 
	 * @var Billrun_Billrun Plan
	 */
	protected static $plan = array();

	/**
	 * Smser instance
	 * 
	 * @var Billrun_Billrun Smser
	 */
	protected static $smser = null;

	/**
	 * Mailer instance
	 * 
	 * @var Billrun_Billrun Mail
	 */
	protected static $mailer = null;

	/**
	 * Users container
	 * 
	 * @var Mongodloid_Entity
	 */
	protected static $users = array();

	/**
	 * Authentication main dispatcher
	 * 
	 * @var Zend_Auth
	 */
	protected static $auth = null;

	/**
	 * method to retrieve the log instance
	 * 
	 * @param string [Optional] $message message to log
	 * @param int [Optional] $priority message to log
	 * 
	 * @return Billrun_Log
	 */
	static public function log() {
		if (!self::$log) {
			self::$log = Billrun_Log::getInstance();
		}

		$args = func_get_args();
		if (count($args) > 0) {
			$message = (string) $args[0];
			if (!isset($args[1])) {
				$priority = Zend_Log::DEBUG;
			} else {
				$priority = (int) $args[1];
			}
			self::$log->log($message, $priority);
		}

		return self::$log;
	}

	/**
	 * method to retrieve the config instance
	 * 
	 * @return Billrun_Config
	 */
	static public function config() {
		if (!self::$config) {
			self::$config = Billrun_Config::getInstance();
		}

		return self::$config;
	}

	/**
	 * method to retrieve the db instance
	 * 
	 * @return Billrun_Db
	 */
	static public function db(array $options = array()) {
		$stamp = md5(serialize($options)); // unique stamp per db connection
		if (!isset(self::$db[$stamp])) {
			if (empty($options)) { // get the db settings from config
				$options = Billrun_Factory::config()->getConfigValue('db');
			}
			self::$db[$stamp] = Billrun_Db::getInstance($options);
		}

		return self::$db[$stamp];
	}

	/**
	 * method to retrieve the cache instance
	 * 
	 * @return Billrun_Cache
	 */
	static public function cache() {
		try {
			if (!self::$cache) {
				$args = self::config()->getConfigValue('cache', array());
				if (isset($args[2]['cache_id_prefix'])) {
					$args[2]['cache_id_prefix'] .= '_' . Billrun_Factory::config()->getTenant() . '_';
				}
				if (empty($args)) {
					return false;
				}
				self::$cache = Billrun_Cache::getInstance($args);
			}

			return self::$cache;
		} catch (Exception $e) {
			Billrun_Factory::log('Cache instance cannot be generated', Zend_Log::ALERT);
		}
		return false;
	}

	/**
	 * method to retrieve the a mailer instance
	 * 
	 * @return Zend_Mail
	 */
	static public function mailer() {
		if (!isset(self::$mailer)) {
			try {
				self::$mailer = new Zend_Mail();
				$mailerTransport = Billrun_Factory::config()->getConfigValue('mailer.transport');
				if (!empty($mailerTransport)) {
					$className = 'Zend_Mail_Transport_' . ucfirst($mailerTransport['type']);
					$transport = new $className($mailerTransport['host'], $mailerTransport);
					Zend_Mail::setDefaultTransport($transport);
				}

				$fromAddress = Billrun_Factory::config()->getConfigValue('tenant.email', Billrun_Factory::config()->getConfigValue('mailer.from.address', 'no-reply@bill.run'));
				$fromName = Billrun_Factory::config()->getConfigValue('tenant.name', Billrun_Factory::config()->getConfigValue('mailer.from.name', 'BillRun'));
				self::$mailer->setFrom($fromAddress, $fromName);
				//$mail->setDefaultTransport($transport);
			} catch (Exception $e) {
				self::log("Can't instantiat mail object. Please check your settings", Zend_Log::ALERT);
				return false;
			}
		} else {
			self::$mailer->clearRecipients()->clearReplyTo()
				->clearSubject();
		}
		return self::$mailer;
	}

	/**
	 * method to retrieve the a smser instance
	 * 
	 * @return Billrun_Sms
	 * 
	 * @todo Refactoring Billrun_Sms object
	 */
	static public function smser($options = array()) {
		if (empty($options)) {
			$options = Billrun_Factory::config()->getConfigValue('smser', array());
		}
		$stamp = Billrun_Util::generateArrayStamp($options);
		if (!isset(self::$smser[$stamp])) {
			self::$smser[$stamp] = new Billrun_Sms($options);
		}

		return self::$smser[$stamp];
	}

	/**
	 * method to retrieve the dispatcher instance. Billrun_Dispatcher decides whether to create a new instance or not.
	 * 
	 * @return Billrun_Dispatcher
	 */
	static public function dispatcher() {
		return Billrun_Dispatcher::getInstance();
	}

	/**
	 * method to retrieve the chain instance
	 * 
	 * @return Billrun_Chain
	 */
	static public function chain() {
		if (!self::$chain) {
			self::$chain = Billrun_Dispatcher::getInstance(array('type' => 'chain'));
		}

		return self::$chain;
	}

	/**
	 * method to retrieve the subscriber instance
	 * 
	 * @return Billrun_Subscriber
	 */
	static public function subscriber() {
		if (!self::$subscriber) {
			$subscriberSettings = self::config()->getConfigValue('subscriber', array());
			self::$subscriber = Billrun_Subscriber::getInstance($subscriberSettings);
		}

		return self::$subscriber;
	}
	
	/**
	 * method to retrieve the account instance
	 * 
	 * @return Billrun_Subscriber
	 */
	static public function account() {
		if (!self::$account) {
			$settings = self::config()->getConfigValue('account', array());
			self::$account = Billrun_Account::getInstance($settings);
		}

		return self::$account;
	}
	
	/**
	 * method to retrieve the account instance
	 * 
	 * @return Billrun_Subscriber
	 */
	static public function collectionSteps() {
		if (!self::$collectionSteps) {
			$settings = self::config()->getConfigValue('collectionSteps', array());
			self::$collectionSteps = Billrun_CollectionSteps::getInstance($settings);
		}

		return self::$collectionSteps;
	}
	
	/**
	 * method to retrieve the Template Tokens instance
	 * 
	 * @return Billrun_Subscriber
	 */
	static public function templateTokens() {
		if (!self::$templateTokens) {
			self::$templateTokens = Billrun_Template_Token_Base::getInstance();
		}

		return self::$templateTokens;
	}
	
	/**
	 * method to retrieve a balance instance
	 * 
	 * @return Billrun_Balance
	 * @deprecated since version 4.0
	 */
	static public function balance($params = array()) {
		$balanceSettings = self::config()->getConfigValue('balance', array());
		return Billrun_Balance::getInstance(array_merge($balanceSettings, $params));
	}

	/**
	 * method to retrieve the tariff instance
	 * 
	 * @return Billrun_Subscriber
	 */
	static public function tariff() {
		if (!self::$tariff) {
			$tariffSettings = self::config()->getConfigValue('tariff', array());
			self::$tariff = Billrun_Tariff::getInstance($tariffSettings);
		}

		return self::$tariff;
	}

	/**
	 * method to retrieve the plan instance
	 * 
	 * @return Billrun_Plan
	 */
	static public function plan($params) {

		if (isset($params['disableCache']) && $params['disableCache']) {
			return new Billrun_Plan($params);
		}
		// unique stamp per plan
		$stamp = Billrun_Util::generateArrayStamp($params);

		if (!isset(self::$plan[$stamp])) {
			self::$plan[$stamp] = new Billrun_Plan($params);
		}
		return self::$plan[$stamp];
	}

	/**
	 * method to retrieve a billrun instance
	 * 
	 * @return Billrun_Billrun
	 */
	static public function billrun($params = array()) {
		$billrunSettings = self::config()->getConfigValue('billrun', array());
		return new Billrun_Billrun(array_merge($billrunSettings, $params));
	}

	/**
	 * Receive a billrun user
	 * @param string $username
	 * @return Billrun_User
	 */
	public static function user($username = null) {
		if (is_null($username)) {
			$username = Billrun_Factory::auth()->getIdentity();
		}

		if (empty($username)) {
			return FALSE;
		}

		$stamp = Billrun_Util::generateArrayStamp($username);
		if (!isset(self::$users[$stamp])) {
			$read = Billrun_Factory::auth()->getStorage()->read();
			if(!isset($read['current_user'])) {
				return FALSE;
			}
			$entity = new Mongodloid_Entity($read['current_user']);
			self::$users[$stamp] = new Billrun_User($entity);
		}
		return self::$users[$stamp];
	}

	protected static function setSessionTimeout($defaultTimeout) {
		$session_timeout = Billrun_Factory::config()->getConfigValue('admin.session.timeout', $defaultTimeout);
		ini_set('session.gc_maxlifetime', $session_timeout);
		session_set_cookie_params($session_timeout);
	}

	public static function auth() {
		if (!isset(self::$auth)) {
			Billrun_Util::setHttpSessionTimeout();
			self::$auth = Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Yaf());
		}
		return self::$auth;
	}

	/**
	 * factory for importer class
	 * 
	 * @param array $options options of the importer
	 * 
	 * @return mixed instance of importer if success, else false
	 */
	static public function importer(array $options = array()) {
		if (!isset($options)) {
			Billrun_Factory::log('Importer trying to initizilized without type', Zend_Log::ERR);
			return false;
		}
		$stamp = md5(serialize($options)); // unique stamp per db connection
		if (!isset(self::$importer[$stamp])) {
			$class_name = 'Billrun_Importer_' . $options['type'];
			self::$importer[$stamp] = new $class_name($options);
		}

		return self::$importer[$stamp];
	}

	/**
	 * method to retrieve a payment gateway by name
	 * 
	 * @return Billrun_PaymentGateway
	 */
	public static function paymentGateway($name) {
		try {
			$gateway = Billrun_PaymentGateway::getInstance($name);
		} catch (Exception $e) {
			Billrun_Factory::log($e->getMessage(), Zend_Log::ALERT);
			return FALSE;
		}
		return $gateway;
	}

}