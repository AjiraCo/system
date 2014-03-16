<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator class for tap3 records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Rate_Tap3 extends Billrun_Calculator_Rate {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'tap3';

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->loadRates();
	}

	/**
	 * write the calculation into DB.
	 * @param $row the line CDR to update. 
	 */
	public function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array($row, $this));

		$current = $row->getRawData();

		$usage_type = $this->getLineUsageType($row);
		$volume = $this->getLineVolume($row, $usage_type);
		$rate = $this->getLineRate($row, $usage_type);

		$added_values = array(
			'usaget' => $usage_type,
			'usagev' => $volume,
			$this->ratingField => $rate ? $rate->createRef() : $rate,
		);
		$newData = array_merge($current, $added_values);
		$row->setRawData($newData);

		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array($row, $this));
		return true;
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineVolume
	 */
	protected function getLineVolume($row, $usage_type) {
		$volume = null;
		switch ($usage_type) {
			case 'sms' :
			case 'incoming_sms' :
				$volume = 1;
				break;

			case 'call' :
			case 'incoming_call' :
				$volume = $row->get('basicCallInformation.TotalCallEventDuration');
				break;

			case 'data' :
				$volume = $row->get('GprsServiceUsed.DataVolumeIncoming') + $row->get('GprsServiceUsed.DataVolumeOutgoing');
				break;
		}
		return $volume;
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineUsageType
	 */
	protected function getLineUsageType($row) {

		$usage_type = null;

		$record_type = $row['record_type'];
		if (isset($row['BasicServiceUsedList']['BasicServiceUsed']['BasicService']['BasicServiceCode']['TeleServiceCode'])) {
			$tele_service_code = $row['BasicServiceUsedList']['BasicServiceUsed']['BasicService']['BasicServiceCode']['TeleServiceCode'];
			if ($tele_service_code == '11') {
				if ($record_type == '9') {
					$usage_type = 'call'; // outgoing call
				} else if ($record_type == 'a') {
					$usage_type = 'incoming_call'; // incoming / callback
				}
			} else if ($tele_service_code == '22') {
				if ($record_type == '9') {
					$usage_type = 'sms';
				}
			} else if ($tele_service_code == '21') {
				if ($record_type == 'a') {
					$usage_type = 'incoming_sms';
				}
			}
		} else {
			if ($record_type == 'e') {
				$usage_type = 'data';
			}
		}

		return $usage_type;
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineRate
	 */
	protected function getLineRate($row, $usage_type) {
		$line_time = $row['urt'];
		$serving_network = $row['serving_network'];
		$matchedRate = false;
		$prefix_length_matched = 0;

		if (!is_null($serving_network)) {
			$call_number = isset($row['called_number']) ? $row->get('called_number') : (isset($row['calling_number']) ? $row->get('calling_number') : NULL);
			if ($call_number) {
				$call_number = preg_replace("/^[^1-9]*/", "", $call_number);
				$call_number_prefixes = $this->getPrefixes($call_number);
			}
			$potential_rates = array();
			if (isset($this->rates['by_names'][$serving_network])) {
				$potential_rates = $this->rates['by_names'][$serving_network];
			}
			if (!empty($this->rates['by_regex'])) {
				foreach ($this->rates['by_regex'] as $regex => $regex_rates) {
					if (preg_match($regex, $serving_network)) {
						$potential_rates = array_merge($potential_rates, $regex_rates);
					}
				}
			}

			foreach ($potential_rates as $rate) {
				if (isset($rate['rates'][$usage_type])) {
					if ($rate['from'] <= $line_time && $rate['to'] >= $line_time) {
						if (!$matchedRate || (is_array($rate['params']['serving_networks']) && !$prefix_length_matched)) { // array of serving networks is stronger then regex of serving_networks
							$matchedRate = $rate;
						}
						if (isset($call_number_prefixes) && !empty($rate['params']['prefix'])) {
							foreach ($call_number_prefixes as $prefix) {
								if (in_array($prefix, $rate['params']['prefix']) && strlen($prefix) > $prefix_length_matched) {
									$prefix_length_matched = strlen($prefix);
									$matchedRate = $rate;
								}
							}
						}
					}
				}
			}
		}

		return $matchedRate;
	}

	/**
	 * Get the header data  of the file that a given TAP3 CDR line belongs to. 
	 * @param type $line the cdr  lline to get the header for.
	 * @return Object representing the file header of the line.
	 */
	protected function getLineHeader($line) {
		return Billrun_Factory::db()->logCollection()->query(array('header.stamp' => $line['log_stamp']))->cursor()->current();
	}

	/**
	 * Caches the rates in the memory for fast computations
	 */
	protected function loadRates() {
		$query = array(
			'params.serving_networks' => array(
				'$exists' => true,
			),
		);
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query($query)->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'));
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			if (is_array($rate['params']['serving_networks'])) {
				foreach ($rate['params']['serving_networks'] as $serving_network) {
					$this->rates['by_names'][$serving_network][] = $rate;
				}
			} else if (is_string($rate['params']['serving_networks'])) {
				$this->rates['by_regex'][$rate['params']['serving_networks']][] = $rate;
			}
		}
	}

}

?>
