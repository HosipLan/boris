<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Config\Extensions
 */



/**
 * @deprecated, for back compatiblity
 * @package Nette\Config\Extensions
 */
class NNetteAccessor extends NObject
{
	private $container;


	public function __construct(NDIContainer $container)
	{
		$this->container = $container;
	}



	public function __call($name, $args)
	{
		if (substr($name, 0, 6) === 'create') {
			$method = $this->container->getMethodName('nette.' . substr($name, 6), FALSE);
			trigger_error("Factory accessing via nette->$name() is deprecated, use $method().", E_USER_WARNING);
			return call_user_func_array(array($this->container, $method), $args);
		}
		throw new NotSupportedException;
	}



	public function &__get($name)
	{
		trigger_error("Service accessing via nette->$name is deprecated, use 'nette.$name'.", E_USER_WARNING);
		$service = $this->container->getService("nette.$name");
		return $service;
	}

}
