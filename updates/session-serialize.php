<?
	$settings = Core_ModuleSettings::create('arclightviewcart', 'settings');
	if ($settings->use_db_storage) {
		$old_session = $_SESSION;
		foreach (Db_DbHelper::objectArray('SELECT * FROM `arclightviewcart_sessions`') as $session) {
			$file = ArclightViewCart_Helper::get_session_directory() . '/sess' . $session->session_id;
			if (is_readable($file) && session_decode(file_get_contents($file)) && count($_SESSION)) {
				if (isset($_SESSION['phpr_session_host']) && str_replace('www.', '', $_SESSION['phpr_session_host']) == str_replace('www.', '', $_SERVER['SERVER_NAME'])) {
					Db_DbHelper::query('UPDATE `arclightviewcart_sessions` SET session_data = ? WHERE session_id = ?', array(
						serialize($_SESSION, $session->session_id)
					));
				}
			}
		}
	}