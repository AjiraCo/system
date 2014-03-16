<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator for  pricing  billing lines with customer price.
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_CustomerPricing extends Billrun_Calculator {

	const DEF_CALC_DB_FIELD = 'aprice';
	
	protected $pricingField = self::DEF_CALC_DB_FIELD;
	static protected $type = "pricing";

	/**
	 *
	 * @var boolean is customer price vatable by default
	 */
	protected $vatable = true;

	/**
	 * Save unlimited usages to balances
	 * @var boolean
	 */
	protected $unlimited_to_balances = true;
	protected $plans = array();

	/**
	 *
	 * @var Mongodloid_Collection 
	 */
	protected $balances = null;

	/**
	 *
	 * @var int timestamp
	 */
	protected $billrun_lower_bound_timestamp;

	public function __construct($options = array()) {
		if (isset($options['autoload'])) {
			$autoload = $options['autoload'];
		} else {
			$autoload = true;
		}

		$options['autoload'] = false;
		parent::__construct($options);

		if (isset($options['calculator']['limit'])) {
			$this->limit = $options['calculator']['limit'];
		}
		if (isset($options['calculator']['vatable'])) {
			$this->vatable = $options['calculator']['vatable'];
		}
		if (isset($options['calculator']['months_limit'])) {
			$this->months_limit = $options['calculator']['months_limit'];
		}
		if (isset($options['calculator']['unlimited_to_balances'])) {
			$this->unlimited_to_balances = (boolean) ($options['calculator']['unlimited_to_balances']);
		}
		$this->billrun_lower_bound_timestamp = is_null($this->months_limit) ? 0 : strtotime($this->months_limit . " months ago");
		// set months limit
		if ($autoload) {
			$this->load();
		}
		$this->loadRates();
		$this->loadPlans();
		$this->balances = Billrun_Factory::db(array('name' => 'balances'))->balancesCollection();
	}

	protected function getLines() {
		$query = array();
		$query['type'] = array('$in' => array('ggsn', 'smpp', 'mmsc', 'smsc', 'nsn', 'tap3', 'credit'));
		return $this->getQueuedLines($query);
	}

	/**
	 * execute the calculation process
	 * @TODO this function mighh  be a duplicate of  @see Billrun_Calculator::calc() do we really  need the diffrence  between Rate/Pricing? (they differ in the plugins triggered)
	 */
	public function calc() {
		Billrun_Factory::dispatcher()->trigger('beforePricingData', array('data' => $this->data));
		$lines_coll = Billrun_Factory::db()->linesCollection();

		$lines = $this->pullLines($this->lines);
		foreach ($lines as $key => $line) {
			if ($line) {
				Billrun_Factory::dispatcher()->trigger('beforePricingDataRow', array('data' => &$line));
				//Billrun_Factory::log()->log("Calcuating row : ".print_r($item,1),  Zend_Log::DEBUG);
				$line->collection($lines_coll);
				if ($this->isLineLegitimate($line)) {
					if (!$this->updateRow($line)) {
						unset($this->lines[$line['stamp']]);
						continue;
					}
					$this->data[$line['stamp']] = $line;
				}
				//$this->updateLinePrice($item); //@TODO  this here to prevent divergance  between the priced lines and the subscriber's balance/billrun if the process fails in the middle.
				Billrun_Factory::dispatcher()->trigger('afterPricingDataRow', array('data' => &$line));
			}
		}
		Billrun_Factory::dispatcher()->trigger('afterPricingData', array('data' => $this->data));
	}

	public function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array($row, $this));
		$billrun_key = Billrun_Util::getBillrunKey($row->get('urt')->sec);
		$rate = $this->getRowRate($row);

		//TODO  change this to be configurable.
		$pricingData = array();

		$usage_type = $row['usaget'];
		$volume = $row['usagev'];

		if (isset($volume)) {
			if ($row['type'] == 'credit') {
				$accessPrice = isset($rate['rates'][$usage_type]['access']) ? $rate['rates'][$usage_type]['access'] : 0;
				$pricingData = array($this->pricingField => $accessPrice + self::getPriceByRate($rate, $usage_type, $volume));
			} else {
				$pricingData = $this->updateSubscriberBalance($row, $billrun_key, $usage_type, $rate, $volume);
			}
			if (!$pricingData) {
				return false;
			}
			$pricingData['billrun'] = "000000";
		} else {
			Billrun_Factory::log()->log("Line with stamp " . $row['stamp'] . " is missing volume information", Zend_Log::ALERT);
			return false;
		}

		$pricingDataTxt = "Saving pricing data to line with stamp: " . $row['stamp'] . ".";
		foreach ($pricingData as $key => $value) {
			$pricingDataTxt.=" " . $key . ": " . $value . ".";
		}
		Billrun_Factory::log()->log($pricingDataTxt, Zend_Log::DEBUG);
		$row->setRawData(array_merge($row->getRawData(), $pricingData));

		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array($row, $this));
		return true;
	}

	/**
	 * Get pricing data for a given rate / subcriber.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @param string $usageType The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param mixed $subr the  subscriber that generated the usage.
	 * @return Array the 
	 */
	protected function getLinePricingData($volumeToPrice, $usageType, $rate, $sub_balance) {
		$accessPrice = isset($rate['rates'][$usageType]['access']) ? $rate['rates'][$usageType]['access'] : 0;
		$subscriber_current_plan = $this->getBalancePlan($sub_balance);
		$plan = Billrun_Factory::plan(array('data' => $subscriber_current_plan, 'disableCache' => true));

		$ret = array();
		if ($plan->isRateInSubPlan($rate, $usageType)) {
			$volumeToPrice = $volumeToPrice - $plan->usageLeftInPlan($sub_balance['balance'], $usageType);

			if ($volumeToPrice < 0) {
				$volumeToPrice = 0;
				//@TODO  check  if that actually the action we want once all the usage is in the plan...
				$accessPrice = 0;
			} else if ($volumeToPrice > 0) {
				$ret['over_plan'] = $volumeToPrice;
			}
		} else {
			$ret['out_plan'] = $volumeToPrice;
		}

		$price = $accessPrice + self::getPriceByRate($rate, $usageType, $volumeToPrice);
		//Billrun_Factory::log()->log("Rate : ".print_r($typedRates,1),  Zend_Log::DEBUG);
		$ret[$this->pricingField] = $price;
		return $ret;
	}

	/**
	 * Override parent calculator to save changes with update (not save)
	 */
	public function writeLine($line, $dataKey) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteLine', array('data' => $line));
		$save = array();
		$saveProperties = array($this->pricingField, 'billrun', 'over_plan', 'in_plan', 'out_plan', 'plan_ref', 'usagesb');
		foreach ($saveProperties as $p) {
			if (!is_null($val = $line->get($p, true))) {
				$save['$set'][$p] = $val;
			}
		}
		$where = array('stamp' => $line['stamp']);
		Billrun_Factory::db()->linesCollection()->update($where, $save);
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteLine', array('data' => $line));
		if (!isset($line['usagev']) || $line['usagev'] === 0) {
			$this->removeLineFromQueue($line);
			unset($this->data[$dataKey]);
		}
	}

	/**
	 * Calculates the price for the given volume (w/o access price)
	 * @param array $rate the rate entry
	 * @param string $usage_type the usage type
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @return int the calculated price
	 */
	public static function getPriceByRate($rate, $usage_type, $volume) {
		$rates_arr = $rate['rates'][$usage_type]['rate'];
		$price = 0;
		foreach ($rates_arr as $currRate) {
			if (0 == $volume) { // volume could be negative if it's a refund amount
				break;
			}//break if no volume left to price.
			$volumeToPriceCurrentRating = ($volume - $currRate['to'] < 0) ? $volume : $currRate['to']; // get the volume that needed to be priced for the current rating
			if (isset($currRate['ceil'])) {
				$ceil = $currRate['ceil'];
			} else {
				$ceil = true;
			}
			if ($ceil) {
				$price += floatval(ceil($volumeToPriceCurrentRating / $currRate['interval']) * $currRate['price']); // actually price the usage volume by the current 	
			} else {
				$price += floatval($volumeToPriceCurrentRating / $currRate['interval'] * $currRate['price']); // actually price the usage volume by the current 
			}
			$volume = $volume - $volumeToPriceCurrentRating; //decressed the volume that was priced
		}
		return $price;
	}

	/**
	 * Update the subscriber balance for a given usage.
	 * @param array $counters the counters to update
	 * @param Mongodloid_Entity $row the input line
	 * @param string $billrun_key the billrun key at the row time
	 * @param string $usageType The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @return mixed array with the pricing data on success, false otherwise
	 */
	protected function updateSubscriberBalance($row, $billrun_key, $usage_type, $rate, $volume) {
		Billrun_Factory::dispatcher()->trigger('beforeUpdateSubscriberBalance', array($row, $billrun_key, $this));
		$plan = Billrun_Factory::plan(array('name' => $row['plan'], 'time' => $row['urt']->sec, 'disableCache' => true));
		$plan_ref = $plan->createRef();
		if (is_null($plan_ref)) {
			Billrun_Factory::log('No plan found for subscriber ' . $row['sid'], Zend_Log::ALERT);
			return false;
		}
		$balance_totals_key = $this->getBalanceTotalsKey($row['type'], $usage_type, $plan, $rate);
		$counters = array($balance_totals_key => $volume);

		if ($this->isUsageUnlimited($rate, $usage_type, $plan)) {
			if ($this->unlimited_to_balances) {
				$balance = $this->increaseSubscriberBalance($counters, $billrun_key, $row['aid'], $row['sid'], $plan_ref);
				$pricingData = $this->getLinePricingData($volume, $usage_type, $rate, $balance);
				$pricingData['usagesb'] = floatval($balance['balance']['totals'][$this->getUsageKey($counters)]['usagev']);
			} else {
				$balance = null;
				$pricingData = array($this->pricingField => 0);
			}
		} else {
			$balance_unique_key = array('sid' => $row['sid'], 'billrun_key' => $billrun_key);
			if (!($balance = $this->createBalanceIfMissing($row['aid'], $row['sid'], $billrun_key, $plan_ref))) {
				return false;
			} else if ($balance === true) {
				$balance = null;
			}

			if (is_null($balance)) {
				$balance = Billrun_Factory::balance($balance_unique_key);
			}
			if (!$balance || !$balance->isValid()) {
				Billrun_Factory::log()->log("couldn't get balance for : " . print_r(array(
						'sid' => $row['sid'],
						'billrun_month' => $billrun_key
						), 1), Zend_Log::INFO);
				return false;
			} else {
				Billrun_Factory::log()->log("Found balance " . $billrun_key . " for subscriber " . $row['sid'], Zend_Log::DEBUG);
			}

			$subRaw = $balance->getRawData();
			$stamp = strval($row['stamp']);
			if (isset($subRaw['tx']) && array_key_exists($stamp, $subRaw['tx'])) { // we're after a crash
				$pricingData = $subRaw['tx'][$stamp]; // restore the pricingData from before the crash
				return $pricingData;
			}
			$pricingData = $this->getLinePricingData($volume, $usage_type, $rate, $balance);
			$balance_unique_key['billrun_month'] = $balance_unique_key['billrun_key'];
			unset($balance_unique_key['billrun_key']);
			$query = $balance_unique_key;
			$update = array();
			$update['$set']['tx.' . $stamp] = $pricingData;
			foreach ($counters as $key => $value) {
				$old_usage = $subRaw['balance']['totals'][$key]['usagev'];
				$query['balance.totals.' . $key . '.usagev'] = $old_usage;
				$update['$set']['balance.totals.' . $key . '.usagev'] = $old_usage + $value;
				$update['$inc']['balance.totals.' . $key . '.cost'] = $pricingData[$this->pricingField];
				$update['$inc']['balance.totals.' . $key . '.count'] = 1;
				$pricingData['usagesb'] = floatval($old_usage);
			}
			$update['$set']['balance.cost'] = $subRaw['balance']['cost'] + $pricingData[$this->pricingField];
			$options = array('w' => 1);
			$is_data_usage = $this->getUsageKey($counters) == 'data';
			if ($is_data_usage) {
				$this->setMongoNativeLong(1);
			}
			Billrun_Factory::log()->log("Updating balance " . $billrun_key . " of subscriber " . $row['sid'], Zend_Log::DEBUG);
			$ret = $this->balances->update($query, $update, $options);
			if ($is_data_usage) {
				$this->setMongoNativeLong(0);
			}
			if (!($ret['ok'] && $ret['updatedExisting'])) { // failed because of different totals (could be that another server with another line raised the totals). Need to calculate pricingData from the beginning
				Billrun_Factory::log()->log("Concurrent write to balance " . $billrun_key . " of subscriber " . $row['sid'] . ". Retrying...", Zend_Log::DEBUG);
				return $this->updateSubscriberBalance($row, $billrun_key, $usage_type, $rate, $volume);
			}
			Billrun_Factory::log()->log("Line with stamp " . $row['stamp'] . " was written to balance " . $billrun_key . " for subscriber " . $row['sid'], Zend_Log::DEBUG);
			$row['tx_saved'] = true; // indication for transaction existence in balances. Won't & shouldn't be saved to the db.
		}
		Billrun_Factory::dispatcher()->trigger('afterUpdateSubscriberBalance', array($row, $balance, $pricingData[$this->pricingField], $this));
		return $pricingData;
	}

	protected function getUsageKey($counters) {
		return key($counters); // array pointer will always point to the first key
	}

	/**
	 * 
	 * @param int $status either 1 to turn on or 0 for off
	 */
	protected function setMongoNativeLong($status = 1) {
		ini_set('mongo.native_long', $status);
	}

	protected function increaseSubscriberBalance($counters, $billrun_key, $aid, $sid, $plan_ref) {
		$query = array('sid' => $sid, 'billrun_month' => $billrun_key);
		foreach ($counters as $key => $value) {
			$update['$inc']['balance.totals.' . $key . '.usagev'] = $value;
			$update['$inc']['balance.totals.' . $key . '.count'] = 1;
		}
		$is_data_usage = $this->getUsageKey($counters) == 'data';
		if ($is_data_usage) {
			$this->setMongoNativeLong(1);
		}
		Billrun_Factory::log()->log("Increasing subscriber $sid balance " . $billrun_key, Zend_Log::DEBUG);
		$balance = $this->balances->findAndModify($query, $update, array(), array());
		if ($is_data_usage) {
			$this->setMongoNativeLong(0);
		}
		if ($balance->isEmpty()) {
			Billrun_Balance::createBalanceIfMissing($aid, $sid, $billrun_key, $plan_ref);
			return $this->increaseSubscriberBalance($counters, $billrun_key, $aid, $sid, $plan_ref);
		} else {
			Billrun_Factory::log()->log("Found balance " . $billrun_key . " for subscriber " . $sid, Zend_Log::DEBUG);
		}
		return Billrun_Factory::balance(array('data' => $balance));
	}

	/**
	 * removes the transactions from the subscriber's balance to save space.
	 * @param type $row
	 */
	public function removeBalanceTx($row) {
		$sid = $row['sid'];
		$billrun_key = Billrun_Util::getBillrunKey($row['urt']->sec);
		$query = array(
			'billrun_month' => $billrun_key,
			'sid' => $sid,
		);
		$values = array(
			'$unset' => array(
				'tx.' . $row['stamp'] => 1
			)
		);
		$this->balances->update($query, $values);
	}

	/**
	 * @see Billrun_Calculator::getCalculatorQueueType
	 */
	public function getCalculatorQueueType() {
		return self::$type;
	}

	/**
	 * @see Billrun_Calculator::isLineLegitimate
	 */
	public function isLineLegitimate($line) {
		$arate = $this->getRateByRef($line->get('arate', true));
		return !is_null($arate) && (empty($arate['skip_calc']) || !in_array(self::$type, $arate['skip_calc'])) &&
			isset($line['sid']) && $line['sid'] !== false &&
			$line['urt']->sec >= $this->billrun_lower_bound_timestamp;
	}

	/**
	 * 
	 */
	protected function setCalculatorTag($query = array(), $update = array()) {
		parent::setCalculatorTag($query, $update);
		foreach ($this->data as $item) {
			if ($this->isLineLegitimate($item) && !empty($item['tx_saved'])) {
				$this->removeBalanceTx($item); // we can safely remove the transactions after the lines have left the current queue
			}
		}
	}

	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'));
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
	}

	protected function loadPlans() {
		$plans_coll = Billrun_Factory::db()->plansCollection();
		$plans = $plans_coll->query()->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'));
		foreach ($plans as $plan) {
			$plan->collection($plans_coll);
			$this->plans[strval($plan->getId())] = $plan;
		}
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getRowRate($row) {
		return $this->getRateByRef($row->get('arate', true));
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getBalancePlan($sub_balance) {
		return $this->getPlanByRef($sub_balance->get('current_plan', true));
	}

	protected function getPlanByRef($plan_ref) {
		if (isset($plan_ref['$id'])) {
			$id_str = strval($plan_ref['$id']);
			if (isset($this->plans[$id_str])) {
				return $this->plans[$id_str];
			}
		}
		return null;
	}

	protected function getRateByRef($rate_ref) {
		if (isset($rate_ref['$id'])) {
			$id_str = strval($rate_ref['$id']);
			if (isset($this->rates[$id_str])) {
				return $this->rates[$id_str];
			}
		}
		return null;
	}

	/**
	 * Add plan reference to line
	 * @param Mongodloid_Entity $row
	 * @param string $plan
	 */
	protected function addPlanRef($row, $plan) {
		$planObj = Billrun_Factory::plan(array('name' => $plan, 'time' => $row['urt']->sec, 'disableCache' => true));
		if (!$planObj->get('_id')) {
			Billrun_Factory::log("Couldn't get plan for CDR line : {$row['stamp']} with plan $plan", Zend_Log::ALERT);
			return;
		}
		$row['plan_ref'] = $planObj->createRef();
		return $row->get('plan_ref', true);
	}

	/**
	 * Create a subscriber entry if none exists. Uses an update query only if the balance doesn't exist
	 * @param type $subscriber
	 */
	protected function createBalanceIfMissing($aid, $sid, $billrun_key, $plan_ref) {
		$balance = Billrun_Factory::balance(array('sid' => $sid, 'billrun_key' => $billrun_key));
		if ($balance->isValid()) {
			return $balance;
		} else {
			return Billrun_Balance::createBalanceIfMissing($aid, $sid, $billrun_key, $plan_ref);
		}
	}

	/**
	 * 
	 * @param Mongodloid_Entity $rate
	 * @param string $usage_type
	 * @param Billrun_Plan $plan
	 */
	protected function isUsageUnlimited($rate, $usage_type, $plan) {
		return $plan->isRateInSubPlan($rate, $usage_type) && $plan->isUnlimited($usage_type);
	}

	protected function getBalanceTotalsKey($type, $usage_type, $plan, $rate) {
		if ($type != 'tap3') {
			if (($usage_type == "call" || $usage_type == "sms") && !$plan->isRateInSubPlan($rate, $usage_type)) {
				$usage_class_prefix = "out_plan_";
			} else {
				$usage_class_prefix = "";
			}
		} else {
			$usage_class_prefix = "intl_roam_";
		}
		return $usage_class_prefix . $usage_type;
	}

}
