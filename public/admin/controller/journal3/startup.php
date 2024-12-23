<?php

use Journal3\Opencart\Controller;
use Journal3\Utils\Arr;

class ControllerJournal3Startup extends Controller {

	public function index() {
		define('JOURNAL3_ADMIN', true);

		if ((VERSION === '3.0.3.9') && function_exists('oc_strtoupper')) {
			define('JOURNAL3_OLD_OC3039', true);
		}

		$this->registry->set('journal3', new Journal3($this->registry));
	}

}
