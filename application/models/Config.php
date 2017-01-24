<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

require_once APPLICATION_PATH . '/library/vendor/autoload.php';

/**
 * Configmodel class
 *
 * @package  Models
 * @since    2.1
 */
class ConfigModel {

	/**
	 * the collection the config run on
	 * 
	 * @var Mongodloid Collection
	 */
	protected $collection;

	/**
	 * the config values
	 * @var array
	 */
	protected $data;
	
	/**
	 * options of config
	 * @var array
	 */
	protected $options;
	protected $fileClassesOrder = array('file_type', 'parser', 'processor', 'customer_identification_fields', 'rate_calculators', 'receiver');
	protected $ratingAlgorithms = array('match', 'longestPrefix');

	public function __construct() {
		// load the config data from db
		$this->collection = Billrun_Factory::db()->configCollection();
		$this->options = array('receive', 'process', 'calculate');
		$this->loadConfig();
	}

	public function getOptions() {
		return $this->options;
	}

	protected function loadConfig() {
		$ret = $this->collection->query()
			->cursor()
			->sort(array('_id' => -1))
			->limit(1)
			->current()
			->getRawData();
		$this->data = $ret;
	}

	public function getConfig() {
		return $this->data;
	}

	/**
	 * 
	 * @param int $data
	 * @return type
	 * @deprecated since version Now
	 * @todo Remove this function?
	 */
	public function setConfig($data) {
		$updatedData = array_merge($this->getConfig(), $data);
		unset($updatedData['_id']);
		foreach ($this->options as $option) {
			if (!isset($data[$option])) {
				$data[$option] = 0;
			}
		}
		return $this->collection->insert($updatedData);
	}

	public function getFromConfig($category, $data) {
		$currentConfig = $this->getConfig();

		// TODO: Create a config class to handle just file_types.
		if ($category == 'file_types') {
			if (!is_array($data)) {
				Billrun_Factory::log("Invalid data for file types.");
				return 0;
			}
			if (empty($data['file_type'])) {
				return $currentConfig['file_types'];
			}
			if ($fileSettings = $this->getFileTypeSettings($currentConfig, $data['file_type'])) {
				return $fileSettings;
			}
			throw new Exception('Unknown file type ' . $data['file_type']);
		} else if ($category == 'subscribers') {
			return $currentConfig['subscribers'];
		} else if ($category == 'payment_gateways') {
 			if (!is_array($data)) {
 				Billrun_Factory::log("Invalid data for payment_gateways.");
 				return 0;
 			}
 			if (empty($data['name'])) {
 				return $this->_getFromConfig($currentConfig, $category, $data);
 			}
 			if ($pgSettings = $this->getPaymentGatewaySettings($currentConfig, $data['name'])) {
 				return $pgSettings;
 			}
 			throw new Exception('Unknown payment gateway ' . $data['name']);
		} else if ($category == 'export_generators') {
			 if (!is_array($data)) {
 				Billrun_Factory::log("Invalid data for export_generators.");
 				return 0;
 			}
 			if (empty($data['name'])) {
 				return $currentConfig['export_generators'];
 			}
 			if ($exportGenSettings = $this->getExportGeneratorSettings($currentConfig, $data['name'])) {
 				return $exportGenSettings;
 			}
 			throw new Exception('Unknown export_generator ' . $data['name']);
		} else if ($category == 'template_token'){
			$tokens = Billrun_Factory::templateTokens()->getTokens();
			$tokens = array_merge_recursive($this->_getFromConfig($currentConfig, $category), $tokens);
			return $tokens;
		}
		
		return $this->_getFromConfig($currentConfig, $category, $data);
	}

	/**
	 * Internal getFromConfig function, recursively extracting values and handling
	 * any complex values.
	 * @param type $currentConfig
	 * @param type $category
	 * @param array $data
	 * @return mixed value
	 * @throws Exception
	 */
	protected function _getFromConfig($currentConfig, $category, $data) {
		if (is_array($data) && !empty($data)) {
			$dataKeys = array_keys($data);
			foreach ($dataKeys as $key) {
				$result[] = $this->_getFromConfig($currentConfig, $category . "." . $key, null);
			}
			return $result;
		}

		$valueInCategory = Billrun_Utils_Mongo::getValueByMongoIndex($currentConfig, $category);

		if (!empty($category) && $valueInCategory === null) {
			$result = $this->handleGetNewCategory($category, $data, $currentConfig);
			return $result;
		}
		
		$translated = Billrun_Config::translateComplex($valueInCategory);
		return $translated;
	}

