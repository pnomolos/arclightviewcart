<?php
	class ArclightViewCart_Helper {
		public $settings = null;

		public function __construct() {
			$this->settings = Core_ModuleSettings::create('arclightviewcart', 'settings');
		}

		public function get_carts() {
			$session_carts = array();
			foreach ($this->getSessions() as $session_id => $data) {
				$args = array(
					'information' => $this->create_information($data),
					'items' => $this->create_cart_items(isset($data['shop_in_memory_cart_items']['main']) ? $data['shop_in_memory_cart_items']['main'] : array()),
					'cart_total' => 0,
					'item_count' => 0,
					'session_id' => $session_id,
					'customer_id' => null,
					'customer_ip' => (isset($data['ip']) ? $data['ip'] : null),
				);

				if (isset($data['customer_id']) && is_numeric($data['customer_id'])) {
					$ids_to_ips[$data['customer_id']] = isset($data['ip']) ? $data['ip'] : null;
				}

				foreach ($args['items'] as $item) {
					$args['cart_total'] += $item->total_price();
					$args['item_count'] += $item->quantity;
				}
				if ($args['item_count']) {
					$session_carts[] = (object)$args;
				}
			}
			foreach (
				Db_DbHelper::scalarArray(
					'SELECT customer_id FROM shop_customer_cart_items
						WHERE
							(postponed IS NULL OR postponed = 0) AND created_at > (NOW() - INTERVAL ' . $this->settings->customer_hours . ' HOUR)
						GROUP BY customer_id
					')
				as $customer_id
				) {
				$customer = Shop_Customer::create()->find($customer_id);
				if ($customer) {
					$cart_items = Shop_CustomerCartItem::create()->where('customer_id = ? AND (postponed IS NULL OR postponed = 0) AND cart_name LIKE \'main\'', array($customer->id))->find_all();
					$args = array(
						'information' => $this->create_information($customer),
						'items' => $this->create_cart_items($cart_items),
						'cart_total' => 0,
						'item_count' => 0,
						'session_id' => null,
						'customer_id' => $customer->id,
						'customer_ip' => (isset($ids_to_ips[$customer->id]) ? $ids_to_ips[$customer->id] : null),
					);
					foreach ($args['items'] as $item) {
						$args['cart_total'] += $item->total_price();
						$args['item_count'] += $item->quantity;
					}
					if ($args['item_count']) {
						$session_carts[] = (object)$args;
					}
				}
			}

			$lookup = new GeoIpLookup();
			foreach ($session_carts as $cart) {
				$cart->customer_country = $lookup->lookup_country_name($cart->customer_ip);
			}

			usort($session_carts, array($this, 'sortSessionCarts'));
			return $session_carts;
		}

		public function get_customer_cart($customer_id = null) {
			$customer = Shop_Customer::create()->where('id = ?', array($customer_id))->find();
			if (!$customer) {
				return null;
			}

			$cart_items = Shop_CustomerCartItem::create()->where('customer_id = ? AND (postponed IS NULL OR postponed = 0) AND cart_name LIKE \'main\'', array($customer->id))->find_all();
			return $this->create_cart_object(array(
				'items' => $cart_items,
				'information' => $this->create_information($customer),
			));
		}

		public function get_session_cart($session_id = null) {
			if (!$session_id) {
				return null;
			}

			$session = $this->getSession($session_id);
			$cart_items = isset($session['shop_in_memory_cart_items']['main']) ? $session['shop_in_memory_cart_items']['main'] : array();
			return $this->create_cart_object(array(
				'items' => $cart_items,
				'information' => $this->create_information($session)
			));
		}

		protected function create_cart_object(array $options = array()) {
			$options = array_merge(array(
				'items' => array(),
				'information' => (object)array('name' => 'DEFAULT')
			), $options);
			$options['items'] = $this->create_cart_items($options['items']);
			return (object)$options;
		}

		protected function create_cart_items($items = array()) {
			$result = array();

			// Load all the extra options
			$product_ids = array();
			foreach ($items as $item) {
				$product_ids[] = $item->product_id;
			}

			$extra_options = array();
			if (count($product_ids)) {
				$extra_options = new Shop_ExtraOption(null, array('no_timestamps'=>true));
				$extra_options->where('(product_id in (?) and (option_in_set is null or option_in_set <> 1))', array($product_ids));
				$extra_options->orWhere('exists(select * from shop_products_extra_sets where extra_option_set_id=shop_extra_options.product_id and extra_product_id in (?))', array($product_ids, $product_ids));
				$extra_options = $extra_options->find_all();
			}

			$extra_option_list = array();
			$global_extra_option_list = array();
			foreach ($extra_options as $extra_option)
			{
				if ($extra_option->option_in_set)
					$global_extra_option_list[$extra_option->option_key] = $extra_option;
				else
					$extra_option_list[$extra_option->option_key.'|'.$extra_option->product_id] = $extra_option;
			}
			$extra_options = $extra_option_list;

			foreach ($items as $item) {
				if (!$item->product_id) {
					continue;
				}
				$cart_item = new Shop_CartItem();
				foreach ($item->options as $key => $val) {
					$sca = Shop_CustomAttribute::create()->where('option_key = :key', array('key' => $key))->find();
					if ($sca) {
						$cart_item->options[$sca->name] = $val;
					}
				}

				// Extras need to be handled specially
				foreach ($item->extras as $key=>$value)
				{
					$product_option_key = $key.'|'.$item->product_id;

					if (!array_key_exists($product_option_key, $extra_options) && !array_key_exists($key, $global_extra_option_list))
					{
						continue 2;
					}

					if (array_key_exists($product_option_key, $extra_options))
						$cart_item->extra_options[] = $extra_options[$product_option_key];
					else
						$cart_item->extra_options[] = $global_extra_option_list[$key];
				}

				$extra_option_keys = array();
				foreach ($cart_item->extra_options as $ex) {
					$extra_option_keys[] = $ex->option_key;
				}

				$cart_item->key = Shop_InMemoryCartItem::gen_item_key(
					$item->product_id,
					$item->options,
					$extra_option_keys,
					array(),
					null);
				$cart_item->product = Shop_Product::create()->find($item->product_id);
				$cart_item->quantity = $item->quantity;
				if ($cart_item->om('is_on_sale')) {
					$cart_item->price_preset = $cart_item->om('sale_price');
				} else {
					$cart_item->price_preset = $cart_item->om('price');
				}

				$result[] = $cart_item;
			}
			return $result;
		}

		protected function create_information_from_session($session_data = array()) {
			$billing_info = null;
			$shipping_info = null;

			if (isset($shipping_info['shop_checkout_data'])) {
				$info = $shipping_info['shop_checkout_data'];
				if (isset($info['billing_info'])) {
					$billing_info = $info['billing_info'];
				}
				if (isset($info['shipping_info'])) {
					$shipping_info = $info['shipping_info'];
				}
			}

			$name = $billing_info ? "{$billing_info->first_name} {$billing_info->last_name}" : '-- GUEST --';
			$object = array(
				'name' => $name,
				'billing_info' => $billing_info,
				'shipping_info' => $shipping_info
			);

			return (object)$object;
		}

		protected function create_information_from_customer($customer = null) {
			if (!$customer) {
				return null;
			}
			$shipping_info = new Shop_CheckoutAddressInfo();
			$shipping_info->act_as_billing_info = false;
			$billing_info = new Shop_CheckoutAddressInfo();
			$billing_info->act_as_billing_info = true;

			$shipping_info->load_from_customer($customer);
			$billing_info->load_from_customer($customer);

			$object = array(
				'name' => "{$customer->first_name} {$customer->last_name}",
				'billing_info' => $billing_info,
				'shipping_info' => $shipping_info
			);

			return (object)$object;
		}

		protected function create_information($obj) {
			if (is_array($obj)) {
				return $this->create_information_from_session($obj);
			} else if (is_object($obj)) {
				return $this->create_information_from_customer($obj);
			}
			return null;
		}

		private function getSession($_session_id) {
			$sessions = $this->getSessions($_session_id);
			foreach ($sessions as $session_id => $session) {
				if ($session_id == $_session_id) {
					return $session;
				}
			}
			return null;
		}

		private function getSessions($session_id = null) {
			$sessions = array();
			$old_session = $_SESSION;
			$path = session_save_path() ? session_save_path() : sys_get_temp_dir();
			if ($this->settings->use_db_storage) {
				$date_time = new Phpr_DateTime();
				$date_time = $date_time->addHours(-($this->settings->session_hours));
				foreach(Db_DbHelper::objectArray(
					'SELECT session_id, session_data FROM `arclightviewcart_sessions` WHERE updated_at > ?',
					array($date_time->format(Phpr_DateTime::universalDateTimeFormat))
				) as $session) {
					if ($session->session_data) {
						$sessions[$session->session_id] = unserialize($session->session_data);
					}
				}
			} else {
				foreach (glob($path.'/sess_' . ($session_id ? $session_id : '*')) as $file) {
					if (filemtime($file) < (time() - $this->settings->session_hours*60*60)) {
						continue;
					}
					$sessions[str_replace('sess_', '', pathinfo($file, PATHINFO_BASENAME))] = true;
				}
			}

			foreach ($sessions as $id => $value) {
				if ($value !== true) {
					continue;
				}
				$file = $path . '/sess_'  . $id;
				$_SESSION = array();
				if (session_decode(file_get_contents($file)) && count($_SESSION)) {
					if (isset($_SESSION['phpr_session_host']) && str_replace('www.', '', $_SESSION['phpr_session_host']) == str_replace('www.', '', $_SERVER['SERVER_NAME'])) {
						$sessions[$id] = $_SESSION;
					} else {
						$sessions[$id] = null;
					}
				}
			}
			$_SESSION = $old_session;
			$sessions = array_filter($sessions);
			return $sessions;
		}

		public function sortSessionCarts($a, $b) {
			return (int)($a->cart_total < $b->cart_total);
		}

		public static function get_session_directory() {
			return session_save_path() ? session_save_path() : sys_get_temp_dir();
		}

		public static function register_session() {
			$instance = new self();
			if ($instance->settings->use_db_storage && isset($_SESSION['shop_in_memory_cart_items'])) {
				Db_DbHelper::query(
					'INSERT INTO `arclightviewcart_sessions`(session_id, updated_at, session_data) VALUES(?,?,?) ON
					DUPLICATE KEY UPDATE updated_at = ?, session_data = ?',array(
					session_id(),
					Phpr_DateTime::now()->format(Phpr_DateTime::universalDateTimeFormat),
					serialize($_SESSION),
					Phpr_DateTime::now()->format(Phpr_DateTime::universalDateTimeFormat),
					serialize($_SESSION)
				));
			}
		}

		public static function deregister_session() {
			$instance = new self();
			if ($instance->settings->use_db_storage) {
				Db_DbHelper::query('DELETE FROM `arclightviewcart_sessions` WHERE `session_id` = ?', array(
					session_id()
				));
			}
		}
	}
