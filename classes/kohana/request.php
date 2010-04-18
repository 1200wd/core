<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Request and response wrapper.
 * 
 * Request and response wrapper. Uses the [Route] class to determine what
 * [Controller] to send the request to.
 *
 * @package    Kohana
 * @category   Base
 * @author     Kohana Team
 * @copyright  (c) 2008-2009 Kohana Team
 * @license    http://kohanaphp.com/license
 */
class Kohana_Request {

	/**
	 * @var  string  protocol: http, https, ftp, cli, etc
	 */
	public static $protocol = 'http';

	/**
	 * @var  string  referring URL
	 */
	public static $referrer;

	/**
	 * @var  string  client user agent
	 */
	public static $user_agent = '';

	/**
	 * @var  string  client IP address
	 */
	public static $client_ip = '0.0.0.0';

	/**
	 * Main request singleton instance. If no URI is provided, the URI will
	 * be automatically detected using PATH_INFO, REQUEST_URI, or PHP_SELF.
	 *
	 *     $request = Request::instance();
	 *
	 * @param   string   URI of the request
	 * @return  Request
	 */
	public static function instance( & $uri = TRUE)
	{
		static $instance;

		if ($instance === NULL)
		{
			if (Kohana::$is_cli)
			{
				// Default protocol for command line is cli://
				Request::$protocol = 'cli';

				// Get the command line options
				$options = CLI::options('uri', 'method', 'get', 'post');

				if (isset($options['uri']))
				{
					// Use the specified URI
					$uri = $options['uri'];
				}

				if (isset($options['method']))
				{
					// Use the specified method
					$config['method'] = strtoupper($options['method']);
				}

				if (isset($options['get']))
				{
					// Overload the global GET data
					parse_str($options['get'], $_GET);
				}

				if (isset($options['post']))
				{
					// Overload the global POST data
					parse_str($options['post'], $_POST);
				}
			}
			else
			{
				if (isset($_SERVER['REQUEST_METHOD']))
				{
					// Use the server request method
					$config['method'] = $_SERVER['REQUEST_METHOD'];
				}

				if ( ! empty($_SERVER['HTTPS']) AND filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN))
				{
					// This request is secure
					Request::$protocol = 'https';
				}

				if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) AND strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
				{
					// This request is an AJAX request
					$config['is_ajax'] = TRUE;
				}

				if (isset($_SERVER['HTTP_REFERER']))
				{
					// There is a referrer for this request
					Request::$referrer = $_SERVER['HTTP_REFERER'];
				}

				if (isset($_SERVER['HTTP_USER_AGENT']))
				{
					// Set the client user agent
					Request::$user_agent = $_SERVER['HTTP_USER_AGENT'];
				}

				if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
				{
					// Use the forwarded IP address, typically set when the
					// client is using a proxy server.
					Request::$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
				}
				elseif (isset($_SERVER['HTTP_CLIENT_IP']))
				{
					// Use the forwarded IP address, typically set when the
					// client is using a proxy server.
					Request::$client_ip = $_SERVER['HTTP_CLIENT_IP'];
				}
				elseif (isset($_SERVER['REMOTE_ADDR']))
				{
					// The remote IP address
					Request::$client_ip = $_SERVER['REMOTE_ADDR'];
				}

				if ($config['method'] !== 'GET' AND $config['method'] !== 'POST')
				{
					// Methods besides GET and POST do not properly parse the form-encoded
					// query string into the $_POST array, so we overload it manually.
					parse_str(file_get_contents('php://input'), $_POST);
				}

				if ($uri === TRUE)
				{
					if ( ! empty($_SERVER['PATH_INFO']))
					{
						// PATH_INFO does not contain the docroot or index
						$uri = $_SERVER['PATH_INFO'];
					}
					else
					{
						// REQUEST_URI and PHP_SELF include the docroot and index

						if (isset($_SERVER['REQUEST_URI']))
						{
							// REQUEST_URI includes the query string, remove it
							$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
						}
						elseif (isset($_SERVER['PHP_SELF']))
						{
							$uri = $_SERVER['PHP_SELF'];
						}
						elseif (isset($_SERVER['REDIRECT_URL']))
						{
							$uri = $_SERVER['REDIRECT_URL'];
						}
						else
						{
							// If you ever see this error, please report an issue at and include a dump of $_SERVER
							// http://dev.kohanaphp.com/projects/kohana3/issues
							throw new Kohana_Exception('Unable to detect the URI using PATH_INFO, REQUEST_URI, or PHP_SELF');
						}

						// Get the path from the base URL, including the index file
						$base_url = parse_url(Kohana::$base_url, PHP_URL_PATH);

						if (strpos($uri, $base_url) === 0)
						{
							// Remove the base URL from the URI
							$uri = substr($uri, strlen($base_url));
						}

						if (Kohana::$index_file AND strpos($uri, Kohana::$index_file) === 0)
						{
							// Remove the index file from the URI
							$uri = substr($uri, strlen(Kohana::$index_file));
						}
					}
				}
			}