	protected function extractComplexFromArray($array) {
		$returnData = array();
		// Check for complex objects.
		foreach ($array as $key => $value) {
			if (Billrun_Config::isComplex($value)) {
				// Get the complex object.
				$returnData[$key] = Billrun_Config::getComplexValue($value);
			} else {
				$returnData[$key] = $value;
			}
		}

		return $returnData;
	}

	/**
	 * Update a config category with data
	 * @param string $category
	 * @param mixed $data
	 * @return mixed
	 */
	public function updateConfig($category, $data) {
		$updatedData = $this->getConfig();
		unset($updatedData['_id']);

		if(empty($category)) {
			if (!$this->updateRoot($updatedData, $data)) {
				return 0;
			}
		}
		// TODO: Create a config class to handle just file_types.
		else if ($category === 'file_types') {
			if (empty($data['file_type'])) {
				throw new Exception('Couldn\'t find file type name');
			}
			$this->setFileTypeSettings($updatedData, $data);
			$fileSettings = $this->validateFileSettings($updatedData, $data['file_type'], FALSE);
		} else if ($category === 'payment_gateways') {
			if (!is_array($data)) {
				Billrun_Factory::log("Invalid data for payment gateways.");
				return 0;
			}
			if (empty($data['name'])) {
				throw new Exception('Couldn\'t find payment gateway name');
			}
			$paymentGateway = Billrun_Factory::paymentGateway($data['name']);
			if (!is_null($paymentGateway)){
				$supported = true;
			}
			else{
				$supported = false;
			}
			if (is_null($supported) || !$supported) {
				throw new Exception('Payment gateway is not supported');
			}
			$defaultParameters = $paymentGateway->getDefaultParameters();
			$releventParameters = array_intersect_key($defaultParameters, $data['params']); 
			$neededParameters = array_keys($releventParameters);
			foreach ($data['params'] as $key => $value) {
				if (!in_array($key, $neededParameters)){
					unset($data['params'][$key]);
				}
			}
			$rawPgSettings = $this->getPaymentGatewaySettings($updatedData, $data['name']);
			if ($rawPgSettings) {
				$pgSettings = array_merge($rawPgSettings, $data);
			} else {
				$pgSettings = $data;
			}
			$this->setPaymentGatewaySettings($updatedData, $pgSettings);
 			$pgSettings = $this->validatePaymentGatewaySettings($updatedData, $data, $paymentGateway);
 			if (!$pgSettings){
 				return 0;
 			}
		} else if ($category === 'export_generators') {
			if (!is_array($data)) {
				Billrun_Factory::log("Invalid data for export generator.");
				return 0;
			}
			if (empty($data['name'])) {
				throw new Exception('Couldn\'t find export generator name');
			}
			if (empty($data['file_type'])) {
				throw new Exception('Export generator must be associated to input processor');
			}
			if (empty($data['segments']) || !is_array($data['segments'])){
				throw new Exception('Segments must be an array and contain at least one value');
			}
			
			$rawExportGenSettings = $this->getExportGeneratorSettings($updatedData, $data['name']);
			if ($rawExportGenSettings) {
				$generatorSettings = array_merge($rawExportGenSettings, $data);
			} else {
				$generatorSettings = $data;
			}
			$this->setExportGeneratorSettings($updatedData, $generatorSettings);
			$generatorSettings = $this->validateExportGeneratorSettings($updatedData, $data);	
 			if (!$generatorSettings){
 				return 0;
 			}
		} else {
			if (!$this->_updateConfig($updatedData, $category, $data)) {
				return 0;
			}
		}

		$ret = $this->collection->insert($updatedData);
		$saveResult = !empty($ret['ok']);
		if ($saveResult) {
			// Reload timezone.
			Billrun_Config::getInstance()->refresh();
		}

		return $saveResult;
	}
	
	public function validateConfig($category, $data) {
		$updatedData = $this->getConfig();
		if ($category === 'file_types') {
			if (empty($data['file_type'])) {
				throw new Exception('Couldn\'t find file type name');
			}
			$this->setFileTypeSettings($updatedData, $data);
			return $this->validateFileSettings($updatedData, $data['file_type']);
		}
	}

	/**
	 * Load the config template.
	 * @return array The array representing the config template
	 */
	protected function loadTemplate() {
		// Load the config template.
		// TODO: Move the file path to a constant
		$templateFileName = APPLICATION_PATH . "/conf/config/template.json";
		$string = file_get_contents($templateFileName);
		$json_a = json_decode($string, true);
		return $json_a;
	}
	
