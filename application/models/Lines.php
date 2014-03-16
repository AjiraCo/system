<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Lines model class to pull data from database for lines collection
 *
 * @package  Models
 * @subpackage Lines
 * @since    0.5
 */
class LinesModel extends TableModel {

	/**
	 *
	 * @var boolean show garbage lines
	 */
	protected $garbage = false;
	protected $lines_coll = null;

	public function __construct(array $params = array()) {
		$params['collection'] = Billrun_Factory::db()->lines;
		parent::__construct($params);
		$this->search_key = "stamp";
		$this->lines_coll = Billrun_Factory::db()->linesCollection();
	}

	public function getProtectedKeys($entity, $type) {
		$parent_protected = parent::getProtectedKeys($entity, $type);
		if ($type == 'update') {
			return array_merge($parent_protected, array("type", "aid", "sid", "file", "log_stamp", "imsi", "source", "stamp", "urt", "usaget", "billrun"));
		}
		return $parent_protected;
	}

	/**
	 * @param Mongodloid collection $collection
	 * @param array $entity
	 * 
	 * @return type
	 * @todo move to model
	 */
	public function getItem($id) {

		$entity = parent::getItem($id);

		if (isset($entity['urt'])) {
			$entity['urt'] = (new Zend_Date($entity['urt']->sec, null, new Zend_Locale('he_IL')))->getIso();
		}
		if (isset($entity['arate'])) {
			$data = $entity->get('arate', false);
			if ($data instanceof Mongodloid_Entity) {
				$entity['arate'] = $data->get('key');
			}
		}
		if (isset($entity['pzone'])) {
			$data = $entity->get('pzone', false);
			if ($data instanceof Mongodloid_Entity) {
				$entity['pzone'] = $data->get('key');
			}
		}
		if (isset($entity['wsc'])) {
			$data = $entity->get('wsc', false);
			if ($data instanceof Mongodloid_Entity) {
				$entity['wsc'] = $data->get('key');
			}
		}
		if (isset($entity['wsc_in'])) {
			$data = $entity->get('wsc_in', false);
			if ($data instanceof Mongodloid_Entity) {
				$entity['wsc_in'] = $data->get('key');
			}
		}

		return $entity;
	}

	public function getHiddenKeys($entity, $type) {
		$hidden_keys = array_merge(parent::getHiddenKeys($entity, $type), array("plan_ref"));
		return $hidden_keys;
	}

	public function update($data) {
		$currentDate = new MongoDate();
		if (isset($data['arate'])) {
			$ratesColl = Billrun_Factory::db()->ratesCollection();
			$rateEntity = $ratesColl->query('key', $data['arate'])
							->lessEq('from', $currentDate)
							->greaterEq('to', $currentDate)
							->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'))->current();
			$data['arate'] = $rateEntity->createRef($ratesColl);
		}
		if (isset($data['plan'])) {
			$plansColl = Billrun_Factory::db()->plansCollection();
			$planEntity = $plansColl->query('name', $data['plan'])
							->lessEq('from', $currentDate)
							->greaterEq('to', $currentDate)
							->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'))->current();
			$data['plan_ref'] = $planEntity->createRef($plansColl);
		}
		parent::update($data);
	}

	public function getData($filter_query = array(), $skip = null, $size = null) {
		if (empty($skip)) {
			$skip = $this->offset();
		}
		if (empty($size)) {
			$size = $this->size;
		}

		$limit = Billrun_Factory::config()->getConfigValue('admin_panel.lines.limit', 10000);
		$cursor = $this->collection->query($filter_query)->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'))->limit($limit);
		$this->_count = $cursor->count();
		$resource = $cursor->sort($this->sort)->skip($skip)->limit($size);
		$ret = array();
		foreach ($resource as $item) {
			$item->collection($this->lines_coll);
			if ($arate = $this->getDBRefField($item, 'arate')) {
				$item['arate'] = $arate['key'];
				$item['arate_id'] = strval($arate['_id']);
			} else {
				$item['arate'] = $arate;
			}
			$ret[] = $item;
		}
		return $ret;
	}

	public function getTableColumns() {
		$columns = array(
			'type' => 'Type',
			'aid' => 'Account id',
			'sid' => 'Subscriber id',
			'calling_number' => 'Calling Number',
			'called_number' => 'Called Number',
			'plan' => 'Plan',
			'usaget' => 'Usage type',
			'usagev' => 'Usage volume',
			'arate' => 'Rate',
			'aprice' => 'Charge',
			'billrun' => 'Billrun',
			'urt' => 'Time',
		);
		if (!empty($this->extra_columns)) {
			$extra_columns = array_intersect_key($this->getExtraColumns(), array_fill_keys($this->extra_columns, ""));
			$columns = array_merge($columns, $extra_columns);
		}
		return $columns;
	}

