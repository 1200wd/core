<?php defined('SYSPATH') OR die('Kohana bootstrap needs to be included before tests run');

/**
 * Tests the Validation lib that's shipped with Kohana
 *
 * @group kohana
 * @group kohana.validation
 *
 * @package    Unittest
 * @author     Kohana Team
 * @author     BRMatt <matthew@sigswitch.com>
 * @copyright  (c) 2008-2010 Kohana Team
 * @license    http://kohanaframework.org/license
 */
Class Kohana_ValidationTest extends Unittest_TestCase
{
	/**
	 * Tests Validation::factory()
	 *
	 * Makes sure that the factory method returns an instance of Validation lib
	 * and that it uses the variables passed
	 *
	 * @test
	 */
	public function test_factory_method_returns_instance_with_values()
	{
		$values = array(
			'this'			=> 'something else',
			'writing tests' => 'sucks',
			'why the hell'	=> 'amIDoingThis',
		);

		$instance = Validation::factory($values);

		$this->assertTrue($instance instanceof Validation);

		$this->assertSame(
			$values,
			$instance->as_array()
		);
	}

	/**
	 * When we copy() a validation object, we should have a new validation object
	 * with the exact same attributes, apart from the data, which should be the
	 * same as the array we pass to copy()
	 *
	 * @test
	 * @covers Validation::copy
	 */
	public function test_copy_copies_all_attributes_except_data()
	{
		$validation = new Validation(array('foo' => 'bar', 'fud' => 'fear, uncertainty, doubt', 'num' => 9));

		$validation->rule('num', 'is_int')->rule('foo', 'is_string');

		$copy_data = array('foo' => 'no', 'fud' => 'maybe', 'num' => 42);

		$copy = $validation->copy($copy_data);

		$this->assertNotSame($validation, $copy);

		foreach(array('_rules', '_bound', '_labels', '_empty_rules', '_errors') as $attribute)
		{
			// This is just an easy way to check that the attributes are identical
			// Without hardcoding the expected values
			$this->assertAttributeSame(
				self::readAttribute($validation, $attribute),
				$attribute,
				$copy
			);
		}

		$this->assertSame($copy_data, $copy->as_array());
	}

	/**
	 * When the validation object is initially created there should be no labels
	 * specified
	 *
	 * @test
	 */
	public function test_initially_there_are_no_labels()
	{
		$validation = new Validation(array());

		$this->assertAttributeSame(array(), '_labels', $validation);
	}

	/**
	 * Adding a label to a field should set it in the labels array
	 * If the label already exists it should overwrite it
	 *
	 * In both cases thefunction should return a reference to $this
	 *
	 * @test
	 * @covers Validation::label
	 */
	public function test_label_adds_and_overwrites_label_and_returns_this()
	{
		$validation = new Validation(array());

		$this->assertSame($validation, $validation->label('email', 'Email Address'));

		$this->assertAttributeSame(array('email' => 'Email Address'), '_labels', $validation);

		$this->assertSame($validation, $validation->label('email', 'Your Email'));

		$validation->label('name', 'Your Name');

		$this->assertAttributeSame(
			array('email' => 'Your Email', 'name' => 'Your Name'),
			'_labels',
			$validation
		);
	}

	/**
	 * Using labels() we should be able to add / overwrite multiple labels
	 *
	 * The function should also return $this for chaining purposes
	 *
	 * @test
	 * @covers Validation::labels
	 */
	public function test_labels_adds_and_overwrites_multiple_labels_and_returns_this()
	{
		$validation = new Validation(array());
		$initial_data = array('kung fu' => 'fighting', 'fast' => 'cheetah');

		$this->assertSame($validation, $validation->labels($initial_data));

		$this->assertAttributeSame($initial_data, '_labels', $validation);

		$this->assertSame($validation, $validation->labels(array('fast' => 'lightning')));

		$this->assertAttributeSame(
			array('fast' => 'lightning', 'kung fu' => 'fighting'),
			'_labels',
			$validation
		);
	}

	/**
	 * Using bind() we should be able to add / overwrite multiple bound variables
	 *
	 * The function should also return $this for chaining purposes
	 *
	 * @test
	 * @covers Validation::bind
	 */
	public function test_bind_adds_and_overwrites_multiple_variables_and_returns_this()
	{
		$validation = new Validation(array());
		$data = array('kung fu' => 'fighting', 'fast' => 'cheetah');
		$bound = array(':foo' => 'some value');

		// Test binding an array of values
		$this->assertSame($validation, $validation->bind($bound));
		$this->assertAttributeSame($bound, '_bound', $validation);

		// Test binding one value
		$this->assertSame($validation, $validation->bind(':foo', 'some other value'));
		$this->assertAttributeSame(array(':foo' => 'some other value'), '_bound', $validation);
	}

	/**
	 * Provides test data for test_check
	 *
	 * @return array
	 */
	public function provider_check()
	{
		// $data_array, $rules, $first_expected, $expected_error
		return array(
			array(
				array('foo' => 'bar'),
				array('foo' => array(array('not_empty', NULL))),
				TRUE,
				array(),
			),
			array(
				array('unit' => 'test'),
				array(
					'foo'  => array(array('not_empty', NULL)),
					'unit' => array(array('min_length', array(':value', 6))
					),
				),
				FALSE,
				array(
					'foo' => 'foo must not be empty',
					'unit' => 'unit must be at least 6 characters long'
				),
			),
			// We need to test wildcard rules
			array(
				array('foo' => 'bar'),
				array(
					TRUE => array(array('min_length', array(':value', 4))),
					'foo'  => array(array('not_empty', NULL)),
					// Makes sure empty fields do not validate unless the rule is in _empty_rules
					'unit' => array(array('exact_length', array(':value', 4))),
				),
				FALSE,
				array('foo' => 'foo must be at least 4 characters long'),
			),
		);
	}

	/**
	 * Tests Validation::check()
	 *
	 * @test
	 * @covers Validation::check
	 * @covers Validation::rule
	 * @covers Validation::rules
	 * @covers Validation::errors
	 * @covers Validation::error
	 * @dataProvider provider_check
	 * @param string  $url       The url to test
	 * @param boolean $expected  Is it valid?
	 */
	public function test_check($array, $rules, $expected, $expected_errors)
	{
		$validation = new Validation($array);

		foreach ($rules as $field => $field_rules)
		{
			foreach ($field_rules as $rule)
				$validation->rule($field, $rule[0], $rule[1]);
		}

		$status = $validation->check();
		$errors = $validation->errors(TRUE);
		$this->assertSame($expected, $status);
		$this->assertSame($expected_errors, $errors);

		$validation = new validation($array);
		foreach ($rules as $field => $rules)
			$validation->rules($field, $rules);
		$this->assertSame($expected, $validation->check());
	}

	/**
	 * Provides test data for test_errors()
	 *
	 * @return array
	 */
	public function provider_errors()
	{
		// [data, rules, expected], ...
		return array(
			array(
				array('username' => 'frank'),
				array('username' => array(array('not_empty', NULL))),
				array(),
			),
			array(
				array('username' => ''),
				array('username' => array(array('not_empty', NULL))),
				array('username' => 'username must not be empty'),
			),
		);
	}

	/**
	 * Tests Validation::errors()
	 *
	 * @test
	 * @covers Validation::errors
	 * @dataProvider provider_errors
	 * @param string  $url       The url to test
	 * @param boolean $expected  Is it valid?
	 */
	public function test_errors($array, $rules, $expected)
	{
		$Validation = Validation::factory($array);

		foreach($rules as $field => $field_rules)
		{
			$Validation->rules($field, $field_rules);
		}

		$Validation->check();

		$this->assertSame($expected, $Validation->errors('Validation', FALSE));
	}
}
