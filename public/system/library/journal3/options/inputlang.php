<?php

namespace Journal3\Options;

use Journal3\Utils\Arr;

class InputLang extends Option {

	protected static function parseValue($value, $data = null) {
		if (is_scalar($value)) {
			return $value;
		}

		$result = Arr::get($value, 'lang_' . $data['config']['language_id'], '');

		if (strlen($result) > 0) {
			return $result;
		}

		$result = Arr::get($value, 'lang_' . $data['config']['default_language_id'], '');

		if (strlen($result) > 0) {
			return $result;
		}

		return Arr::get($value, 'lang_1', '');
	}

}
