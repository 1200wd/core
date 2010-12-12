<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Request and response wrapper. Uses the [Route] class to determine what
 * [Controller] to send the request to.
 *
 * @package    Kohana
 * @category   Base
 * @author     Kohana Team
 * @copyright  (c) 2008-2010 Kohana Team
 * @license    http://kohanaframework.org/license
 */
class Kohana_Request implements Http_Request {

	/**
	 * @var  string  client user agent
	 */
	public static $user_agent = '';

	/**
	 * @var  string  client IP address
	 */
	public static $client_ip = '0.0.0.0';

	/**
	 * @var  object  main request instance
	 */
	public static $initial;

	/**
	 * @var  object  currently executing request instance
	 */
	public static $current;

	public static function factory( & $uri = TRUE)
	{
		if (Kohana::$is_cli)
		{
			// Default protocol for command line is cli://
			$protocol = 'cli';

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
				$method = strtoupper($options['method']);
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
				$method = $_SERVER['REQUEST_METHOD'];
			}

			if ( ! empty($_SERVER['HTTPS']) AND filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN))
			{
				// This request is secure
				$protocol = 'https';
			}

			if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) AND strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
			{
				// This request is an AJAX request
				$is_ajax = TRUE;
			}

			if (isset($_SERVER['HTTP_REFERER']))
			{
				// There is a referrer for this request
				$referrer = $_SERVER['HTTP_REFERER'];
			}

			if (isset($_SERVER['HTTP_USER_AGENT']))
			{
				// Set the client user agent
				$user_agent = $_SERVER['HTTP_USER_AGENT'];
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

			if ($method !== 'GET' AND $method !== 'POST')
			{
				// Ensure the raw body is saved for future use
				$body = file_get_contents('php://input');
				// Methods besides GET and POST do not properly parse the form-encoded
				// query string into the $_POST array, so we overload it manually.
				parse_str($body, $_POST);
			}

			if ($uri === TRUE)
			{
				$uri = Request::detect_uri();
			}
		}

		// Reduce multiple slashes to a single slash
		$uri = preg_replace('#//+#', '/', $uri);

		// Remove all dot-paths from the URI, they are not valid
		$uri = preg_replace('#\.[\s./]*/#', '', $uri);

		// Create the instance singleton
		$request = new Request($uri);

		// Create the initial request if it does not exist
		(Request::$initial === NULL) and Request::$initial = $request;

		/**
		 * @todo   Apply this to the request->response headers
		 */
		// Add the default Content-Type header
		//Request::$instance->headers['Content-Type'] = Kohana::$content_type.'; charset='.Kohana::$charset;
		return $request;
	}

	/**
	 * Automatically detects the URI of the main request using PATH_INFO,
	 * REQUEST_URI, PHP_SELF or REDIRECT_URL.
	 *
	 *     $uri = Request::detect_uri();
	 *
	 * @return  string  URI of the main request
	 * @throws  Kohana_Exception
	 * @since   3.0.8
	 */
	public static function detect_uri()
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

				// Decode the request URI
				$uri = rawurldecode($uri);
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
				// If you ever see this error, please report an issue at http://dev.kohanaphp.com/projects/kohana3/issues
				// along with any relevant information about your web server setup. Thanks!
				throw new Kohana_Exception('Unable to detect the URI using PATH_INFO, REQUEST_URI, PHP_SELF or REDIRECT_URL');
			}

			// Get the path from the base URL, including the index file
			$base_url = parse_url(Kohana::$base_url, PHP_URL_PATH);

			if (strpos($uri, $base_url) === 0)
			{
				// Remove the base URL from the URI
				$uri = (string) substr($uri, strlen($base_url));
			}

			if (Kohana::$index_file AND strpos($uri, Kohana::$index_file) === 0)
			{
				// Remove the index file from the URI
				$uri = (string) substr($uri, strlen(Kohana::$index_file));
			}
		}

		return $uri;
	}

	/**
	 * Return the currently executing request. This is changed to the current
	 * request when [Request::execute] is called and restored when the request
	 * is completed.
	 *
	 *     $request = Request::current();
	 *
	 * @return  Request
	 * @since   3.0.5
	 */
	public static function current()
	{
		return Request::$current;
	}

	/**
	 * Returns the first request encountered by this framework. This will should
	 * only be set once during the first [Request::factory] invocation.
	 * 
	 *     // Get the first request
	 *     $request = Request::initial();
	 * 
	 *     // Test whether the current request is the first request
	 *     if (Request::initial() === Request::current())
	 *          // Do something useful
	 *
	 * @return  Request
	 * @since   3.1.0
	 */
	public static function initial()
	{
		return Request::$initial;
	}

	/**
	 * Returns information about the client user agent.
	 *
	 *     // Returns "Chrome" when using Google Chrome
	 *     $browser = Request::user_agent('browser');
	 *
	 * Multiple values can be returned at once by using an array:
	 *
	 *     // Get the browser and platform with a single call
	 *     $info = Request::user_agent(array('browser', 'platform'));
	 *
	 * When using an array for the value, an associative array will be returned.
	 *
	 * @param   mixed   string to return: browser, version, robot, mobile, platform; or array of values
	 * @return  mixed   requested information, FALSE if nothing is found
	 * @uses    Kohana::config
	 * @uses    Request::$user_agent
	 */
	public static function user_agent($value)
	{
		if (is_array($value))
		{
			$agent = array();
			foreach ($value as $v)
			{
				// Add each key to the set
				$agent[$v] = Request::user_agent($v);
			}

			return $agent;
		}

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
	 * @var  string  method: GET, POST, PUT, DELETE, HEAD, etc
	 */
	public $method = 'GET';

	/**
	 * @var  string  protocol: HTTP/1.1, FTP, CLI, etc
	 */
	public $protocol;

	/**
	 * @var  string  referring URL
	 */
	public $referrer;

	/**
	 * @var  Route       route matched for this request
	 */
	public $route;

	/**
	 * @var  Kohana_Response  response
	 */
	public $response;

	/**
	 * @var  Kohana_Http_Header  headers to sent as part of the request
	 */
	public $header;

	/**
	 * @var  string the body
	 */
	public $body;

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
	 * @var  array   parameters from the route
	 */
	protected $_params;

	/**
	 * @var array    query parameters
	 */
	protected $_get;

	/**
	 * @var array    post parameters
	 */
	protected $_post;

	/**
	 * Creates a new request object for the given URI. New requests should be
	 * created using the [Request::instance] or [Request::factory] methods.
	 *
	 *     $request = new Request($uri);
	 *
	 * @param   string  URI of the request
	 * @return  void
	 * @throws  Kohana_Request_Exception
	 * @uses    Route::all
	 * @uses    Route::matches
	 */
	public function __construct($uri, array $options = NULL)
	{
		// Initialise the header
		$this->header = new Http_Header(array());

		// Remove trailing slashes from the URI
		$uri = trim($uri, '/');

		// Detect host

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
	 * Create a URL from the current request. This is a shortcut for:
	 *
	 *     echo URL::site($this->request->uri($params), $protocol);
	 *
	 * @param   string   route name
	 * @param   array    URI parameters
	 * @param   mixed    protocol string or boolean, adds protocol and domain
	 * @return  string
	 * @since   3.0.7
	 * @uses    URL::site
	 */
	public function url(array $params = NULL, $protocol = NULL)
	{
		// Create a URI with the current route and convert it to a URL
		return URL::site($this->uri($params), $protocol);
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
	 * Redirects as the request response. If the URL does not include a
	 * protocol, it will be converted into a complete URL.
	 *
	 *     $request->redirect($url);
	 *
	 * [!!] No further processing can be done after this method is called!
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

		// Redirect
		$response = $this->create_response();

		// Set the response status
		$response->status = $code;

		// Set the location header
		$response->headers['Location'] = $url;

		// Send headers
		$response->send_headers();

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
	 * @return  $this
	 * @throws  Kohana_Exception
	 * @uses    [Kohana::$profiling]
	 * @uses    [Profiler]
	 */
	public function execute()
	{
		// Create the class prefix
		$prefix = 'controller_';

		if ($this->directory)
		{
			// Add the directory name to the class prefix
			$prefix .= str_replace(array('\\', '/'), '_', trim($this->directory, '/')).'_';
		}

		if (Kohana::$profiling)
		{
			// Set the benchmark name
			$benchmark = '"'.$this->uri.'"';

			if ($this !== Request::$initial AND Request::$current)
			{
				// Add the parent request uri
				$benchmark .= ' « "'.Request::$current->uri.'"';
			}

			// Start benchmarking
			$benchmark = Profiler::start('Requests', $benchmark);
		}

		// Store the currently active request
		$previous = Request::$current;

		// Change the current request to this request
		Request::$current = $this;

		try
		{
			// Load the controller using reflection
			$class = new ReflectionClass($prefix.$this->controller);

			if ($class->isAbstract())
			{
				throw new Kohana_Exception('Cannot create instances of abstract :controller',
					array(':controller' => $prefix.$this->controller));
			}

			// Create a new instance of the controller
			$controller = $class->newInstance($this, $this->create_response());

			// Determine the action to use
			$action = empty($this->action) ? Route::$default_action : $this->action;

			// Get all the method objects before invoking them
			$before = $class->getMethod('before');
			$method = $class->getMethod('action_'.$action);
			$after = $class->getMethod('after');
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
				// Reflection will throw exceptions for missing classes or actions
				$this->status = 404;
			}

			// Re-throw the exception
			throw $e;
		}

		try
		{
			// Execute the "before action" method
			$before->invoke($controller);

			// Execute the main action with the parameters
			$method->invokeArgs($controller, $this->_params);

			// Execute the "after action" method
			$after->invoke($controller);
		}
		catch (Exception $e)
		{
			// All other exceptions are PHP/server errors
			$this->status = 500;

			throw $e;
		}

		// Restore the previous request
		Request::$current = $previous;

		if (isset($benchmark))
		{
			// Stop the benchmark
			Profiler::stop($benchmark);
		}

		return $this;
	}


	/**
	 * Generates an [ETag](http://en.wikipedia.org/wiki/HTTP_ETag) from the
	 * request response.
	 *
	 *     $etag = $request->generate_etag();
	 *
	 * [!!] If the request response is empty when this method is called, an
	 * exception will be thrown!
	 *
	 * @return string
	 * @throws Kohana_Request_Exception
	 */
	public function generate_etag()
	{
	    if ($this->response === NULL)
		{
			throw new Kohana_Request_Exception('No response yet associated with request - cannot auto generate resource ETag');
		}

		// Generate a unique hash for the response
		return '"'.sha1($this->response).'"';
	}

	/**
	 * Creates a response based on the type of request, i.e. an
	 * Request_Http will produce a Response_Http, and the same applies
	 * to CLI.
	 * 
	 *      // Create a response to the request
	 *      $response = $request->create_response();
	 * 
	 * @param   boolean  bind to this request
	 * @return  Kohana_Response
	 * @since   3.1.0
	 */
	public function create_response($bind = TRUE)
	{
		$response = new Response(array('_protocol' => $this->protocol()));

		if ( ! $bind)
			return $response;
		else
			return $this->response = $response;
	}

	/**
	 * Checks the browser cache to see the response needs to be returned.
	 *
	 *     $request->check_cache($etag);
	 *
	 * [!!] If the cache check succeeds, no further processing can be done!
	 *
	 * @param   string  etag to check
	 * @return  $this
	 * @throws  Kohana_Request_Exception
	 * @uses    Request::generate_etag
	 */
	public function check_cache($etag = null)
	{
		if (empty($etag))
		{
			$etag = $this->generate_etag();
		}

		// Set the ETag header
		$this->header['ETag'] = $etag;

		// Add the Cache-Control header if it is not already set
		// This allows etags to be used with Max-Age, etc
		// $this->header += array(
		// 	'Cache-Control' => 'must-revalidate',
		// );

		if (isset($_SERVER['HTTP_IF_NONE_MATCH']) AND $_SERVER['HTTP_IF_NONE_MATCH'] === $etag)
		{
			// No need to send data again
			$response = $this->create_response();
			$response->status = 304;
			$response->send_headers();

			// Stop execution
			exit;
		}

		return $this;
	}

	/**
	 * Gets or sets the Http method. Usually GET, POST, PUT or DELETE in
	 * traditional CRUD applications.
	 *
	 * @param   string   method to use for this request
	 * @return  string
	 * @return  Kohana_Request
	 */
	public function method($method = NULL)
	{
		if ($method === NULL)
			return $this->method;

		$this->method = strtoupper($method);
		return $this;
	}

	/**
	 * Gets or sets the HTTP protocol. The standard protocol to use
	 * is `HTTP/1.1`.
	 *
	 * @param   string   protocol to set to the request/response
	 * @return  string
	 * @return  Kohana_Request
	 */
	public function protocol($protocol = NULL)
	{
		if ($protocol === NULL)
		{
			if ($this->protocol === NULL)
				$this->protocol = Http::$protocol;

			return $this->protocol;
		}

		$this->protocol = strtoupper($protocol);
		return $this;
	}

	/**
	 * Gets or sets HTTP headers to the request or response. All headers
	 * are included immediately after the HTTP protocol definition during
	 * transmission. This method provides a simple array or key/value
	 * interface to the headers.
	 *
	 * @param   string|array   key or array of key/value pairs to set
	 * @param   string         value to set to the supplied key
	 * @return  mixed
	 */
	public function headers($key = NULL, $value = NULL)
	{
		if ($key === NULL)
			return $this->header;
		else if ($value === NULL)
			return $this->header[$key];
		else if (is_array($key))
			$this->header->exchangeArray($key);
		else
			$this->header[$key] = $value;

		return $this;
	}

	/**
	 * Sends the response status and all set headers.
	 *
	 * @return  Kohana_Request
	 */
	public function send_response_headers()
	{
		return $this->response->send_headers();
	}

	/**
	 * Gets or sets the HTTP body to the request or response. The body is
	 * included after the header, separated by a single empty new line.
	 *
	 * @param   string         content to set to the object
	 * @return  string
	 * @return  Kohana_Request
	 */
	public function body($content = NULL)
	{
		if ($content === NULL)
			return $this->body;

		$this->body = $content;
		return $this;
	}

	/**
	 * Renders the Http_Interaction to a string, producing
	 * 
	 *  - Protocol
	 *  - Headers
	 *  - Body
	 * 
	 *  If there are variables set to the `Kohana_Request::$_post`
	 *  they will override any values set to body.
	 *
	 * @return  string
	 */
	public function render()
	{
		if ( ! $this->_post)
			$body = $this->body;
		else
		{
			$this->header['content-type'] = 'application/x-www-form-urlencoded';
			$body = Http::www_form_urlencode($this->_post);
		}

		if ( ! $this->_get)
			$query_string = '';
		else
			$query_string = '?'.Http::www_form_urlencode($this->query());

		$output = $this->method.' '.$this->uri($this->param()).$query_string.' '.$this->protocol()."\n";
		$output .= (string) $this->header;
		$output .= $body;

		return $output;
	}

	/**
	 * Gets or sets HTTP query string.
	 *
	 * @param   string|array key or key value pairs to set
	 * @param   string   value to set to a key
	 * @return  mixed
	 */
	public function query($key = NULL, $value = NULL)
	{
		if ($key === NULL)
			return $this->_get;
		else if ($value === NULL)
			return $this->_get[$key];
		else
		{
			$this->_get[$key] = $value;
			return $this;
		}
	}

	/**
	 * Gets or sets HTTP POST parameters to the request.
	 *
	 * @param   string|array key or key value pairs to set
	 * @param   string   value to set to a key
	 * @return  mixed
	 */
	public function post($key = NULL, $value = NULL)
	{
		if ($key === NULL)
			return $this->_post;
		else if ($value === NULL)
			return $this->_post[$key];
		else
		{
			$this->_post[$key] = $value;
			return $this;
		}
	}
} // End Request