	/**
	 * Update the config root category.
	 * @param array $currentConfig - The current configuration, passed by reference.
	 * @param array $data - The data to set. Treated as an hierchical JSON structure.
	 * (See _updateCofig).
	 * @return int
	 */
	protected function updateRoot(&$currentConfig, $data) {
		foreach ($data as $key => $value) {
			foreach ($value as $k => $v) {
				Billrun_Factory::log("Data: " . print_r($data,1));
				Billrun_Factory::log("Value: " . print_r($value,1));
				if (!$this->_updateConfig($currentConfig, $k, $v)) {
					return 0;
				}
			}
		}
		return 1;
	}
	
	/**
	 * Internal update process, used to update primitive and complex config values.
	 * @param array $currentConfig - The current configuratuin, passed by reference.
	 * @param string $category - Name of the category in the config.
	 * @param array $data - The data to set to the config. This array is treated
	 * as a complete JSON hierchical structure, and can update multiple values at
	 * once, as long as none of the values to update are arrays.
	 * @return int
	 * @throws Billrun_Exceptions_InvalidFields
	 */
	protected function _updateConfig(&$currentConfig, $category, $data) {
		$valueInCategory = Billrun_Utils_Mongo::getValueByMongoIndex($currentConfig, $category);

		if ($valueInCategory === null) {
			$result = $this->handleSetNewCategory($category, $data, $currentConfig);
			return $result;
		}

		// Check if complex object.
		if (Billrun_Config::isComplex($valueInCategory)) {
			// TODO: Do we allow setting?
			return $this->updateComplex($currentConfig, $category, $data, $valueInCategory);
		}
		
		// TODO: if it's possible to receive a non-associative array of associative arrays, we need to also check isMultidimentionalArray
		if (Billrun_Util::isAssoc($data)) {
			foreach ($data as $key => $value) {
				if (!$this->_updateConfig($currentConfig, $category . "." . $key, $value)) {
					return 0;
				}
			}
			return 1;
		}
		
		return Billrun_Utils_Mongo::setValueByMongoIndex($data, $currentConfig, $category);
	}
	
	protected function updateComplex(&$currentConfig, $category, $data, $valueInCategory) {
		// Set the value for the complex object,
		$valueInCategory['v'] = $data;

		// Validate the complex object.
		if (!Billrun_Config::isComplexValid($valueInCategory)) {
			Billrun_Factory::log("Invalid complex object " . print_r($valueInCategory, 1), Zend_Log::NOTICE);
			$invalidFields[] = Billrun_Utils_Mongo::mongoArrayToInvalidFieldsArray($category, ".");
			throw new Billrun_Exceptions_InvalidFields($invalidFields);
		}

		// Update the config.
		if (!Billrun_Utils_Mongo::setValueByMongoIndex($valueInCategory, $currentConfig, $category)) {
			return 0;
		}

		return 1;
	}
	
	/**
	 * Handle the scenario of a category that doesn't exist in the database
	 * @param string $category - The current category.
	 * @param array $data - Data to set.
	 * @param array $currenConfig - Current configuration data.
	 */
	protected function handleNewCategory($category, $data, &$currentConfig) {
		$splitCategory = explode('.', $category);

		$template = $this->loadTemplate();
		Billrun_Factory::log("Tempalte: " . print_r($template,1), Zend_Log::DEBUG);
		$found = true;
		$ptrTemplate = &$template;
		$newConfig = $currentConfig;
		$newValueIndex = &$newConfig;
		
		// Go through the keys
		foreach ($splitCategory as $key) {
			// If the value doesn't exist check if it has a default value in the template ini
			if(!isset($newValueIndex[$key])) {
				$overrideValue = Billrun_Util::getFieldVal($ptrTemplate[$key], array());
				$newValueIndex[$key] = $overrideValue;
			}
			$newValueIndex = &$newValueIndex[$key];
			if(!isset($ptrTemplate[$key])) {
				$found = false;
				break;
			}
			$ptrTemplate = &$ptrTemplate[$key];
		}
		
		// Check if the value exists in the settings template ini.
		if(!$found) {
			Billrun_Factory::log("Unknown category", Zend_Log::NOTICE);
			return 0;
		}
		
		return $newConfig;
	}
	
	/**
	 * Handle the scenario of a category that doesn't exist in the database
	 * @param string $category - The current category.
	 * @param array $data - Data to set.
	 * @param array $currenConfig - Current configuration data.
	 */
	protected function handleGetNewCategory($category, $data, &$currentConfig) {
		// Set the data
		$newConfig = $this->handleNewCategory($category, $data, $currentConfig);
		if(!$newConfig) {
			throw new Exception("Category not found " . $category);
		}
		$currentConfig = $newConfig;
		
		$result = Billrun_Utils_Mongo::getValueByMongoIndex($currentConfig, $category);
		if(Billrun_Config::isComplex($result)) {
			return Billrun_Config::getComplexValue($result);
		}
		return $result;
	}
	