			// Reduce multiple slashes to a single slash
			$uri = preg_replace('#//+#', '/', $uri);

			// Remove all dot-paths from the URI, they are not valid
			$uri = preg_replace('#\.[\s./]*/#', '', $uri);

			$config['get'] = $_GET;
			$config['post'] = $_POST;

			// Create the instance singleton
			$instance = new Request($uri, $config);

			// Add the Content-Type header
//			$instance->headers['Content-Type'] = 'text/html; charset='.Kohana::$charset;
		}

		return $instance;
	}

	/**
	 * Creates a new request object for the given URI. This differs from
	 * [Request::instance] in that it does not automatically detect the URI
	 * and should only be used for creating HMVC requests.
	 *
	 *     $request = Request::factory($uri);
	 *
	 * @param   string  URI of the request
	 * @return  Request
	 */
	public static function factory($uri)
	{
		return new Request($uri);
	}

	/**
	 * Returns information about the client user agent.
	 *
	 *     // Returns "Chrome" when using Google Chrome
	 *     $browser = Request::user_agent('browser');
	 *
	 * @param   string  value to return: browser, version, robot, mobile, platform
	 * @return  string  requested information
	 * @return  FALSE   no information found
	 * @uses    Kohana::config
	 * @uses    Request::$user_agent
	 */
	public static function user_agent($value)
	{
		static $info;

		if (isset($info[$value]))
		{
			// This value has already been found
			return $info[$value];
		}

		if ($value === 'browser' OR $value == 'version')
		{
			// Load browsers
			$browsers = Kohana::config('user_agents')->browser;

			foreach ($browsers as $search => $name)
			{
				if (stripos(Request::$user_agent, $search) !== FALSE)
				{
					// Set the browser name
					$info['browser'] = $name;

					if (preg_match('#'.preg_quote($search).'[^0-9.]*+([0-9.][0-9.a-z]*)#i', Request::$user_agent, $matches))
					{
						// Set the version number
						$info['version'] = $matches[1];
					}
					else
					{
						// No version number found
						$info['version'] = FALSE;
					}

					return $info[$value];
				}
			}
		}
		else
		{
			// Load the search group for this type
			$group = Kohana::config('user_agents')->$value;

			foreach ($group as $search => $name)
			{
				if (stripos(Request::$user_agent, $search) !== FALSE)
				{
					// Set the value name
					return $info[$value] = $name;
				}
			}
		}

		// The value requested could not be found
		return $info[$value] = FALSE;
	}

	/**
	 * Returns the accepted content types. If a specific type is defined,
	 * the quality of that type will be returned.
	 *
	 *     $types = Request::accept_type();
	 *
	 * @param   string  content MIME type
	 * @return  float   when checking a specific type
	 * @return  array
	 * @uses    Request::_parse_accept
	 */
	public static function accept_type($type = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT'], array('*/*' => 1.0));
		}

		if (isset($type))
		{
			// Return the quality setting for this type
			return isset($accepts[$type]) ? $accepts[$type] : $accepts['*/*'];
		}

		return $accepts;
	}

	/**
	 * Returns the accepted languages. If a specific language is defined,
	 * the quality of that language will be returned. If the language is not
	 * accepted, FALSE will be returned.
	 *
	 *     $langs = Request::accept_lang();
	 *
	 * @param   string  language code
	 * @return  float   when checking a specific language
	 * @return  array
	 * @uses    Request::_parse_accept
	 */
	public static function accept_lang($lang = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT_LANGUAGE header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT_LANGUAGE']);
		}

		if (isset($lang))
		{
			// Return the quality setting for this lang
			return isset($accepts[$lang]) ? $accepts[$lang] : FALSE;
		}

		return $accepts;
	}

	/**
	 * Returns the accepted encodings. If a specific encoding is defined,
	 * the quality of that encoding will be returned. If the encoding is not
	 * accepted, FALSE will be returned.
	 *
	 *     $encodings = Request::accept_encoding();
	 *
	 * @param   string  encoding type
	 * @return  float   when checking a specific encoding
	 * @return  array
	 * @uses    Request::_parse_accept
	 */
	public static function accept_encoding($type = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT_LANGUAGE header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT_ENCODING']);
		}

		if (isset($type))
		{
			// Return the quality setting for this type
			return isset($accepts[$type]) ? $accepts[$type] : FALSE;
		}

		return $accepts;
	}


	/**
	 * Parses an accept header and returns an array (type => quality) of the
	 * accepted types, ordered by quality.
	 *
	 *     $accept = Request::_parse_accept($header, $defaults);
	 *
	 * @param   string   header to parse
	 * @param   array    default values
	 * @return  array
	 */
	protected static function _parse_accept( & $header, array $accepts = NULL)
	{
		if ( ! empty($header))
		{
			// Get all of the types
			$types = explode(',', $header);

			foreach ($types as $type)
			{
				// Split the type into parts
				$parts = explode(';', $type);

				// Make the type only the MIME
				$type = trim(array_shift($parts));

				// Default quality is 1.0
				$quality = 1.0;

				foreach ($parts as $part)
				{
					// Prevent undefined $value notice below
					if (strpos($part, '=') === FALSE)
						continue;

					// Separate the key and value
					list ($key, $value) = explode('=', trim($part));

					if ($key === 'q')
					{
						// There is a quality for this type
						$quality = (float) trim($value);
					}
				}

				// Add the accept type and quality
				$accepts[$type] = $quality;
			}
		}

		// Make sure that accepts is an array
		$accepts = (array) $accepts;

		// Order by quality
		arsort($accepts);

		return $accepts;
	}

	/**
	 * Tests whether the request is for an internal call or
	 * external call
	 *
	 * @param   string   the uri to test
	 * @return  boolean  FALSE internal call, TRUE for external request
	 */
	protected static function _request_external($uri)
	{
		// If there is no protocal, request is internal
		if (FALSE === strpos($uri, '://'))
			return FALSE;

		// Discover in uri is statistics
		$base_path = URL::base(TRUE, TRUE);
		$base_path_position = strpos($uri, $base_path);

		// If the base_path did not match at all, or it was located beyond the start
		// of the URI
		if (FALSE === $base_path_position or 0 < $base_path_position)
			return TRUE;

		return FALSE;
	}

	/**
	 * @var  string  method: GET, POST, PUT, DELETE, etc
	 */
	public $method = 'GET';

	/**
	 * @var  object  route matched for this request
	 */
	public $route;

	/**
	 * @var  boolean  true if external request
	 */
	public $external = FALSE;

	/**
	 * @var  integer  HTTP response code: 200, 404, 500, etc
	 */
	public $status = 200;

	/**
	 * @var  object  response 
	 */
	public $response;

	/**
	 * @var  array  headers to send with the request
	 */
	public $headers = array();

	/**
	 * @var  array  cookies to be sent with the request
	 */
	public $cookies = array();

	/**
	 * @var  string  controller directory
	 */
	public $directory = '';

	/**
	 * @var  string  controller to be executed
	 */
	public $controller;

	/**
	 * @var  string  action to be executed in the controller
	 */
	public $action;

	/**
	 * @var  string  the URI of the request
	 */
	public $uri;

	/**
	 * @var  array   GET parameters for this request
	 */
	public $get = array();

	/**
	 * @var  array   POST parameters for this request
	 */
	public $post = array();

	/**
	 * @var  boolean  AJAX-generated request
	 */
	public $is_ajax = FALSE;

	/**
	 * @var  array|bool Original GET, POST, SERVER vars before alteration
	 */
	protected $_previous_environment = FALSE;

	// Parameters extracted from the route
	protected $_params;

	/**
	 * Creates a new request object for the given URI. New requests should be
	 * created using the [Request::instance] or [Request::factory] methods.
	 *
	 *     $request = new Request($uri);
	 *
	 * @param   string  URI of the request
	 * @param   config  settings for this request object
	 * @return  void
	 * @throws  Kohana_Request_Exception
	 * @uses    Route::all
	 * @uses    Route::matches
	 */
	public function __construct($uri, array $config = array())
	{
		// Parse an array of request properties
		foreach ($config as $key => $value)
		{
			if (property_exists($this, $key))
				$this->$key = $value;
		}

		// Test if request is internal or external
		if (Request::_request_external($uri))
		{
			$this->external = TRUE;
			$this->uri = $uri;
			return;
		}

		// Remove trailing slashes from the URI
		$uri = trim($uri, '/');

		// Load routes
		$routes = Route::all();

		foreach ($routes as $name => $route)
		{
			if ($params = $route->matches($uri))
			{
				// Store the URI
				$this->uri = $uri;

				// Store the matching route
				$this->route = $route;

				if (isset($params['directory']))
				{
					// Controllers are in a sub-directory
					$this->directory = $params['directory'];
				}

				// Store the controller
				$this->controller = $params['controller'];

				if (isset($params['action']))
				{
					// Store the action
					$this->action = $params['action'];
				}
				else
				{
					// Use the default action
					$this->action = Route::$default_action;
				}

				// These are accessible as public vars and can be overloaded
				unset($params['controller'], $params['action'], $params['directory']);

				// Params cannot be changed once matched
				$this->_params = $params;

				return;
			}
		}

		// No matching route for this URI
		$this->status = 404;

		throw new Kohana_Request_Exception('Unable to find a route to match the URI: :uri',
			array(':uri' => $uri));
	}

	/**
	 * Returns the response as the string representation of a request.
	 *
	 *     echo $request;
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return (string) $this->response;
	}

	/**
	 * Generates a relative URI for the current route.
	 *
	 *     $request->uri($params);
	 *
	 * @param   array   additional route parameters
	 * @return  string
	 * @uses    Route::uri
	 */
	public function uri(array $params = NULL)
	{
		if ( ! isset($params['directory']))
		{
			// Add the current directory
			$params['directory'] = $this->directory;
		}

		if ( ! isset($params['controller']))
		{
			// Add the current controller
			$params['controller'] = $this->controller;
		}

		if ( ! isset($params['action']))
		{
			// Add the current action
			$params['action'] = $this->action;
		}

		// Add the current parameters
		$params += $this->_params;

		return $this->route->uri($params);
	}

	/**
	 * Retrieves a value from the route parameters.
	 *
	 *     $id = $request->param('id');
	 *
	 * @param   string   key of the value
	 * @param   mixed    default value if the key is not set
	 * @return  mixed
	 */
	public function param($key = NULL, $default = NULL)
	{
		if ($key === NULL)
		{
			// Return the full array
			return $this->_params;
		}

		return isset($this->_params[$key]) ? $this->_params[$key] : $default;
	}

	/**
	 * Get/Set value(s) to/from the request header
	 *
	 * @param   string|array key of the header to get or set
	 * @param   string   value to set to the key
	 * @return  mixed
	 */
	public function header($key = NULL, $value = NULL)
	{
		if ($key === NULL)
		{
			// Return the full array
			return $this->headers;
		}

		if ($key !== NULL and $value === NULL)
		{
			// Return just the header value if present
			return isset($this->headers[$key]) ? $this->headers[$key] : NULL;
		}

		if (is_array($key))
		{
			// Set the headers array to the request and return this
			$this->headers = $key;
			return $this;
		}

		// Set the value to the header key and return this
		$this->headers[$key] = $value;
		return $this;
	}

	/**
	 * Redirects as the request response.
	 *
	 * @param   string   redirect location
	 * @param   integer  status code: 301, 302, etc
	 * @return  void
	 * @uses    URL::site
	 * @uses    Request::send_headers
	 */
	public function redirect($url, $code = 302)
	{
		if (strpos($url, '://') === FALSE)
		{
			// Make the URI into a URL
			$url = URL::site($url, TRUE);
		}

		// Set the response status
		$config['status'] = $code;

		// Set the location header
		$config['headers']['Location'] = $url;

		$this->response = new Response($config);

		// Send headers
		$this->response->send_headers();

		// Stop execution
		exit;
	}

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
	 * @return  Kohana_Response
	 * @throws  Kohana_Exception
	 * @uses    [Kohana::$profiling]
	 * @uses    [Profiler]
	 */
	public function execute()
	{
		// If this is an external request, process it as such
		if ($this->external)
			return $this->_external_execute();

		// Create the class prefix
		$prefix = 'controller_';

		if ( ! empty($this->directory))
		{
			// Add the directory name to the class prefix
			$prefix .= str_replace(array('\\', '/'), '_', trim($this->directory, '/')).'_';
		}

		if (Kohana::$profiling === TRUE)
		{
			// Start benchmarking
			$benchmark = Profiler::start('Requests', $this->uri);
		}

		try
		{
			// Initialise the Request environment
			$this->_init_environment();

			// Load the controller using reflection
			$class = new ReflectionClass($prefix.$this->controller);

			if ($class->isAbstract())
			{
				throw new Kohana_Exception('Cannot create instances of abstract :controller',
					array(':controller' => $prefix.$this->controller));
			}

			// Create a response
			$this->response = new Response;

			// Create a new instance of the controller
			$controller = $class->newInstance($this);

			// Execute the "before action" method
			$class->getMethod('before')->invoke($controller);

			// Determine the action to use
			$action = empty($this->action) ? Route::$default_action : $this->action;

			// Execute the main action with the parameters
			$class->getMethod('action_'.$action)->invokeArgs($controller, $this->_params);

			// Execute the "after action" method
			$class->getMethod('after')->invoke($controller);

			// De-initialise the Request environment
			$this->_deinit_environment();
		}
		catch (Exception $e)
		{
			if (isset($benchmark))
			{
				// Delete the benchmark, it is invalid
				Profiler::delete($benchmark);
			}

			if ($e instanceof ReflectionException)
			{
				// Reflection will throw exceptions for missing classes or actions
				$this->status = 404;
			}
			else
			{
				// All other exceptions are PHP/server errors
				$this->status = 500;
			}

			// Re-throw the exception
			throw $e;
		}

		if (isset($benchmark))
		{
			// Stop the benchmark
			Profiler::stop($benchmark);
		}

		return $this->response;
	}

	/**
	 * Initialises the server environment variables
	 * for this request execution.
	 * 
	 * - Stores _GET, _POST and select _SERVER vars
	 * - Replaces _GET, _POST and select _SERVER vars
	 *
	 * @return void
	 */
	protected function _init_environment()
	{
		// Store existing $_GET, $_POST, $_SERVER vars
		$this->_previous_environment = array(
			'_GET'    => $_GET,
			'_POST'   => $_POST,
			'_SERVER' => $_SERVER,
		);

		// Assign this requests values to globals
		$_GET = $this->get;
		$_POST = $this->post;

		$query_strings = array();
		foreach ($_GET as $key => $val)
			$query_strings[] = $key.'='.urlencode($val);

		// Get argc number
		$_argc = $query_strings ? 1 : 0;

		// Create the full query string
		$query_string = implode('&', $query_strings);

		// Augment the existing $_SERVER
		$_REQUEST_SERVER = array(
			'QUERY_STRING'     => $query_string,
			'argv'             => $query_string,
			'argc'             => $_argc,
			'REQUEST_METHOD'   => $this->method,
			'SCRIPT_NAME'      => '/'.Kohana::$index_file.'//'.$this->uri,
			'REQUEST_URI'      => '/'.$this->uri,
			'DOCUMENT_URI'     => '/'.Kohana::$index_file.'//'.$this->uri,
			'REQUEST_TIME'     => time(),
			'PHP_SELF'         => '/'.Kohana::$index_file.'/'.$this->uri,
		);

		// Apply new server settings
		$_SERVER = ($_REQUEST_SERVER += $_SERVER);

		$http_headers = array();
		foreach ($_SERVER as $key => $value)
		{
			if ( ! preg_match_all('/HTTP_(\w+)/', $key, $headers))
				continue;

			$http_headers[ucwords(strtolower(str_replace('_', '-', $headers[1][0])))] = $value;
		}

		// Set new internal headings
		$this->headers += $http_headers;
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

	/**
	 * Execute a request that is to an external source
	 *
	 * @param   array    Additional headers to send with the request
	 * @param   string   The HTTP method to use
	 * @return  $this
	 */
	protected function _external_execute()
	{
		// Cached store of requests
		static $external_executions;

		// Start benchmarking if required
		$benchmark = (Kohana::$profiling === TRUE) ? Profiler::start('Requests', $this->uri) : NULL;

		// Encode the request components
		$encoded_components = array(
			'headers'  => ($this->headers) ? http_build_query($this->headers) : NULL,
			'cookies'  => ($this->cookies) ? http_build_query($this->cookies, '', '; ') : NULL,
			'get'      => ($this->get) ? http_build_query($this->get) : NULL,
			'post'     => ($this->post) ? http_build_query($this->post) : NULL,
		);

		// If there are GET parameters, add them to the uri
		if ($encoded_components['get'] !== NULL)
			$this->uri .= '?'.$encoded_components['get'];

		// Create a hash of the request
		$request_hash = sha1($this->method.' '.$this->uri.'|HEADERS;'.$encoded_components['headers'].'|COOKIES;'.$encoded_components['cookies'].'|POST;'.$encoded_components['post']);

		// If this request has been run
		if (isset($external_executions[$request_hash]))
			return $external_executions[$request_hash];

		// Compile the base curl settings
		$curl_options = array(
			CURLOPT_HTTPHEADER    => $this->headers,
			CURLOPT_CUSTOMREQUEST => $this->method,
		);

		if ($this->cookies)
		{
			// If there are cookies present, set the cookie string
			$curl_options[CURLOPT_COOKIE] = $encoded_components['cookies'];
		}

		if (in_array($this->method, array('POST', 'PUT', 'DELETE')))
		{
			// If the method supports POST data, apply it
			$curl_options[CURLOPT_POSTFIELDS] = $this->post;
		}

		$config = array(
			'status'   => array_pop(Remote::$headers),
			'body'     => Remote::get($this->uri, $curl_options),
			'headers'  => Remote::$headers,
		);

		// Create a response
		$this->response = Response::factory($config);

		// Cache the response
		$external_executions[$request_hash] = $this->response;

		if ($benchmark !== NULL)
		{
			// Stop benchmarking if running
			Profiler::stop($benchmark);
		}

		// Return the response
		return $this->response;
	}

} // End Request