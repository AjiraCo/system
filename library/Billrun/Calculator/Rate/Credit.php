<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator class for credit records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Rate_Credit extends Billrun_Calculator_Rate {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = "credit";

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->loadRates();
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineVolume
	 * @deprecated since version 2.9
	 */
	protected function getLineVolume($row) {
		return $row['amount_without_vat'];
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineUsageType
	 */
	protected function getLineUsageType($row) {
		return 'credit';
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineRate
	 */
	protected function getLineRate($row) {

		$rate_key = $row['vatable'] ? "CREDIT_VATABLE" : "CREDIT_VAT_FREE";
		$rate = $this->rates[$rate_key];

		return $rate;
	}

	/**
	 * Caches the rates in the memory for fast computations
	 */
	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$query = array(
			'key' => array(
				'$in' => array('CREDIT_VATABLE', 'CREDIT_VAT_FREE'),
			),
		);
		$rates = $rates_coll->query($query)->cursor();
		$this->rates = array();
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[$rate['key']] = $rate;
		}
	}

}