	protected function handleSetNewCategory($category, $data, &$currentConfig) {
		// Set the data
		$newConfig = $this->handleNewCategory($category, $data, $currentConfig);
		if(!$newConfig) {
			throw new Exception("Category not found " . $category);
		}
		$currentConfig = $newConfig;
		$value = Billrun_Utils_Mongo::getValueByMongoIndex($currentConfig, $category);
		if(Billrun_Config::isComplex($value)) {
			return $this->updateComplex($currentConfig, $category, $data, $value);
		}
		
		$result = Billrun_Utils_Mongo::setValueByMongoIndex($data, $currentConfig, $category);
		return $result;
	}
	
	protected function setConfigValue(&$config, $category, $toSet) {
		// Check if complex object.
		if (Billrun_Config::isComplex($toSet)) {
			return $this->setComplexValue($toSet);
		}

		if (is_array($toSet)) {
			return $this->setConfigArrayValue($toSet);
		}

		return Billrun_Utils_Mongo::setValueByMongoIndex($toSet, $config, $category);
	}

	protected function setConfigArrayValue($toSet) {
		
	}

	protected function setComplexValue($toSet) {
		// Check if complex object.
		if (!Billrun_Config::isComplex($valueInCategory)) {
			// TODO: Do we allow setting?
			Billrun_Factory::log("Encountered a problem", Zend_Log::NOTICE);
			return 0;
		}
		// Set the value for the complex object,
		$valueInCategory['v'] = $data;

		// Validate the complex object.
		if (!Billrun_Config::isComplexValid($valueInCategory)) {
			Billrun_Factory::log("Invalid complex object " . print_r($valueInCategory, 1), Zend_Log::NOTICE);
			$invalidFields = Billrun_Utils_Mongo::mongoArrayToInvalidFieldsArray($category, ".", false);
			throw new Billrun_Exceptions_InvalidFields($invalidFields);
		}

		// Update the config.
		if (!Billrun_Utils_Mongo::setValueByMongoIndex($valueInCategory, $currentConfig, $category)) {
			return 0;
		}

		if (Billrun_Config::isComplex($toSet)) {
			// Get the complex object.
			return Billrun_Config::getComplexValue($toSet);
		}

		if (is_array($toSet)) {
			return $this->extractComplexFromArray($toSet);
		}

		return $toSet;
	}

	public function unsetFromConfig($category, $data) {
		$updatedData = $this->getConfig();
		unset($updatedData['_id']);
		if ($category === 'file_types') {
			if (isset($data['file_type'])) {
				$this->unsetFileTypeSettings($updatedData, $data['file_type']);
			}
		}
		if ($category === 'export_generators') {
			if (isset($data['name'])) {
				$this->unsetExportGeneratorSettings($updatedData, $data['name']);
			}
		}
		if ($category === 'payment_gateways') {
 			if (isset($data['name'])) {
 				if (count($data) == 1) {
 					$this->unsetPaymentGatewaySettings($updatedData, $data['name']);
 				} else {
 					if (!$pgSettings = $this->getPaymentGatewaySettings($updatedData, $data['name'])) {
 						throw new Exception('Unkown payment gateway ' . $data['name']);
 					}
 					foreach (array_keys($data) as $key) {
 						if ($key != 'name') {
 							unset($pgSettings[$key]);
 						}
 					}
 					$this->setPaymentGatewaySettings($updatedData, $pgSettings);
 				}
 			}
 		}
 
		$ret = $this->collection->insert($updatedData);
		return !empty($ret['ok']);
	}

	protected function getFileTypeSettings($config, $fileType) {
		if ($filtered = array_filter($config['file_types'], function($fileSettings) use ($fileType) {
			return $fileSettings['file_type'] === $fileType;
		})) {
			return current($filtered);
		}
		return FALSE;
	}
	
	protected function getPaymentGatewaySettings($config, $pg) {
 		if ($filtered = array_filter($config['payment_gateways'], function($pgSettings) use ($pg) {
 			return $pgSettings['name'] === $pg;
 		})) {
 			return current($filtered);
 		}
 		return FALSE;
 	}
	
	protected function getExportGeneratorSettings($config, $name) {
 		if ($filtered = array_filter($config['export_generators'], function($exportGenSettings) use ($name) {
 			return $exportGenSettings['name'] === $name;
 		})) {
 			return current($filtered);
 		}
 		return FALSE;
 	}
 
