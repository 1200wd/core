<?php defined('SYSPATH') or die('No direct script access.');
/**
 * The Kohana_HTTP_Header class provides an Object-Orientated interface
 * to HTTP headers. This can parse header arrays returned from the
 * PHP functions `apache_request_headers()` or the `http_parse_headers()`
 * function available within the PECL HTTP library.
 *
 * @package    Kohana
 * @category   HTTP
 * @author     Kohana Team
 * @since      3.1.0
 * @copyright  (c) 2008-2011 Kohana Team
 * @license    http://kohanaphp.com/license
 */
class Kohana_HTTP_Header extends ArrayObject {

	/**
	 * @var     array     Default positive filter for sorting header values
	 */
	public static $default_sort_filter = array('accept','accept-charset','accept-encoding','accept-language');

	/**
	 * Parses HTTP Header values and creating an appropriate object
	 * depending on type; i.e. accept-type, accept-char, cache-control etc.
	 *
	 *     $header_values_array = HTTP_Header::parse_header_values(array('cache-control' => 'max-age=200; public'));
	 *
	 * @param   array    $header_values          Values to parse
	 * @param   array    $header_commas_allowed  Header values where commas are not delimiters (usually date)
	 * @return  array
	 */
	public static function parse_header_values(array $header_values, array $header_commas_allowed = array('user-agent', 'date', 'expires', 'last-modified'))
	{
		/**
		 * @see http://www.w3.org/Protocols/rfc2616/rfc2616.html
		 *
		 * HTTP header declarations should be treated as case-insensitive
		 */
		$header_values = array_change_key_case($header_values, CASE_LOWER);

		// Foreach of the header values applied
		foreach ($header_values as $key => $value)
		{
			if (is_array($value))
			{
				$values = array();

				if (Arr::is_assoc($value))
				{

					foreach ($value as $k => $v)
					{
						$values[] = HTTP_Header::parse_header_values($v);
					}
				}
				else
				{
					// RFC 2616 allows multiple headers with same name if they can be
					// concatinated using commas without altering the original message.
					// This usually occurs with multiple Set-Cookie: headers
					$array = array();
					foreach ($value as $k => $v)
					{
						// Break value into component parts
						$v = explode(';', $v);

						// Do some nasty parsing to flattern the array into components,
						// parsing key values
						$array = Arr::flatten(array_map('HTTP_Header_Value::parse_key_value', $v));

						// Get the K/V component and extract the first element
						$key_value_component = array_slice($array, 0, 1, TRUE);
						array_shift($array);

						// Create the HTTP_Header_Value component array
						$http_header['_key']        = key($key_value_component);
						$http_header['_value']      = current($key_value_component);
						$http_header['_properties'] = $array;

						// Create the HTTP_Header_Value
						$values[] = new HTTP_Header_Value($http_header);
					}
				}

				// Assign HTTP_Header_Value array to the header
				$header_values[$key] = $values;
				continue;
			}

			// If the key allows commas or no commas are found
			if (in_array($key, $header_commas_allowed) or (strpos($value, ',') === FALSE))
			{
				// If the key is user-agent, we don't want to parse the string
				if ($key === 'user-agent')
				{
					$header_values[$key] = new HTTP_Header_Value($value, TRUE);
				}
				// Else, behave normally
				else
				{
					$header_values[$key] = new HTTP_Header_Value($value);
				}

				// Move to next header
				continue;
			}

			// Create an array of the values and clear any whitespace
			$value = array_map('trim', explode(',', $value));

			$parsed_values = array();

			// Foreach value
			foreach ($value as $v)
			{
				$v = new HTTP_Header_Value($v);

				// Convert the value string into an object
				if ($v->key() === NULL)
				{
					$parsed_values[] = $v;
				}
				else
				{
					$parsed_values[$v->key()] = $v;
				}
			}

			// Apply parsed value to the header
			$header_values[$key] = $parsed_values;
		}

		// Return the parsed header values
		return $header_values;
	}

	/**
	 * Constructor method for [Kohana_HTTP_Header]. Uses the standard constructor
	 * of the parent `ArrayObject` class.
	 *
	 *     $header_object = new HTTP_Header(array('x-powered-by' => 'Kohana 3.1.x', 'expires' => '...'));
	 *
	 * @param   mixed    Input array
	 * @param   int      Flags
	 * @param   string   The iterator class to use
	 */
	public function __construct(array $input = array(), $flags = NULL, $iterator_class = 'ArrayIterator')
	{
		/**
		 * @link http://www.w3.org/Protocols/rfc2616/rfc2616.html
		 *
		 * HTTP header declarations should be treated as case-insensitive
		 */
		$input = array_change_key_case($input, CASE_LOWER);

		parent::__construct($input, $flags, $iterator_class);

		// if ($input !== NULL)
		// {
		// 	// Parse the values into [HTTP_Header_Values]
		// 	parent::__construct(HTTP_Header::parse_header_values($input), $flags, $iterator_class);
		// }
		// else
		// {
		// 	parent::__construct(array(), $flags, $iterator_class);
		// }
	
	}

