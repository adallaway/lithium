<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2016, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\core;

use lithium\core\Libraries;
use lithium\aop\Filters;
use lithium\analysis\Inspector;

/**
 * Base class in Lithium's hierarchy, from which all concrete classes inherit. This class defines
 * several conventions for how classes in Lithium should be structured:
 *
 * - **Universal constructor**: Any class which defines a `__construct()` method should take
 *   exactly one parameter (`$config`), and that parameter should always be an array. Any settings
 *   passed to the constructor will be stored in the `$_config` property of the object.
 * - **Initialization / automatic configuration**: After the constructor, the `_init()` method is
 *   called. This method can be used to initialize the object, keeping complex logic and
 *   high-overhead or difficult to test operations out of the constructor. This method is called
 *   automatically by `BaseObject::__construct()`, but may be disabled by passing `'init' => false` to
 *   the constructor. The initializer is also used for automatically assigning object properties.
 *   See the documentation on the `_init()` method for more details.
 * - **Testing / misc.**: The `__set_state()` method provides a default implementation of the PHP
 *   magic method (works with `var_export()`) which can instantiate an object with a static method
 *   call. Finally, the `_stop()` method may be used instead of `exit()`, as it can be overridden
 *   for testing purposes.
 *
 * @link http://php.net/manual/en/language.oop5.magic.php#object.set-state
 * @see lithium\core\StaticObject
 */
class BaseObject {

	/**
	 * Stores configuration information for object instances at time of construction.
	 * **Do not override.** Pass any additional variables to `parent::__construct()`.
	 *
	 * @var array
	 */
	protected $_config = [];

	/**
	 * Holds an array of values that should be processed on initialization. Each value should have
	 * a matching protected property (prefixed with `_`) defined in the class. If the property is
	 * an array, the property name should be the key and the value should be `'merge'`. See the
	 * `_init()` method for more details.
	 *
	 * @see lithium\core\BaseObject::_init()
	 * @var array
	 */
	protected $_autoConfig = [];

	/**
	 * Parents of the current class.
	 *
	 * @see lithium\core\BaseObject::_parents()
	 * @var array
	 */
	protected static $_parents = [];

	/**
	 * Constructor. Initializes class configuration (`$_config`), and assigns object properties
	 * using the `_init()` method, unless otherwise specified by configuration. See below for
	 * details.
	 *
	 * @see lithium\core\BaseObject::$_config
	 * @see lithium\core\BaseObject::_init()
	 * @param array $config The configuration options which will be assigned to the `$_config`
	 *        property. This method accepts one configuration option:
	 *        - `'init'` _boolean_: Controls constructor behavior for calling the `_init()`
	 *          method. If `false`, the method is not called, otherwise it is. Defaults to `true`.
	 * @return void
	 */
	public function __construct(array $config = []) {
		$defaults = ['init' => true];
		$this->_config = $config + $defaults;

		if ($this->_config['init']) {
			$this->_init();
		}
	}

	/**
	 * Initializer function called by the constructor unless the constructor `'init'` flag is set
	 * to `false`. May be used for testing purposes, where objects need to be manipulated in an
	 * un-initialized state, or for high-overhead operations that require more control than the
	 * constructor provides. Additionally, this method iterates over the `$_autoConfig` property
	 * to automatically assign configuration settings to their corresponding properties.
	 *
	 * For example, given the following:
	 * ```
	 * class Bar extends \lithium\core\BaseObject {
	 * 	protected $_autoConfig = ['foo'];
	 * 	protected $_foo;
	 * }
	 *
	 * $instance = new Bar(['foo' => 'value']);
	 * ```
	 *
	 * The `$_foo` property of `$instance` would automatically be set to `'value'`. If `$_foo` was
	 * an array, `$_autoConfig` could be set to `array('foo' => 'merge')`, and the constructor value
	 * of `'foo'` would be merged with the default value of `$_foo` and assigned to it.
	 *
	 * @see lithium\core\BaseObject::$_autoConfig
	 * @return void
	 */
	protected function _init() {
		foreach ($this->_autoConfig as $key => $flag) {
			if (!isset($this->_config[$key]) && !isset($this->_config[$flag])) {
				continue;
			}

			if ($flag === 'merge') {
				$this->{"_{$key}"} = $this->_config[$key] + $this->{"_{$key}"};
			} else {
				$this->{"_$flag"} = $this->_config[$flag];
			}
		}
	}

	/**
	 * Calls a method on this object with the given parameters. Provides an OO wrapper
	 * for call_user_func_array, and improves performance by using straight method calls
	 * in most cases.
	 *
	 * @param string $method  Name of the method to call
	 * @param array $params  Parameter list to use when calling $method
	 * @return mixed  Returns the result of the method call
	 */
	public function invokeMethod($method, $params = []) {
		switch (count($params)) {
			case 0:
				return $this->{$method}();
			case 1:
				return $this->{$method}($params[0]);
			case 2:
				return $this->{$method}($params[0], $params[1]);
			case 3:
				return $this->{$method}($params[0], $params[1], $params[2]);
			case 4:
				return $this->{$method}($params[0], $params[1], $params[2], $params[3]);
			case 5:
				return $this->{$method}($params[0], $params[1], $params[2], $params[3], $params[4]);
			default:
				return call_user_func_array([&$this, $method], $params);
		}
	}