	protected function setFileTypeSettings(&$config, $fileSettings) {
		$fileType = $fileSettings['file_type'];
		foreach ($config['file_types'] as &$someFileSettings) {
			if ($someFileSettings['file_type'] == $fileType) {
				$someFileSettings = $fileSettings;
				return;
			}
		}
		$config['file_types'] = array_merge($config['file_types'], array($fileSettings));
	}
	
	
	protected function setPaymentGatewaySettings(&$config, $pgSettings) {
 		$paymentGateway = $pgSettings['name'];
 		foreach ($config['payment_gateways'] as &$somePgSettings) {
 			if ($somePgSettings['name'] == $paymentGateway) {
 				$somePgSettings = $pgSettings;
 				return;
 			}
 		}
 		$config['payment_gateways'] = array_merge($config['payment_gateways'], array($pgSettings));
 	}
	
	
	protected function setExportGeneratorSettings(&$config, $egSettings) {
 		$exportGenerator = $egSettings['name'];
 		foreach ($config['export_generators'] as &$someEgSettings) {
 			if ($someEgSettings['name'] == $exportGenerator) {
 				$someEgSettings = $egSettings;
 				return;
 			}
 		}
        if (!$config['export_generators']) {
            $config['export_generators'] = array($egSettings);
        } else {
            $config['export_generators'] = array_merge($config['export_generators'], array($egSettings));
        }
 	}
 

	protected function unsetFileTypeSettings(&$config, $fileType) {
		$config['file_types'] = array_filter($config['file_types'], function($fileSettings) use ($fileType) {
			return $fileSettings['file_type'] !== $fileType;
		});
	}
	
	
	protected function unsetPaymentGatewaySettings(&$config, $pg) {
 		$config['payment_gateways'] = array_filter($config['payment_gateways'], function($pgSettings) use ($pg) {
 			return $pgSettings['name'] !== $pg;
 		});
 	}
	
	protected function unsetExportGeneratorSettings(&$config, $name) {
		$config['export_generators'] = array_map(function($ele) use($name){
			if ($ele['name'] == $name){
				$ele['enabled'] = false;
			}
			return $ele;
		}, $config['export_generators']);	
	}
 
	protected function validateFileSettings(&$config, $fileType, $allowPartial = TRUE) {
		$completeFileSettings = FALSE;
		$fileSettings = $this->getFileTypeSettings($config, $fileType);
		if (!$this->isLegalFileSettingsKeys(array_keys($fileSettings))) {
			throw new Exception('Incorrect file settings keys.');
		}
		$updatedFileSettings = array();
		$updatedFileSettings['file_type'] = $fileSettings['file_type'];
		if (isset($fileSettings['type']) && $this->validateType($fileSettings['type'])) {
			$updatedFileSettings['type'] = $fileSettings['type'];
		}
		if (isset($fileSettings['parser'])) {
			$updatedFileSettings['parser'] = $this->validateParserConfiguration($fileSettings['parser']);
			if (isset($fileSettings['processor'])) {
				$updatedFileSettings['processor'] = $this->validateProcessorConfiguration($fileSettings['processor']);
				if (isset($fileSettings['customer_identification_fields'])) {
					$updatedFileSettings['customer_identification_fields'] = $this->validateCustomerIdentificationConfiguration($fileSettings['customer_identification_fields']);
					if (isset($fileSettings['rate_calculators'])) {
						$updatedFileSettings['rate_calculators'] = $this->validateRateCalculatorsConfiguration($fileSettings['rate_calculators']);
						if (isset($fileSettings['receiver'])) {
							$updatedFileSettings['receiver'] = $this->validateReceiverConfiguration($fileSettings['receiver']);
							$completeFileSettings = TRUE;
						} else if (isset($fileSettings['realtime'], $fileSettings['response'])) {
							$updatedFileSettings['realtime'] = $this->validateRealtimeConfiguration($fileSettings['realtime']);
							$updatedFileSettings['response'] = $this->validateResponseConfiguration($fileSettings['response']);
							$completeFileSettings = TRUE;
						}
					}
				}
			}
		}
		if (!$allowPartial && !$completeFileSettings) {
			throw new Exception('File settings is not complete.');
		}
		$this->setFileTypeSettings($config, $updatedFileSettings);
		return $this->checkForConflics($config, $fileType);
	}

	protected function validateType($type) {
		$allowedTypes = array('realtime');
		return in_array($type, $allowedTypes);
	}
	