	/**
	 * Returns the header object as a string, including
	 * the terminating new line
	 *
	 *     // Return the header as a string
	 *     echo (string) $request->headers();
	 *
	 * @return  string
	 */
	public function __toString()
	{
		$header = '';

		foreach ($this as $key => $value)
		{
			// Put the keys back the Case-Convention expected
			$key = Text::ucfirst($key);

			if (is_array($value))
			{
				$header .= $key.': '.(implode(', ', $value))."\r\n";
			}
			else
			{
				$header .= $key.': '.$value."\r\n";
			}
		}

		return $header."\r\n";
	}

	/**
	 * Overloads the `ArrayObject::exchangeArray()` method to ensure all
	 * values passed are parsed correctly into a [Kohana_HTTP_Header_Value].
	 *
	 *     // Input new headers
	 *     $headers->exchangeArray(array(
	 *          'date'          => 'Wed, 24 Nov 2010 21:09:23 GMT',
	 *          'cache-control' => 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0'
	 *     ));
	 *
	 * @param   array    $array Array to exchange
	 * @return  array
	 */
	// public function exchangeArray($array)
	// {
	// 	return parent::exchangeArray(HTTP_Header::parse_header_values($array));
	// }

	// /**
	//  * Overloads the `ArrayObject::offsetSet` method to ensure any
	//  * access is correctly converted to the correct object type.
	//  *
	//  *     // Add a new header from encoded string
	//  *     $headers['cache-control'] = 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0'
	//  *
	//  * @param   mixed    $index   Key
	//  * @param   mixed    $newval  Value
	//  * @return  void
	//  */
	// public function offsetSet($index, $newval)
	// {
	// 	if (is_array($newval) AND (current($newval) instanceof HTTP_Header_Value))
	// 		return parent::offsetSet(strtolower($index), $newval);
	// 	elseif ( ! $newval instanceof HTTP_Header_Value)
	// 	{
	// 		$newval = new HTTP_Header_Value($newval);
	// 	}
	// 
	// 	parent::offsetSet(strtolower($index), $newval);
	// }

	/**
	 * undocumented function
	 *
	 * @param   mixed     index to retrieve
	 * @param   boolean   set to `TRUE` to return the raw string/array
	 * @return  mixed
	 */
	public function offsetGet($index, $raw = FALSE)
	{
		$value = parent::offsetGet(strtolower($index));

		if ($raw === TRUE)
		 	return (string) $value;
		else
			return HTTP_Header_Value::parse($value);
	}

	/**
	 * Sort the headers by quality property if the header matches the
	 * [Kohana_HTTP_Header::$default_sort_filter] definition.
	 *
	 * #### Default sort values
	 *
	 *  - Accept
	 *  - Accept-Chars
	 *  - Accept-Encoding
	 *  - Accept-Lang
	 *
	 * @param   array    $filter  Header fields to parse
	 * @return  self
	 */
	public function sort_values_by_quality(array $filter = array())
	{
		// If a filter argument is supplied
		if ($filter)
		{
			// Apply filter and store previous
			$previous_filter = HTTP_Header::$default_sort_filter;
			HTTP_Header::$default_sort_filter = $filter;
		}

		// Get a copy of this ArrayObject
		$values = $this->getArrayCopy();

		foreach ($values as $key => $value)
		{
			if ( ! is_array($value) or ! in_array($key, HTTP_Header::$default_sort_filter))
			{
				unset($values[$key]);
				continue;
			}

			// Sort them by comparison
			uasort($value, array($this, '_sort_by_comparison'));

			$values[$key] = $value;
		}

		// Return filter to previous state if required
		if ($filter)
		{
			HTTP_Header::$default_sort_filter = $previous_filter;
		}

		foreach ($values as $key => $value)
		{
			$this[$key] = $value;
		}

		// Return this
		return $this;
	}

	/**
	 * Parses a HTTP Message header line and applies it to this HTTP_Header
	 * 
	 *     $header = $response->headers();
	 *     $header->parse_header_string(NULL, 'content-type: application/json');
	 *
	 * @param   resource  the resource (required by Curl API)
	 * @param   string    the line from the header to parse
	 * @return  int
	 */
	public function parse_header_string($resource, $header_line)
	{
		$headers = array();

		if (preg_match_all('/(\w[^\s:]*):[ ]*([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/', $header_line, $matches))
		{
			foreach ($matches[0] as $key => $value)
			{
				$this[$matches[1][$key]] = $matches[2][$key];
			}
		}

		return strlen($header_line);
	}