	/**
	 * PHP magic method used in conjunction with `var_export()` to allow objects to be
	 * re-instantiated with their pre-existing properties and values intact. This method can be
	 * called statically on any class that extends `BaseObject` to return an instance of it.
	 *
	 * @param array $data An array of properties and values with which to re-instantiate the object.
	 *        These properties can be both public and protected.
	 * @return object Returns an instance of the requested object with the given properties set.
	 */
	public static function __set_state($data) {
		$class = get_called_class();
		$object = new $class();

		foreach ($data as $property => $value) {
			$object->{$property} = $value;
		}
		return $object;
	}

	/**
	 * Determines if a given method can be called.
	 *
	 * @param string $method Name of the method.
	 * @param boolean $internal Provide `true` to perform check from inside the
	 *                class/object. When `false` checks also for public visibility;
	 *                defaults to `false`.
	 * @return boolean Returns `true` if the method can be called, `false` otherwise.
	 */
	public function respondsTo($method, $internal = false) {
		return Inspector::isCallable($this, $method, $internal);
	}

	/**
	 * Returns an instance of a class with given `config`. The `name` could be a key from the
	 * `classes` array, a fully-namespaced class name, or an object. Typically this method is used
	 * in `_init` to create the dependencies used in the current class.
	 *
	 * @param string|object $name A `classes` key or fully-namespaced class name.
	 * @param array $options The configuration passed to the constructor.
	 * @return object
	 */
	protected function _instance($name, array $options = []) {
		if (is_string($name) && isset($this->_classes[$name])) {
			$name = $this->_classes[$name];
		}
		return Libraries::instance(null, $name, $options);
	}

	/**
	 * Gets and caches an array of the parent methods of a class.
	 *
	 * @return array Returns an array of parent classes for the current class.
	 */
	protected static function _parents() {
		$class = get_called_class();

		if (!isset(static::$_parents[$class])) {
			static::$_parents[$class] = class_parents($class);
		}
		return static::$_parents[$class];
	}

	/**
	 * Exit immediately. Primarily used for overrides during testing.
	 *
	 * @param integer|string $status integer range 0 to 254, string printed on exit
	 * @return void
	 */
	protected function _stop($status = 0) {
		exit($status);
	}

	/* Deprecated / BC */

	/**
	 * Contains a 2-dimensional array of filters applied to this object's methods, indexed by method
	 * name. See the associated methods for more details.
	 *
	 * @deprecated Not used anymore.
	 * @see lithium\core\BaseObject::_filter()
	 * @see lithium\core\BaseObject::applyFilter()
	 * @var array
	 */
	protected $_methodFilters = [];

	/**
	 * Apply a closure to a method of the current object instance.
	 *
	 * @deprecated Replaced by `\lithium\aop\Filters::apply()` and `::clear()`.
	 * @see lithium\core\BaseObject::_filter()
	 * @see lithium\util\collection\Filters
	 * @param mixed $method The name of the method to apply the closure to. Can either be a single
	 *        method name as a string, or an array of method names. Can also be false to remove
	 *        all filters on the current object.
	 * @param \Closure $filter The closure that is used to filter the method(s), can also be false
	 *        to remove all the current filters for the given method.
	 * @return void
	 */
	public function applyFilter($method, $filter = null) {
		$message  = '`' . __METHOD__ . '()` has been deprecated in favor of ';
		$message .= '`\lithium\aop\Filters::apply()` and `::clear()`.';
		trigger_error($message, E_USER_DEPRECATED);

		if ($method === false) {
			Filters::clear($this);
			return;
		}
		foreach ((array) $method as $m) {
			if ($filter === false) {
				Filters::clear($this, $m);
			} else {
				Filters::apply($this, $m, $filter);
			}
		}
	}

	/**
	 * Executes a set of filters against a method by taking a method's main implementation as a
	 * callback, and iteratively wrapping the filters around it. This, along with the `Filters`
	 * class, is the core of Lithium's filters system. This system allows you to "reach into" an
	 * object's methods which are marked as _filterable_, and intercept calls to those methods,
	 * optionally modifying parameters or return values.
	 *
	 * @deprecated Replaced by `\lithium\aop\Filters::run()`.
	 * @see lithium\core\BaseObject::applyFilter()
	 * @see lithium\util\collection\Filters
	 * @param string $method The name of the method being executed, usually the value of
	 *               `__METHOD__`.
	 * @param array $params An associative array containing all the parameters passed into
	 *              the method.
	 * @param \Closure $callback The method's implementation, wrapped in a closure.
	 * @param array $filters Additional filters to apply to the method for this call only.
	 * @return mixed Returns the return value of `$callback`, modified by any filters passed in
	 *         `$filters` or applied with `applyFilter()`.
	 */
	protected function _filter($method, $params, $callback, $filters = []) {
		$message  = '`' . __METHOD__ . '()` has been deprecated in favor of ';
		$message .= '`\lithium\aop\Filters::run()` and `::apply()`.';
		trigger_error($message, E_USER_DEPRECATED);

		list(, $method) = explode('::', $method);

		foreach ($filters as $filter) {
			Filters::apply($this, $method, $filter);
		}
		return Filters::run($this, $method, $params, $callback);
	}
}

?>