	protected function validatePaymentGatewaySettings(&$config, $pg, $paymentGateway) {
 		$connectionParameters = array_keys($pg['params']);
 		$name = $pg['name'];	
		$defaultParameters = $paymentGateway->getDefaultParameters();
		$defaultParametersKeys = array_keys($defaultParameters);
		$diff = array_diff($defaultParametersKeys, $connectionParameters);
		if (!empty($diff)) {
			Billrun_Factory::log("Wrong parameters for connection to ", $name);
			return false;
		}
		$isAuth = $paymentGateway->authenticateCredentials($pg['params']);
		if (!$isAuth){
			throw new Exception('Wrong credentials for connection to ', $name); 
		}	
		
 		return true;
 	}
 
	protected function validateExportGeneratorSettings(&$config, $eg) {
		$fileTypeSettings = $this->getFileTypeSettings($config, $eg['file_type']);
		if (empty($fileTypeSettings)){
			Billrun_Factory::log("There's no matching file type "  . $eg['file_type']);
			return false;
		}
		$parserSettings = $fileTypeSettings['parser'];
		$inputProcessorFields = $parserSettings['structure'];
		foreach ($eg['segments'] as $segment){
			if (!in_array($segment['field'], $inputProcessorFields)){
				Billrun_Factory::log("There's no matching field in the name of "  . $segment['field'] . "in input processor: ", $eg['file_type']);
				return false;
			}
		}
		
		return true;
 	}

	protected function checkForConflics($config, $fileType) {
		$fileSettings = $this->getFileTypeSettings($config, $fileType);
		if (isset($fileSettings['processor'])) {
			$customFields = $fileSettings['parser']['custom_keys'];
			$uniqueFields[] = $dateField = $fileSettings['processor']['date_field'];
			$uniqueFields[] = $volumeField = $fileSettings['processor']['volume_field'];
			$useFromStructure = $uniqueFields;
			$usagetMappingSource = array_map(function($mapping) {
				return $mapping['src_field'];
			}, array_filter($fileSettings['processor']['usaget_mapping'], function($mapping) {
					return isset($mapping['src_field']);
				}));
			if (array_diff($usagetMappingSource, $customFields)) {
				throw new Exception('Unknown fields used for usage type mapping: ' . implode(', ', $usagetMappingSource));
			}
			$usagetTypes = array_map(function($mapping) {
				return $mapping['usaget'];
			}, $fileSettings['processor']['usaget_mapping']);
			if (isset($fileSettings['processor']['default_usaget'])) {
				$usagetTypes[] = $fileSettings['processor']['default_usaget'];
				$usagetTypes = array_unique($usagetTypes);
			}
			if (isset($fileSettings['customer_identification_fields'])) {
				$customerMappingSource = array_map(function($mapping) {
					return $mapping['src_key'];
				}, $fileSettings['customer_identification_fields']);
				$useFromStructure = $uniqueFields = array_merge($uniqueFields, array_unique($customerMappingSource));
				$customerMappingTarget = array_map(function($mapping) {
					return $mapping['target_key'];
				}, $fileSettings['customer_identification_fields']);
				$subscriberFields = array_map(function($field) {
					return $field['field_name'];
				}, array_filter($config['subscribers']['subscriber']['fields'], function($field) {
						return !empty($field['unique']);
					}));
				if ($subscriberDiff = array_unique(array_diff($customerMappingTarget, $subscriberFields))) {
					throw new Exception('Unknown subscriber fields ' . implode(',', $subscriberDiff));
				}
				if (isset($fileSettings['rate_calculators'])) {
					$ratingUsageTypes = array_keys($fileSettings['rate_calculators']);
					foreach ($fileSettings['rate_calculators'] as $usageRules) {
						foreach ($usageRules as $rule) {
							$ratingLineKeys[] = $rule['line_key'];
						}
					}
					$useFromStructure = array_merge($useFromStructure, $ratingLineKeys);
					if ($unknownUsageTypes = array_diff($ratingUsageTypes, $usagetTypes)) {
						throw new Exception('Unknown usage type(s) in rating: ' . implode(',', $unknownUsageTypes));
					}
					if ($usageTypesMissingRating = array_diff($usagetTypes, $ratingUsageTypes)) {
						throw new Exception('Missing rating rules for usage types(s): ' . implode(',', $usageTypesMissingRating));
					}
				}
			}
			if ($uniqueFields != array_unique($uniqueFields)) {
				throw new Exception('Cannot use same field for different configurations');
			}
			$billrunFields = array('type', 'usaget');
			$customFields = array_merge($customFields, array_map(function($field) {
				return 'uf.' . $field;
			}, $customFields));
			if ($diff = array_diff($useFromStructure, array_merge($customFields, $billrunFields))) {
				throw new Exception('Unknown source field(s) ' . implode(',', $diff));
			}
		}
		return true;
	}

