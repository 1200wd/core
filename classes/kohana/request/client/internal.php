<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Request Client for internal execution
 *
 * @package    Kohana
 * @category   Base
 * @author     Kohana Team
 * @copyright  (c) 2008-2010 Kohana Team
 * @license    http://kohanaframework.org/license
 * @since      3.1.0
 */
class Kohana_Request_Client_Internal extends Request_Client {

	/**
	 * @var    array
	 */
	protected $_previous_environment;

	/**
	 * Processes the request, executing the controller action that handles this
	 * request, determined by the [Route].
	 *
	 * 1. Before the controller action is called, the [Controller::before] method
	 * will be called.
	 * 2. Next the controller action will be called.
	 * 3. After the controller action is called, the [Controller::after] method
	 * will be called.
	 *
	 * By default, the output from the controller is captured and returned, and
	 * no headers are sent.
	 *
	 *     $request->execute();
	 *
	 * @param   Request $request
	 * @return  Response
	 * @throws  Kohana_Exception
	 * @uses    [Kohana::$profiling]
	 * @uses    [Profiler]
	 */
	public function execute(Request $request)
	{
		// Check for cache existance
		if ($this->_cache instanceof Cache AND ($response = $this->_cache->cache_response($request)) instanceof Response)
			return $response;

		// Create the class prefix
		$prefix = 'controller_';

		// Directory
		$directory = $request->directory();

		// Controller
		$controller = $request->controller();

		// Action
		$action = $request->action();

		if ($directory)
		{
			// Add the directory name to the class prefix
			$prefix .= str_replace(array('\\', '/'), '_', trim($directory, '/')).'_';
		}

		if (Kohana::$profiling)
		{
			$uri = $request->uri();
			// Set the benchmark name
			$benchmark = '"'.$uri.'"';

			if ($request !== Request::$initial AND Request::$current)
			{
				// Add the parent request uri
				$benchmark .= ' « "'.$uri.'"';
			}

			// Start benchmarking
			$benchmark = Profiler::start('Requests', $benchmark);
		}

		// Store the currently active request
		$previous = Request::$current;

		// Change the current request to this request
		Request::$current = $request;

		// Is this the initial request
		$initial_request = ($request === Request::$initial);

		try
		{
			// Load the controller using reflection
			$class = new ReflectionClass($prefix.$controller);

			if ($class->isAbstract())
			{
				throw new Kohana_Exception('Cannot create instances of abstract :controller',
					array(':controller' => $prefix.$controller));
			}

			// Create a new instance of the controller
			$controller = $class->newInstance($request, $request->create_response());

			// Determine the action to use
			$action = empty($action) ? Route::$default_action : $action;

			// Get all the method objects before invoking them
			$before = $class->getMethod('before');
			$after  = $class->getMethod('after');

			$params = $request->param();
			$method = $class->getMethod('action_'.$action);

		}
		catch (Exception $e)
		{
			// Restore the previous request
			Request::$current = $previous;

			if (isset($benchmark))
			{
				// Delete the benchmark, it is invalid
				Profiler::delete($benchmark);
			}

			if ($e instanceof ReflectionException)
			{
				$response = $request->create_response();

				// Reflection will throw exceptions for missing classes or actions
				$response->status(404);
			}

			// Re-throw the exception
			throw $e;
		}

		try
		{
			if ( ! $initial_request)
			{
				$this->_init_environment($request);
			}

			// Initiate response time
			$this->_response_time = time();

			// Execute the "before action" method
			$before->invoke($controller);

			// Execute the main action with the parameters
			$method->invokeArgs($controller, $params);

			// Execute the "after action" method
			$after->invoke($controller);

			// Stop response time
			$this->_response_time = (time() - $this->_response_time);

			// Add the default Content-Type header to initial request if not present
			if ($initial_request AND ! $request->headers('content-type'))
			{
				$request->headers('content-type', Kohana::$content_type.'; charset='.Kohana::$charset);
			}

			if ( ! $initial_request)
			{
				$this->_deinit_environment();
			}
		}
		catch (Exception $e)
		{
			if ( ! $initial_request)
			{
				$this->_deinit_environment();
			}

			// All other exceptions are PHP/server errors
			$response = $request->create_response();
			$response->status(500);

			throw $e;
		}

		// Restore the previous request
		Request::$current = $previous;

		if (isset($benchmark))
		{
			// Stop the benchmark
			Profiler::stop($benchmark);
		}

		// Cache the response if cache is available
		if ($this->_cache instanceof Cache)
		{
			$this->cache_response($request, $request->response());
		}

		// Return the response
		return $request->response();
	}

	/**
	 * Initialises the server environment variables
	 * for this request execution.
	 *
	 * - Stores _GET, _POST and select _SERVER vars
	 * - Replaces _GET, _POST and select _SERVER vars
	 *
	 * @param   Kohana_Request  request
	 * @return  void
	 */
	protected function _init_environment(Kohana_Request $request)
	{
		// Store existing $_GET, $_POST, $_SERVER vars
		$this->_previous_environment = array(
			'_GET'    => $_GET,
			'_POST'   => $_POST,
			'_SERVER' => $_SERVER,
		);

		// Assign this requests values to globals
		$_GET = $request->query();
		$_POST = $request->post();

		$query_strings = array();

		if ($_GET)
		{
			foreach ($_GET as $key => $val)
			{
				$query_strings[] = $key.'='.urlencode($val);
			}
		}

		// Get argc number
		$_argc = $query_strings ? 1 : 0;

		// Create the full query string
		$query_string = implode('&', $query_strings);

		$uri = $request->uri();

		// Augment the existing $_SERVER
		$_request_server = array(
			'QUERY_STRING'     => $query_string,
			'argv'             => $query_string,
			'argc'             => $_argc,
			'REQUEST_METHOD'   => $request->method(),
			'SCRIPT_NAME'      => '/'.Kohana::$index_file.'/'.$uri,
			'REQUEST_URI'      => '/'.$request->uri,
			'DOCUMENT_URI'     => '/'.Kohana::$index_file.'/'.$uri,
			'REQUEST_TIME'     => time(),
			'PHP_SELF'         => '/'.Kohana::$index_file.'/'.$uri,
		);

		// Add the request headers to replacement server vars
		foreach ($request->header as $key => $value)
		{
			$_request_server[('HTTP_'.strtoupper((str_replace('-', '_', $key))))] = $value;
		}

		// Replace global $_SERVER with request server var
		$_SERVER = $_request_server;
	}

	/**
	 * Returns the server environment variables
	 * to their initial state
	 *
	 * @return void
	 */
	protected function _deinit_environment()
	{
		// Exit now if environment is initialised already
		if ( ! $this->_previous_environment)
			return;

		// Restore globals
		$_GET = $this->_previous_environment['_GET'];
		$_POST = $this->_previous_environment['_POST'];
		$_SERVER = $this->_previous_environment['_SERVER'];

		// Reset the previous environment
		$this->_previous_environment = FALSE;
	}

} // End Kohana_Request_Client_Internal