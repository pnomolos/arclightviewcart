<?php
	if (!class_exists('GeoIpLookup')) {
		require_once dirname(__FILE__) . '/../thirdparty/geoip/geoiplookup.php';
	}
	
	class ArclightViewCart_ViewCart extends Backend_Controller {
		public $implement = 'Db_ListBehavior, Db_FormBehavior, Db_FilterBehavior';
		public $list_model_class = 'ArclightViewCart_FakeCart';
		public $list_record_url = null;

		public $form_model_class = 'ArclightViewCart_FakeCart';
		public $form_redirect = null;

		public $form_unique_prefix = null;
		
		protected $globalHandlers = array(
			'onLoadSessionCart',
		);
		
		public function __construct() {
			parent::__construct();
			$this->app_module_name = 'View Cart';
			$this->app_tab = 'arclightviewcart';
			$this->app_page = 'viewcart';
		}
		
		public function index() {
			// Here we will show the basic manager
			$this->app_page_title = 'Carts';
			
			$session_carts = array();
			$ids_to_ips = array();
			
			$helper = new ArclightViewCart_Helper();
			$session_carts = $helper->get_carts();
			
			$this->viewData['session_carts'] = $session_carts;
		}
		
		protected function onLoadSessionCart() {
			$helper = new ArclightViewCart_Helper();
			
			$cart = null;
			if (post('customer_id')) {
				$cart = $helper->get_customer_cart(post('customer_id'));
			} else if (post('session_id')) {
				$cart = $helper->get_session_cart(post('session_id'));
			}
			$this->viewData['session_cart'] = $cart;
			$this->renderPartial('session_cart');
		}
	}
