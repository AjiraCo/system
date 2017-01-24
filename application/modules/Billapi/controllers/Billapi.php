<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billapi abstract controller for BillRun entities available actions
 *
 * @package  Billapi
 * @since    5.3
 */
abstract class BillapiController extends Yaf_Controller_Abstract {

	/**
	 * The output sent to the view
	 * @var stdClass
	 */
	protected $output;

	/**
	 * The requested collection name
	 * @var string
	 */
	protected $collection;

	/**
	 * The action to perform (create/update/delete/get)
	 * @var string
	 */
	protected $action;

	/**
	 * The base error number of this module
	 * @var int
	 */
	protected $errorBase;

	/**
	 * parameters to be used by the model
	 * 
	 * @var array
	 */
	protected $params = array();
	
	/**
	 * config settings of the API
	 * 
	 * @var array
	 */
	protected $settings = array();

	public function init() {
		$request = $this->getRequest();
		$this->collection = $request->getParam('collection');
		Billrun_Factory::config()->addConfig(APPLICATION_PATH . '/conf/modules/billapi/' . $this->collection . '.ini');
		$this->action = strtolower($request->getParam('action'));
		$this->errorBase = Billrun_Factory::config()->getConfigValue('billapi.error_base', 10400);
		$this->setActionConfig();
		
		if (!$this->checkPermissions()) {
			throw new Billrun_Exceptions_NoPermission();
		}
		
		$this->output = new stdClass();
		$this->getView()->output = $this->output;
		Yaf_Loader::getInstance(APPLICATION_PATH . '/application/modules/Billapi')->registerLocalNamespace("Models");
		$pluginStatus = true;
		Billrun_Factory::dispatcher()->trigger('beforeBillApi', array($this->collection, $this->action, &$request, &$pluginStatus));
		if ($pluginStatus !== true) {
			$errorCode = isset($pluginStatus['error']['code']) ? $pluginStatus['error']['code'] : $this->errorBase;
			$errorMsg = isset($pluginStatus['error']['message']) ? $pluginStatus['error']['message'] : 'Operation was cancelled due to 3rd party plugin';
			throw new Billrun_Exceptions_Api($errorCode, array(), $errorMsg);
		}
	}

	public function indexAction() {
		$request = $this->getRequest();

		// Moved to entity model
//		$query = json_decode($request->get('query'), TRUE);
//		$update = json_decode($request->get('update'), TRUE);
//		list($translatedQuery, $translatedUpdate) = $this->validateRequest($query, $update);
//		$this->params['query'] = $translatedQuery;
//		$this->params['update'] = $translatedUpdate;
		
		$this->params['request'] = array_merge($request->getParams(), $request->getRequest());
		$this->params['settings'] = $this->settings;
		$res = $this->runOperation();
		$this->output->status = 1;
		$this->output->details = $res;
		Billrun_Factory::dispatcher()->trigger('afterBillApi', array($this->collection, $this->action, $request, $this->output));
	}
	
	protected function runOperation() {
		$entityModel = $this->getModel();
		return $entityModel->{$this->action}();
	}

	/**
	 * Get the right model, depending on the requested collection
	 * @return \Models_Entity
	 */
	protected function getModel() {
		$modelPrefix = 'Models_';
		$className = $modelPrefix . ucfirst($this->collection);
		if (!@class_exists($className)) {
			$className = $modelPrefix . 'Entity';
		}
		$this->params['collection'] = $this->collection;
		return new $className($this->params);
	}

	/**
	 * Get the relevant billapi config depending on the requested collection + action
	 * @return array
	 */
	protected function setActionConfig() {
		$configVar = 'billapi.' . $this->collection . '.' . $this->action;
		$this->settings = Billrun_Factory::config()->getConfigValue($configVar, array());
	}

	/**
	 * Returns the translated (validated) request
	 * @param array $query the query parameter
	 * @param array $data the update parameter
	 * 
	 * @return array
	 * 
	 * @throws Billrun_Exceptions_Api
	 * @throws Billrun_Exceptions_InvalidFields
	 * @deprecated since version 5.3 moved to Entity model
	 */
	protected function validateRequest($query, $data) {
		$options = array();
		foreach (array('query_parameters' => $query, 'update_parameters' => $data) as $type => $params) {
			$options['fields'] = array();
			$translated[$type] = array();
			foreach (Billrun_Util::getFieldVal($this->settings[$type], array()) as $param) {
				$name = $param['name'];
				$isGenerated = (isset($param['generated']) && $param['generated']);
				if (!isset($params[$name])) {
					if (isset($param['mandatory']) && $param['mandatory'] && !$isGenerated) {
						throw new Billrun_Exceptions_Api($this->errorBase + 1, array(), 'Mandatory ' . str_replace('_parameters', '', $type) . ' parameter ' . $name . ' missing');
					}
					if (!$isGenerated) {
						continue;
					}
				}
				$options['fields'][] = array(
					'name' => $name,
					'type' => $param['type'],
					'preConversions' => isset($param['pre_conversion']) ? $param['pre_conversion'] : [],
					'postConversions' => isset($param['post_conversion']) ? $param['post_conversion'] : [],
					'options' => [],
				);
				$knownParams[$name] = $params[$name];
				unset($params[$name]);
			}
			if ($options['fields']) {
				$translatorModel = new Api_TranslatorModel($options);
				$ret = $translatorModel->translate($knownParams);
				$translated[$type] = $ret['data'];
//				Billrun_Factory::log("Translated result: " . print_r($ret, 1));
				if (!$ret['success']) {
					throw new Billrun_Exceptions_InvalidFields($translated[$type]);
				}
			}
			if (!Billrun_Util::getFieldVal($this->settings['restrict_query'], 1) && $params) {
				$translated[$type] = array_merge($translated[$type], $params);
			}
		}
		$this->verifyTranslated($translated);
		return array($translated['query_parameters'], $translated['update_parameters']);
	}
	
	/**
	 * authentication & authorization method to bill api
	 * 
	 * @param array $config api configuration
	 * 
	 * @return true if permission allowed, else false
	 */
	protected function checkPermissions() {
		if (!isset($this->settings['permission'])) {
			Billrun_Factory::log("No permissions settings for API call.", Zend_Log::ERR);
			return false;
		}
		
		$permission = $this->settings['permission'];
		$user = Billrun_Factory::user();
		if (!$user || !$user->valid()) {
			return false;
		}

		return $user->allowed($permission);
	}

	/**
	 * Verify the translated query & update
	 * @param array $translated
	 * @deprecated since version 5.3 moved to entity model
	 */
	protected function verifyTranslated($translated) {
		if (!$translated['query_parameters'] && !$translated['update_parameters']) {
			throw new Billrun_Exceptions_Api($this->errorBase + 2, array(), 'No query/update was found or entity not supported');
		}
	}

	protected function validateSort($sort) {
		if (!is_array($sort) || array_filter($sort, function ($one) {
				return $one != 1 && $one != -1;
			})) {
			throw new Billrun_Exceptions_Api($this->errorBase + 3, array(), 'Illegal sort parameter');
		}
	}

	/**
	 *
	 * @param string $tpl the default tpl the controller used; this will be override to use the general admin layout
	 * @param array $parameters parameters of the view
	 *
	 * @return string the render layout including the page (component)
	 */
	protected function render($tpl, array $parameters = array()) {
		return $this->getView()->render('index.phtml', $parameters);
	}

}
