<?php defined('SYSPATH') or die('No direct script access.');

class Http_Exception_400 extends Kohana_Http_Exception_400 {

	/**
	 * @var   integer    HTTP 400 Bad Request
	 */
	protected $_code = 400;

}