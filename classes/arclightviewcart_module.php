<?php
	// Copyright 2012 by Philip Schalm
	// This product includes GeoLite data created by MaxMind, available from http://www.maxmind.com
	class ArclightViewCart_Module extends Core_ModuleBase
	{
		/**
		 * Creates the module information object
		 * @return Core_ModuleInfo
		 */

		protected function createModuleInfo()
		{
			return new Core_ModuleInfo(
				"View Cart",
				"View the contents of shopping carts",
				"Arclight Industries" );
		}

		public function subscribeEvents() {
			Backend::$events->addEvent('cms:onAfterDisplay', $this, 'register_session');
			Backend::$events->addEvent('cms:onAfterHandleAjax', $this, 'register_session');
			Backend::$events->addEvent('shop:onAfterAddToCart', $this, 'register_session');

			Backend::$events->addEvent('onFrontEndLogin', $this, 'deregister_session');
		}

		public function register_session() {
			// This *will* run multiple times during a session.  Unfortunately, until
			// core:onUninitialize works properly it has to be done
			$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
			$_SESSION['customer_id'] = Phpr::$frontend_security->getUser() ? Phpr::$frontend_security->getUser()->id : null;
			ArclightViewCart_Helper::register_session();
		}

		public function deregister_session() {
			$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
			$_SESSION['customer_id'] = Phpr::$frontend_security->getUser() ? Phpr::$frontend_security->getUser()->id : null;
			ArclightViewCart_Helper::deregister_session();
		}

		public function listTabs($tabCollection)
		{
			$menu_item = $tabCollection->tab('arclightviewcart', 'View Carts', 'viewcart', 12);
		}

		public function listSettingsForms()
		{
			return array(
				'settings'=>array(
				'icon'=>'/modules/shop/resources/images/shop_configuration.png',
				'title'=>'View Cart settings',
				'description'=>'Set time to view carts and other settings',
				'sort_id'=>100,
				'section'=>'Miscellaneous'
				),
			);
		}

		public function initSettingsData($model, $form_code)
		{
			$model->session_hours = 24;
			$model->customer_hours = 24;
			$model->use_db_storage = 1;
		}

		public function buildSettingsForm($model, $form_code)
		{
			$model->add_field('session_hours', 'Show guest carts up to x hours ago', 'left', db_number);
			$model->add_field('customer_hours', 'Show registered carts up to x hours ago', 'right', db_number);

			$field = $model->add_field('use_db_storage', 'Store session IDs in database', 'left', db_number)->renderAs(frm_checkbox);

			$readable = false;
			try {
				$readable = is_readable(ArclightViewCart_Helper::get_session_directory());
			} catch (Exception $e) {}
			if (!$readable) {
				$field->comment('<strong>Your session directory is unreadable, so you should check this off</strong>', 'above', true);
			} else {
				$field->comment("You don't need to do this, but if you host many other sites on this server it may increase performance");
			}
		}
	}
