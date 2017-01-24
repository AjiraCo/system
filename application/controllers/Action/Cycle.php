<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Cycle action class
 *
 * @package  Action
 * @since    5.0
 * 
 */
class CycleAction extends Action_Base {
	use Billrun_Traits_OnChargeDay;
	
	protected $billingCycleCol = null;

	/**
	 * Build the options for the cycle
	 * @return boolean
	 * @todo This is a generic function, might be better to create a basic action class
	 * that uses an cycle and have all these functions in it.
	 */
	protected function buildOptions() {
		$possibleOptions = array(
			'type' => false,
			'stamp' => true,
			'page' => true,
			'size' => true,
			'fetchonly' => true,
		);

		$options = $this->_controller->getInstanceOptions($possibleOptions);
		if ($options === false) {
			return false;
		}

		if (empty($options['stamp'])) {
			$nextBillrunKey = Billrun_Billrun::getBillrunKeyByTimestamp(time());
			$currentBillrunKey = Billrun_Billrun::getPreviousBillrunKey($nextBillrunKey);
			$options['stamp'] = $currentBillrunKey;
		}

		if (!isset($options['size']) || !$options['size']) {
			// default value for size
			$options['size'] = Billrun_Factory::config()->getConfigValue('customer.aggregator.size');
		}
		
		return $options;
	}
	
	/**
	 * Get the process interval
	 * @return int
	 */
	protected function getProcessInterval() {
		$processInterval = (int) Billrun_Factory::config()->getConfigValue('cycle.processes.interval');
		if (Billrun_Factory::config()->isProd()) {
			if ($processInterval < 60) {   // 1 minute is minimum sleep time 
				$processInterval = 60;
			}
		}
		return $processInterval;
	}
	
	/**
	 * method to execute the aggregate process
	 * it's called automatically by the cli main controller
	 */
	public function execute() {
		// Check if we should cycle.
		$startHoursLag = Billrun_Factory::config()->getConfigValue('billrun.start_hours_lag');
		if(!$this->isChargeDay($startHoursLag)) {
			$this->_controller->addOutput("Skipping cycle.");
			return;
		}
		
		$options = $this->buildOptions();
		$this->billingCycleCol = Billrun_Factory::db()->billing_cycleCollection();
		$processInterval = $this->getProcessInterval();

		$stamp = $options['stamp'];
		$size = (int)$options['size'];
		
		$zeroPages = Billrun_Factory::config()->getConfigValue('customer.aggregator.zero_pages_limit');
				
		while(!Billrun_Aggregator_Customer::isBillingCycleOver($this->billingCycleCol, $stamp, $size, $zeroPages)) {
			$pid = pcntl_fork();
			if ($pid == -1) {
				die('could not fork');
			}
			
			$this->_controller->addOutput("Running on PID " . $pid);
		
			// Parent process.
			if ($pid) {
				$this->executeParentProcess($processInterval);
				continue;
			}
			
			// Child process
			$this->executeChildProcess($options);
			break;
		}
	}
	
	protected function executeParentProcess($processInterval) {
		$this->_controller->addOutput("Going to sleep for " . $processInterval);
		sleep($processInterval);
		pcntl_signal(SIGCHLD, SIG_IGN);
	}
	
	/**
	 * Execute the child process logic
	 * @param type $options
	 * @return type
	 */
	protected function executeChildProcess($options) {
		$aggregator = $this->getAggregator($options);
		if($aggregator == false) {
			return;
		}
		
		$this->_controller->addOutput("Loading data to Aggregate...");
		$aggregator->load();
		if (isset($options['fetchonly'])) {
			$this->_controller->addOutput("Only fetched aggregate accounts info. Exit...");
			return;
		}

		$this->_controller->addOutput("Starting to Aggregate. This action can take a while...");
		$aggregator->aggregate();
		$this->_controller->addOutput("Finish to Aggregate.");
	}
	
	/**
	 * Get an aggregator with input options
	 * @param array $options - Array of options to initialize the aggregator with.
	 * @return Aggregator
	 * @todo getAggregator might be common in actions, maybe create a basic aggregate action class?
	 */
	protected function getAggregator($options) {
		$this->_controller->addOutput("Loading aggregator");
		$aggregator = Billrun_Aggregator::getInstance($options);
		
		if(!$aggregator || !$aggregator->isValid()) {
			$this->_controller->addOutput("Aggregator cannot be loaded");
			return false;
		}
		
		$this->_controller->addOutput("Aggregator loaded");
		return $aggregator;
	}
}