	public function toolbar() {
		return 'events';
	}

	public function getFilterFields() {
		$months = 6;
		$previous_billruns = array();
		$timestamp = time();
		for ($i = 1; $i <= $months; $i++) {
			$timestamp = strtotime("1 month ago", $timestamp);
			$billrun_key = Billrun_Util::getBillrunKey($timestamp);
			if ($billrun_key >= '201401') {
				$previous_billruns[$billrun_key] = $billrun_key;
			}
		}
		arsort($previous_billruns);
		$current_billrun_key = '000000';
		$current_billrun = array($current_billrun_key => 'Current billrun');
		$billruns = $current_billrun + $previous_billruns;

		$filter_fields = array(
			'aid' => array(
				'key' => 'aid',
				'db_key' => 'aid',
				'input_type' => 'number',
				'comparison' => 'equals',
				'display' => 'Account id',
				'default' => '',
			),
			'sid' => array(
				'key' => 'sid',
				'db_key' => 'sid',
				'input_type' => 'number',
				'comparison' => 'equals',
				'display' => 'Subscriber id',
				'default' => '',
			),
			'from' => array(
				'key' => 'from',
				'db_key' => 'urt',
				'input_type' => 'date',
				'comparison' => '$gte',
				'display' => 'From',
				'default' => (new Zend_Date(strtotime('2013-01-01'), null, new Zend_Locale('he_IL')))->toString('YYYY-MM-dd HH:mm:ss'),
			),
			'to' => array(
				'key' => 'to',
				'db_key' => 'urt',
				'input_type' => 'date',
				'comparison' => '$lte',
				'display' => 'To',
				'default' => (new Zend_Date(strtotime("next month"), null, new Zend_Locale('he_IL')))->toString('YYYY-MM-dd HH:mm:ss'),
			),
			'usage' => array(
				'key' => 'usage',
				'db_key' => 'usaget',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'display' => 'Usage',
				'values' => Billrun_Factory::config()->getConfigValue('admin_panel.line_usages'),
				'default' => array(),
			),
			'billrun' => array(
				'key' => 'billrun',
				'db_key' => 'billrun',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'display' => 'Billrun',
				'values' => $billruns,
				'default' => $current_billrun_key,
			),
		);
		return array_merge($filter_fields, parent::getFilterFields());
	}

	public function applyFilter($filter_field, $value) {
		if ($filter_field['comparison'] == 'special') {
			if ($filter_field['input_type'] == 'boolean') {
				if (!is_null($value) && $value != $filter_field['default']) {
					$rates_coll = Billrun_Factory::db()->ratesCollection();
					$unrated_rate = $rates_coll->query("key", "UNRATED")->cursor()->setReadPreference(Billrun_Factory::config()->getConfigValue('read_only_db_pref'))->current()->createRef($rates_coll);
					$month_ago = new MongoDate(strtotime("1 month ago"));
					return array(
						'$or' => array(
							array('arate' => $unrated_rate), // customer rate is "UNRATED"
							array('sid' => false), // or subscriber not found
							array('$and' => array(// old unpriced records which should've been priced
									array('arate' => array(
											'$exists' => true,
											'$nin' => array(
												false, $unrated_rate
											),
										)),
									array('sid' => array(
											'$exists' => true,
											'$ne' => false,
										)),
									array('urt' => array(
											'$lt' => $month_ago
										)),
									array('aprice' => array(
											'$exists' => false
										)),
								)),
					));
				}
			}
		} else {
			return parent::applyFilter($filter_field, $value);
		}
	}

	public function getFilterFieldsOrder() {
		$filter_field_order = array(
			0 => array(
				'aid' => array(
					'width' => 2,
				),
				'sid' => array(
					'width' => 2,
				),
				'from' => array(
					'width' => 2,
				),
				'to' => array(
					'width' => 2,
				),
			),
			1 => array(
				'usage' => array(
					'width' => 2,
				),
				'billrun' => array(
					'width' => 2,
				),
			),
		);
		return $filter_field_order;
	}

	public function getSortFields() {
		return array(
			'aid' => 'Account id',
			'billrun_key' => 'Billrun',
			'aprice' => 'Charge',
			'plan' => 'Plan',
			'sid' => 'Subscriber id',
			'urt' => 'Time',
			'type' => 'Type',
			'usaget' => 'Usage type',
			'usagev' => 'Usage volume',
		);
	}

}
