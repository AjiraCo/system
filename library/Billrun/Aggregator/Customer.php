<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing aggregator class for Golan customers records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Aggregator_Customer extends Billrun_Aggregator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'customer';

	/**
	 * 
	 * @var int
	 */
	protected $page = 1;

	/**
	 * 
	 * @var int
	 */
	protected $size = 200;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $plans = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $lines = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $billrun = null;

	/**
	 *
	 * @var int invoice id to start from
	 */
	protected $min_invoice_id = 101;

	/**
	 *
	 * @var boolean is customer price vatable by default
	 */
	protected $vatable = true;
	protected $rates;
	protected $testAcc = false;

	/**
	 * @var boolean when creating billrun documents, write lines stamps to file rather than updating the lines with billrun stamps
	 */
	protected $write_stamps_to_file = false;

	/**
	 * Memory limit in bytes, after which the aggregation is stopped. Set to -1 for no limit.
	 * @var int 
	 */
	protected $memory_limit = -1;

	/**
	 * @var boolean if $write_stamps_to_file is true, will be set to the stamps files directory
	 */
	protected $stamps_dir = null;

	public function __construct($options = array()) {
		parent::__construct($options);

		ini_set('mongo.native_long', 1); //Set mongo  to use  long int  for  all aggregated integer data.

		if (isset($options['aggregator']['page']) && is_numeric($options['aggregator']['page'])) {
			$this->page = $options['aggregator']['page'];
		}
		if (isset($options['page']) && is_numeric($options['page'])) {
			$this->page = $options['page'];
		}
		if (isset($options['aggregator']['size']) && $options['aggregator']['size']) {
			$this->size = $options['aggregator']['size'];
		}
		if (isset($options['size']) && $options['size']) {
			$this->size = $options['size'];
		}
		if (isset($options['aggregator']['vatable'])) {
			$this->vatable = $options['aggregator']['vatable'];
		}

		if (isset($options['aggregator']['test_accounts'])) {
			$this->testAcc = $options['aggregator']['test_accounts'];
		}
		if (isset($options['aggregator']['min_invoice_id'])) {
			$this->min_invoice_id = (int) $options['aggregator']['min_invoice_id'];
		}

		if (isset($options['aggregator']['write_stamps_to_file']) && $options['aggregator']['write_stamps_to_file']) {
			$this->write_stamps_to_file = $options['aggregator']['write_stamps_to_file'];
			$this->stamps_dir = (isset($options['aggregator']['stamps_dir']) ? $options['aggregator']['stamps_dir'] : getcwd() . '/files/billrun_stamps') . '/' . $this->getStamp() . '/';
		}
		if (isset($options['aggregator']['memory_limit_in_mb'])) {
			if ($options['aggregator']['memory_limit_in_mb'] > -1) {
				$this->memory_limit = $options['aggregator']['memory_limit_in_mb'] * 1048576;
			} else {
				$this->memory_limit = $options['aggregator']['memory_limit_in_mb'];
			}
		}

		$this->plans = Billrun_Factory::db()->plansCollection();
		$this->lines = Billrun_Factory::db()->linesCollection();
		$this->billrun = Billrun_Factory::db()->billrunCollection();

		$this->loadRates();
	}

	/**
	 * load the data to aggregate
	 */
	public function load() {
		$billrun_key = $this->getStamp();
		$date = date(Billrun_Base::base_dateformat, Billrun_Util::getEndTime($billrun_key));
		$subscriber = Billrun_Factory::subscriber();
		Billrun_Factory::log()->log("Loading page " . $this->page . " of size " . $this->size, Zend_Log::INFO);
		$this->data = $subscriber->getList($this->page, $this->size, $date);

		Billrun_Factory::log()->log("aggregator entities loaded: " . count($this->data), Zend_Log::INFO);

		Billrun_Factory::dispatcher()->trigger('afterAggregatorLoadData', array('aggregator' => $this));
	}

	/**
	 * execute aggregate
	 */
	public function aggregate() {
		if ($this->write_stamps_to_file) {
			if (!$this->initStampsDir()) {
				Billrun_Factory::log()->log("Could not create stamps file for page " . $this->page, Zend_Log::ALERT);
				return false;
			}
		}
		// @TODO trigger before aggregate
		Billrun_Factory::dispatcher()->trigger('beforeAggregate', array($this->data, &$this));
		$account_billrun = false;
		$billrun_key = $this->getStamp();
		$billruns_count = 0;
		foreach ($this->data as $accid => $account) {
			if ($this->memory_limit > -1 && memory_get_usage() > $this->memory_limit) {
				Billrun_Factory::log('Customer aggregator memory limit of ' . $this->memory_limit / 1048576 . 'M has reached. Exiting (page: ' . $this->page . ', size: ' . $this->size . ').', Zend_log::ALERT);
				break;
			}
			Billrun_Factory::dispatcher()->trigger('beforeAggregateAccount', array($accid, $account, &$this));
			Billrun_Factory::log('Current account index: ' . ++$billruns_count, Zend_log::INFO);
			if (!Billrun_Factory::config()->isProd()) {
				if ($this->testAcc && is_array($this->testAcc) && !in_array($accid, $this->testAcc)) {
					//Billrun_Factory::log("Moving on nothing to see here... , account Id : $accid");
					continue;
				}
			}

			if (Billrun_Billrun::exists($accid, $billrun_key)) {
				Billrun_Factory::log()->log("Billrun " . $billrun_key . " already exists for account " . $accid, Zend_Log::ALERT);
				continue;
			}
			$params = array(
				'aid' => $accid,
				'billrun_key' => $billrun_key,
				'autoload' => false,
			);
			$account_billrun = Billrun_Factory::billrun($params);
			$flat_lines = array();
			foreach ($account as $subscriber) {
				Billrun_Factory::dispatcher()->trigger('beforeAggregateSubscriber', array($subscriber, $account_billrun, &$this));
				$sid = $subscriber->sid;
				if ($account_billrun->subscriberExists($sid)) {
					Billrun_Factory::log()->log("Billrun " . $billrun_key . " already exists for subscriber " . $sid, Zend_Log::ALERT);
					continue;
				}
				$next_plan_name = $subscriber->getNextPlanName();
				if (is_null($next_plan_name) || $next_plan_name == "NULL") {
					$subscriber_status = "closed";
				} else {
					$subscriber_status = "open";
					Billrun_Factory::log("Getting flat price for subscriber $sid", Zend_log::INFO);
					$flat_price = $subscriber->getFlatPrice();
					Billrun_Factory::log("Finished getting flat price for subscriber $sid", Zend_log::INFO);
					if (is_null($flat_price)) {
						Billrun_Factory::log()->log("Couldn't find flat price for subscriber " . $sid . " for billrun " . $billrun_key, Zend_Log::ALERT);
						continue;
					}
					Billrun_Factory::log('Adding flat line to subscriber ' . $sid, Zend_Log::INFO);
					$flat_lines[] = $this->saveFlatLine($subscriber, $billrun_key);
					Billrun_Factory::log('Finished adding flat line to subscriber ' . $sid, Zend_Log::INFO);
				}
				$account_billrun->addSubscriber($subscriber, $subscriber_status);
				Billrun_Factory::dispatcher()->trigger('afterAggregateSubscriber', array($subscriber, $account_billrun, &$this));
			}
			if ($this->write_stamps_to_file) {
				$lines = $account_billrun->addLines(false, 0, $flat_lines);
				if (!empty($lines)) {
					$stamps_str = implode("\n", array_keys($lines)) . "\n";
					file_put_contents($this->file_path, $stamps_str, FILE_APPEND);
				}
			} else {
				$lines = $account_billrun->addLines(true, 0, $flat_lines);
			}
			//save the billrun
			Billrun_Factory::log('Saving account ' . $accid, Zend_Log::INFO);
			if ($account_billrun->save() === false) {
				Billrun_Factory::log('Error saving account ' . $accid, Zend_Log::ALERT);
				continue;
			}
			Billrun_Factory::log('Finished saving account ' . $accid, Zend_Log::INFO);

			Billrun_Factory::dispatcher()->trigger('aggregateBeforeCloseAccountBillrun', array($accid, $account, $account_billrun, $lines, &$this));
			Billrun_Factory::log("Closing billrun $billrun_key for account $accid", Zend_log::INFO);
			$account_billrun->close($this->min_invoice_id);
			Billrun_Factory::log("Finished closing billrun $billrun_key for account $accid", Zend_log::INFO);
			Billrun_Factory::dispatcher()->trigger('afterAggregateAccount', array($accid, $account, $account_billrun, $lines, &$this));
		}
		if ($billruns_count == count($this->data)) {
			$end_msg = "Finished iterating page $this->page of size $this->size. Memory usage is " . memory_get_usage() / 1048576 . " MB";
			Billrun_Factory::log($end_msg, Zend_log::INFO);
			$this->sendEndMail($end_msg);
		}

		// @TODO trigger after aggregate
		Billrun_Factory::dispatcher()->trigger('afterAggregate', array($this->data, &$this));
	}

	protected function sendEndMail($msg) {
		$recipients = Billrun_Factory::config()->getConfigValue('log.email.writerParams.to');
		if ($recipients) {
			Billrun_Util::sendMail("BillRun customer aggregate page finished", $msg, $recipients);
		}
	}

	/**
	 * Creates and saves a flat line to the db
	 * @param Billrun_Subscriber $subscriber the subscriber to create a flat line to
	 * @param string $billrun_key the billrun for which to add the flat line
	 * @return array the inserted line or the old one if it already exists
	 */
	protected function saveFlatLine($subscriber, $billrun_key) {
		$flat_entry = $subscriber->getFlatEntry($billrun_key);
		try {
			$this->lines->insert($flat_entry, array("w" => 1));
		} catch (Exception $e) {
			if ($e->getCode() == 11000) {
				Billrun_Factory::log("Flat line already exists for subscriber " . $subscriber->sid . " for billrun " . $billrun_key, Zend_log::ALERT);
			} else {
				Billrun_Factory::log("Problem inserting flat line for subscriber " . $subscriber->sid . " for billrun " . $billrun_key . ". error message: " . $e->getMessage() . ". error code: " . $e->getCode(), Zend_log::ALERT);
			}
		}
		return new Mongodloid_Entity($flat_entry);
	}

	protected function save($data) {
		
	}

	/**
	 *
	 * @param type $sid
	 * @param type $item
	 * @deprecated update of billing line is done in customer pricing stage
	 */
	protected function updateBillingLine($sid, $item) {
		
	}

	/**
	 * method to update the billrun by the billing line (row)
	 * @param Mongodloid_Entity $billrun the billrun line
	 * @param Mongodloid_Entity $line the billing line
	 *
	 * @return boolean true on success else false
	 */
	protected function updateBillrun($billrun, $line) {
		
	}

	/**
	 * Load all rates from db into memory
	 */
	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'));
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
	}

	/**
	 * HACK TO MAKE THE BILLLRUN FASTER
	 * Get a rate from the row
	 * @param Mongodloid_Entity the row to get rate from
	 * @return Mongodloid_Entity the rate of the row
	 */
	protected function getRowRate($row) {
		$raw_rate = $row->get('arate', true);
		$id_str = strval($raw_rate['$id']);
		if (isset($this->rates[$id_str])) {
			return $this->rates[$id_str];
		} else {
			return $row->get('arate', false);
		}
	}

	protected function initStampsDir() {
		@mkdir($this->stamps_dir, 0777, true);
		$this->file_path = $this->stamps_dir . '/' . $this->size . '.' . $this->page;
		@unlink($this->file_path);
		@touch($this->file_path);
		return is_file($this->file_path);
	}

}
