<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Abstract Controller class for RESTful controller mapping.
 *
 * @package    Kohana
 * @category   Controller
 * @author     Kohana Team
 * @copyright  (c) 2009 Kohana Team
 * @license    http://kohanaphp.com/license
 */
abstract class Kohana_Controller_REST extends Controller {

	protected $_action_map = array
	(
		'GET'    => 'index',
		'PUT'    => 'update',
		'POST'   => 'create',
		'DELETE' => 'delete',
	);

	protected $_action_requested = '';

	public function before()
	{
		$this->_action_requested = $this->request->action;

		if ( ! isset($this->_action_map[$this->request->method]))
		{
			$this->request->action = 'invalid';
		}
		else
		{
			$this->request->action = $this->_action_map[$this->request->method];
		}

		return parent::before();
	}

	public function action_invalid()
	{
		// Send the "Method Not Allowed" response
		$this->response->status = 405;
		$this->response->headers['Allow'] = implode(', ', array_keys($this->_action_map));
	}

} // End REST