	/**
	 * Sends headers to the php processor, or supplied `$callback` argument.
	 * This method formats the headers correctly for output, re-instating their
	 * capitalization for transmission.
	 *
	 * @param   HTTP_Response header to send
	 * @param   boolean   replace existing value
	 * @param   callback  optional callback to replace PHP header function
	 * @return  self
	 * @since   3.2.0
	 */
	public function send_headers(HTTP_Response $response = NULL, $replace = FALSE, $callback = NULL)
	{
		if ($response === NULL)
		{
			// Default to the initial request message
			$response = Request::initial()->response();
		}

		$protocol = $response->protocol();

		// Create the response header
		$status = $response->status();
		$headers_out = array($protocol.' '.$status.' '.Response::$messages[$status]);

		// Get the headers object
		$headers = $response->headers();

		if ( ! isset($headers['content-type']))
		{
			$headers['content-type'] = Kohana::$content_type.'; charset='.Kohana::$charset;
		}

		if (Kohana::$expose AND ! isset($headers['x-powered-by']))
		{
			$headers['x-powered-by'] = 'Kohana Framework '.Kohana::VERSION.' ('.Kohana::CODENAME.')';
		}

		foreach ($headers as $key => $value)
		{
			if (is_string($key))
			{
				$key = Text::ucfirst($key);

				if (is_array($value))
				{
					$value = implode(', ', $value);
				}

				$value = $key.': '. (string) $value;
			}

			$headers_out[] = $value;
		}

		// Add the cookies
		$headers['Set-Cookie'] = $response->cookie();

		if (is_callable($callback))
		{
			// Use the callback method to set header
			call_user_func($callback, $headers_out, $replace);
			return $this;
		}
		else
		{
			return $this->_send_headers_to_php($headers_out, $replace);
		}
	}

	/**
	 * Sends the supplied headers to the PHP output buffer. If cookies
	 * are included in the message they will be handled appropriately.
	 *
	 * @param   array     headers to send to php
	 * @param   boolean   replace existing headers
	 * @return  self
	 */
	protected function _send_headers_to_php(array $headers, $replace)
	{
		// If the headers have been sent, get out
		if (headers_sent())
			return $this;

		foreach ($headers as $key => $line)
		{
			if ($key == 'Set-Cookie' AND is_array($line))
			{
				// Send cookies
				foreach ($line as $name => $value)
				{
					Cookie::set($name, $value['value'], $value['expiration']);
				}

				continue;
			}

			header($line, $replace);
		}

		return $this;
	}

	/**
	 * Provides the implementation for sort_values_by_quality (uasort)
	 *
	 * @param   HTTP_Header_Value 
	 * @param   HTTP_Header_Value 
	 * @return  int
	 * @see     http://www.ietf.org/rfc/rfc2616.txt (Section 14.1)
	 */
	protected function _sort_by_comparison($value_a, $value_b)
	{
		// Test for correct instance type
		if ( ! $value_a instanceof HTTP_Header_Value OR ! $value_b instanceof HTTP_Header_Value)
		{
			// Return neutral if cannot test value
			return 0;
		}

		// Extract the qualities
		$a = (float) Arr::get($value_a->properties, 'q', HTTP_Header_Value::DEFAULT_QUALITY);
		$b = (float) Arr::get($value_b->properties, 'q', HTTP_Header_Value::DEFAULT_QUALITY);

		// Return the string comparison of two floats.
		// This implementation gets past the floating point math problems
		// that occur when testing for equality.
		if (strcmp( (string) $a, (string) $b) === 0)
			return 0;

		// If the qualities are equal, more interrogation is required
		// Get the values for comparisom
		$values = array(
			1   => $value_a->render(NULL, TRUE),
			-1  => $value_b->render(NULL, TRUE)
		);

		// Look for */* first, this is fastish
		if (($count = count($catch_all = array_keys($values, '*/*'))) > 0)
			return ($count == 2) ? 0 : $catch_all[0] * -1;

		// Now lets look for foo/*, getting slower 
		if (($count = count($catch_some = preg_grep('/(\w+\/\*)/', $values))) > 0)
			return ($count == 2) ? 0 : $catch_some[0] * -1;

		// If we get here we'll assume eqauality.
		// Technically there should be final search on specificity, however
		// this is probably not required for 9/10 use cases and we've wasted
		// a lot of CPU time already.
		return 0;
	}

} // End Kohana_HTTP_Header