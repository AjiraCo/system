<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Balance action class
 *
 * @package  Action
 * @since    0.5
 */
class BalanceAction extends Action_Base {

	public function execute() {
		$request = $this->getRequest();
		$aid = $request->get("aid");
		$stamp = Billrun_Util::getBillrunKey(time());
		$subscribers = $request->get("subscribers");
		if (!is_numeric($aid)) {
			die();
		}
		if (is_string($subscribers)) {
			$subscribers = explode(",", $subscribers);
		} else {
			$subscribers = array();
		}

		$options = array(
			'type' => 'balance',
			'aid' => $aid,
			'subscribers' => $subscribers,
			'stamp' => $stamp,
		);
		$generator = Billrun_Generator::getInstance($options);

		if ($generator) {
			$generator->load();
			header('Content-type: text/xml');
			$generator->generate();
			$this->getController()->setOutput(array(false, true)); // hack
		} else {
			$this->_controller->addOutput("Generator cannot be loaded");
		}
	}

}