	protected function validateParserConfiguration($parserSettings) {
		if (empty($parserSettings['type'])) {
			throw new Exception('No parser type selected');
		}
		$allowedParsers = array('separator', 'fixed', 'json');
		if (!in_array($parserSettings['type'], $allowedParsers)) {
			throw new Exception('Parser must be one of: ' . implode(',', $allowedParsers));
		}
		if (empty($parserSettings['structure']) || !is_array($parserSettings['structure'])) {
			throw new Exception('No file structure supplied');
		}
		if ($parserSettings['type'] == 'json') {
			$customKeys = $parserSettings['structure'];
		} else if ($parserSettings['type'] == 'separator') {
			$customKeys = $parserSettings['structure'];
			if (empty($parserSettings['separator'])) {
				throw new Exception('Missing CSV separator');
			}
			if (!(is_scalar($parserSettings['separator']) && !is_bool($parserSettings['separator']))) {
				throw new Exception('Illegal seprator ' . $parserSettings['separator']);
			}
		} else {
			$customKeys = array_keys($parserSettings['structure']);
			$customLengths = array_values($parserSettings['structure']);
			if ($customLengths != array_filter($customLengths, function($length) {
					return Billrun_Util::IsIntegerValue($length);
				})) {
				throw new Exception('Duplicate field names found');
			}
		}
		$parserSettings['custom_keys'] = $customKeys;
		if ($customKeys != array_unique($customKeys)) {
			throw new Exception('Duplicate field names found');
		}
		if ($customKeys != array_filter($customKeys, array('Billrun_Util', 'isValidCustomLineKey'))) {
			throw new Exception('Illegal field names');
		}
		foreach (array('H', 'D', 'T') as $rowKey) {
			if (empty($parserSettings['line_types'][$rowKey])) {
				$parserSettings['line_types'][$rowKey] = $rowKey == 'D' ? '//' : '/^none$/';
			} else if (!Billrun_Util::isValidRegex($parserSettings['line_types'][$rowKey])) {
				throw new Exception('Invalid regex ' . $parserSettings['line_types'][$rowKey]);
			}
		}
		return $parserSettings;
	}

	protected function validateProcessorConfiguration($processorSettings) {
		if (empty($processorSettings['type'])) {
			$processorSettings['type'] = 'Usage';
		}
		if (!in_array($processorSettings['type'], array('Usage', 'Realtime'))) {
			throw new Exception('Invalid processor type');
		}
		if (isset($processorSettings['date_format'])) {
			if (isset($processorSettings['time_field']) && !isset($processorSettings['time_format'])) {
				throw new Exception('Missing processor time format (in case date format is set, and timedate are in separated fields)');
			}
			// TODO validate date format
		}
		if (!isset($processorSettings['date_field'])) {
			throw new Exception('Missing processor date field');
		}
		if (!isset($processorSettings['volume_field'])) {
			throw new Exception('Missing processor volume field');
		}
		if (!(isset($processorSettings['usaget_mapping']) || isset($processorSettings['default_usaget']))) {
			throw new Exception('Missing processor usage type mapping rules');
		}
		if (isset($processorSettings['usaget_mapping'])) {
			if (!$processorSettings['usaget_mapping'] || !is_array($processorSettings['usaget_mapping'])) {
				throw new Exception('Missing mandatory processor configuration');
			}
			$processorSettings['usaget_mapping'] = array_values($processorSettings['usaget_mapping']);
			foreach ($processorSettings['usaget_mapping'] as $index => $mapping) {
				if (isset($mapping['src_field']) && !(isset($mapping['pattern']) && Billrun_Util::isValidRegex($mapping['pattern'])) || empty($mapping['usaget'])) {
					throw new Exception('Illegal usaget mapping at index ' . $index);
				}
			}
		}
		if (!isset($processorSettings['orphan_files_time'])) {
			$processorSettings['orphan_files_time'] = '6 hours';
		}
		return $processorSettings;
	}

	protected function validateCustomerIdentificationConfiguration($customerIdentificationSettings) {
		if (!is_array($customerIdentificationSettings) || !$customerIdentificationSettings) {
			throw new Exception('Illegal customer identification settings');
		}
		$customerIdentificationSettings = array_values($customerIdentificationSettings);
		foreach ($customerIdentificationSettings as $index => $settings) {
			if (!isset($settings['src_key'], $settings['target_key'])) {
				throw new Exception('Illegal customer identification settings at index ' . $index);
			}
			if (array_key_exists('conditions', $settings) && (!is_array($settings['conditions']) || !$settings['conditions'] || !($settings['conditions'] == array_filter($settings['conditions'], function ($condition) {
					return isset($condition['field'], $condition['regex']) && Billrun_Util::isValidRegex($condition['regex']);
				})))) {
				throw new Exception('Illegal customer identification conditions field at index ' . $index);
			}
			if (isset($settings['clear_regex']) && !Billrun_Util::isValidRegex($settings['clear_regex'])) {
				throw new Exception('Invalid customer identification clear regex at index ' . $index);
			}
		}
		return $customerIdentificationSettings;
	}

