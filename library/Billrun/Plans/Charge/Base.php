<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * A basic class to implement the charge logic
 *
 * @package  Plans
 * @since    5.2
 */
abstract class Billrun_Plans_Charge_Base {
	use Billrun_Traits_DateSpan;
	
	protected $price;
	
	/**
	 *
	 * @var Billrun_DataTypes_CycleTime
	 */
	protected $cycle;
	
	/**
	 * Create a new instance of the plans charge base class
	 * @param array $plan - Raw plan data
	 */
	public function __construct($plan) {
		$this->cycle = $plan['cycle'];
		$this->price = $plan['price'];
		
		$this->setSpan($plan);
	}
	
	/**
	 * Get the price of the current plan.
	 * @return float the price of the plan without VAT.
	 */
	public abstract function getPrice();		
}
