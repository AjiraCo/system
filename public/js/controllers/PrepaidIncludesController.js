angular
		.module('BillrunApp')
		.controller('PrepaidIncludesController', PrepaidIncludesController);

function PrepaidIncludesController(Database, Utils, $http, $timeout, $rootScope) {
	'use strict';
	var vm = this;
	vm.edit_mode = false;
	vm.newent = false;

	angular.element('.active').removeClass('active');
	angular.element('.menu-item-pp_includes').addClass('active');

	vm.newPPInclude = function () {
		vm.edit_mode = true;
		vm.newent = true;
		vm.current_entity = {
			name: "",
			id: undefined,
			charging_by: "",
			charging_by_usaget: "",
			priority: 0,
			from: new Date(),
			to: new Date("2099-12-31")
		};
	};
	
	vm.initMultiSelectData = function() {
		vm.availableRatesToDisplay = {};
		vm.allowed_in = {};
		_.forEach(vm.availablePlans, function (planName) {
			vm.allowed_in[planName] = [];
			var availableRatesForMultiSelect = [];
			_.forEach(vm.availableRates, function (rateName) {
				availableRatesForMultiSelect.push({name: rateName, ticked: false});
			});
			vm.availableRatesToDisplay[planName] = availableRatesForMultiSelect;
		});
		
		_.forEach(vm.current_entity.allowed_in, function (allowedIn, planName) {
			_.forEach(allowedIn, function (allowed) {
				_.forEach(vm.availableRatesToDisplay[planName], function(availableRate) {
					if (availableRate.name === allowed) {
						availableRate.ticked = true;
						return;
					}
				});
			});
		});
		vm.initAdditionalChargingUsaget();
	};

	vm.edit = function (external_id) {
		$rootScope.spinner++;
		vm.current_entity = _.find(vm.pp_includes, function (e) {
			return e.external_id === external_id;
		});
//    vm.allowed_in = {};
//    _.forEach(vm.current_entity.allowed_in, function (a, p) {
//      vm.allowed_in[p] = [];
//      _.forEach(a, function (r) {
//        vm.allowed_in[p].push({key: r, ticked: true});
//      });
//    });
		vm.edit_mode = true;
		vm.newent = false;
		$timeout(function () {
			vm.initMultiSelectData();
			$rootScope.spinner--;
		}, 0);
	};

	vm.cancel = function () {
		vm.edit_mode = false;
	};
	
	vm.setAllowedIn = function() {
		_.forEach(vm.allowed_in, function(allowedIn, planName) {
			vm.current_entity.allowed_in[planName] = [];
			_.forEach(allowedIn, function(allowedRate) {
				if (allowedRate.ticked) {
					vm.current_entity.allowed_in[planName].push(allowedRate.name);
				}
			});
			if (vm.current_entity.allowed_in[planName].length === 0) {
				delete vm.current_entity.allowed_in[planName];
			}
		});
	};
	
	vm.setAdditionalChargingUsaget = function() {
		vm.current_entity.additional_charging_usaget = [];
		_.forEach(vm.additional_charging_usaget, function(additional) {
			if (additional.ticked) {
				vm.current_entity.additional_charging_usaget.push(additional.name);
			}
		});
	};
	
	vm.initAdditionalChargingUsaget = function() {
		vm.additional_charging_usaget = [];
		vm.available_additional_charging_usaget = [];
		var available_additional = [
			"call",
			"data",
			"sms",
			"incoming_call",
			"video_call",
			"roaming_incoming_call",
			"roaming_call",
			"roaming_callback",
			"roaming_callback_short",
			"forward_call"
		];
		_.forEach(available_additional, function(additional) {
			vm.available_additional_charging_usaget.push({name: additional, ticked: !_.isEmpty(vm.current_entity.additional_charging_usaget) && vm.current_entity.additional_charging_usaget.indexOf(additional) > -1});
		});
	};
	
	vm.save = function () {
		vm.setAllowedIn();
		vm.setAdditionalChargingUsaget();
		$http.post(baseUrl + '/admin/savePPIncludes', {data: vm.current_entity, new_entity: vm.newent}).then(function (res) {
			if (vm.newent)
				vm.init();
			vm.edit_mode = false;
		});
	};

	vm.addAllowedPlan = function () {
		if (!vm.selected_allowed_plan)
			return;
		if (_.isUndefined(vm.current_entity.allowed_in))
			vm.current_entity.allowed_in = {};
		vm.current_entity.allowed_in[vm.selected_allowed_plan] = {};
		$rootScope.spinner++;
		$timeout(function () {
			$('#' + vm.selected_allowed_plan + '-select').multiselect({
				maxHeight: 250,
				enableFiltering: true,
				enableCaseInsensitiveFiltering: true,
				includeSelectAllOption: true,
				selectAllValue: 'all',
				selectedClass: null
			});
			$rootScope.spinner--;
			vm.selected_allowed_plan = "";
		}, 0);
	};

	vm.init = function () {
		vm.availableChargingBy = [
			"total_cost",
			"cost",
			"usagev"
		];
		vm.availableChargingByType = [
			"total_cost",
			"call",
			"data",
			"sms"
		];
		Database.getAvailablePlans("customer", true).then(function (res) {
			vm.availablePlans = res.data;
		});
		Database.getAvailableRates().then(function (res) {
			vm.availableRates = res.data;
		});
		Database.getAvailablePPIncludes({full_objects: true}).then(function (res) {
			vm.pp_includes = res.data.ppincludes;
			vm.authorized_write = res.data.authorized_write;
			var format = Utils.getDateFormat() + " HH:MM:SS";
			_.forEach(vm.pp_includes, function (pp_include) {
				pp_include.from = moment(pp_include.from.sec * 1000).format(format.toUpperCase());
				pp_include.to = moment(pp_include.to.sec * 1000).format(format.toUpperCase());
			});
		});
	};
}