	protected function validateRateCalculatorsConfiguration($rateCalculatorsSettings) {
		if (!is_array($rateCalculatorsSettings)) {
			throw new Exception('Rate calculators settings is not an array');
		}
		foreach ($rateCalculatorsSettings as $usaget => $rateRules) {
			foreach ($rateRules as $rule) {
				if (!isset($rule['type'], $rule['rate_key'], $rule['line_key'])) {
					throw new Exception('Illegal rating rules for usaget ' . $usaget);
				}
				if (!in_array($rule['type'], $this->ratingAlgorithms)) {
					throw new Exception('Illegal rating algorithm for usaget ' . $usaget);
				}
			}
		}
		return $rateCalculatorsSettings;
	}

	protected function validateReceiverConfiguration($receiverSettings) {
		if (!is_array($receiverSettings)) {
			throw new Exception('Receiver settings is not an array');
		}
		if (!array_key_exists('connections', $receiverSettings) || !is_array($receiverSettings['connections']) || !$receiverSettings['connections']) {
			throw new Exception('Receiver \'connections\' does not exist or is empty');
		}
		$receiverSettings['type'] = 'ftp';
		if (isset($receiverSettings['limit'])) {
			if (!Billrun_Util::IsIntegerValue($receiverSettings['limit']) || $receiverSettings['limit'] < 1) {
				throw new Exception('Illegal receiver limit value ' . $receiverSettings['limit']);
			}
			$receiverSettings['limit'] = intval($receiverSettings['limit']);
		} else {
			$receiverSettings['limit'] = 3;
		}
		if ($receiverSettings['type'] == 'ftp') {
			foreach ($receiverSettings['connections'] as $index => $connection) {
				if (!isset($connection['name'], $connection['host'], $connection['user'], $connection['password'], $connection['remote_directory'], $connection['passive'], $connection['delete_received'])) {
					throw new Exception('Missing receiver\'s connection field at index ' . $index);
				}
				if (filter_var($connection['host'], FILTER_VALIDATE_IP) === FALSE) {
					throw new Exception($connection['host'] . ' is not a valid IP address');
				}
				$connection['passive'] = $connection['passive'] ? 1 : 0;
				$connection['delete_received'] = $connection['delete_received'] ? 1 : 0;
			}
		}
		return $receiverSettings;
	}

	protected function isLegalFileSettingsKeys($keys) {
		$hole = FALSE;
		foreach ($this->fileClassesOrder as $class) {
			if (!in_array($class, $keys)) {
				$hole = TRUE;
			} else if ($hole) {
				return FALSE;
			}
		}
		return TRUE;
	}
	
	protected function validateRealtimeConfiguration($realtimeSettings) {
		if (!is_array($realtimeSettings)) {
			throw new Exception('Realtime settings is not an array');
		}
		
		if (isset($realtimeSettings['postpay_charge']) && $realtimeSettings['postpay_charge']) {
			return $realtimeSettings;
		}
		
		$mandatoryFields = Billrun_Factory::config()->getConfigValue('configuration.realtime.mandatory_fields', array());
		$missingFields = array();
		foreach ($mandatoryFields as $mandatoryField) {
			if (!isset($realtimeSettings[$mandatoryField])) {
				$missingFields[] = $mandatoryField;
			}
		}
		if (!empty($missingFields)) {
			throw new Exception('Realtime settings missing mandatory fields: ' . implode(', ', $missingFields));
		}
		
		return $realtimeSettings;
	}
	
	protected function validateResponseConfiguration($responseSettings) {
		if (!is_array($responseSettings)) {
			throw new Exception('Response settings is not an array');
		}
		
		if (!isset($responseSettings['encode']) || !in_array($responseSettings['encode'], array('json'))) {
			throw new Exception('Invalid response encode type');
		}

		if (!isset($responseSettings['fields'])) {
			throw new Exception('Missing response fields');
		}
		
		foreach ($responseSettings['fields'] as $responseField) {
			if (empty($responseField['response_field_name']) || empty($responseField['row_field_name'])) {
				throw new Exception('Invalid response fields structure');
			}
		}
		
		return $responseSettings;
	}

	public function save($items) {
		$data = $this->getConfig();
		$saveData = array_merge($data, $items);
		$this->setConfig($saveData);
	}

}
