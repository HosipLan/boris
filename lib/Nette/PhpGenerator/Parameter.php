<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\PhpGenerator
 */



/**
 * Method parameter description.
 *
 * @author     David Grudl
 *
 * @method Parameter setName(string $name)
 * @method Parameter setReference(bool $on)
 * @method Parameter setTypeHint(string $class)
 * @method Parameter setOptional(bool $on)
 * @method Parameter setDefaultValue(mixed $value)
 * @package Nette\PhpGenerator
 */
class NPhpParameter extends NObject
{
	/** @var string */
	public $name;

	/** @var bool */
	public $reference;

	/** @var string */
	public $typeHint;

	/** @var bool */
	public $optional;

	/** @var mixed */
	public $defaultValue;


	/** @return NPhpParameter */
	public static function from(ReflectionParameter $from)
	{
		$param = new self;
		$param->name = $from->getName();
		$param->reference = $from->isPassedByReference();
		try {
			$param->typeHint = $from->isArray() ? 'array' : ($from->getClass() ? '\\' . $from->getClass()->getName() : '');
		} catch (ReflectionException $e) {
			if (preg_match('#Class (.+) does not exist#', $e->getMessage(), $m)) {
				$param->typeHint = '\\' . $m[1];
			} else {
				throw $e;
			}
		}
		$param->optional = PHP_VERSION_ID < 50407 ? $from->isOptional() || ($param->typeHint && $from->allowsNull()) : $from->isDefaultValueAvailable();
		$param->defaultValue = (PHP_VERSION_ID === 50316 ? $from->isOptional() : $from->isDefaultValueAvailable()) ? $from->getDefaultValue() : NULL;

		$namespace = PHP_VERSION_ID < 50300 ? '' :$from->getDeclaringClass()->getNamespaceName();
		$namespace = $namespace ? "\\$namespace\\" : "\\";
		if (NStrings::startsWith($param->typeHint, $namespace)) {
			$param->typeHint = substr($param->typeHint, strlen($namespace));
		}
		return $param;
	}



	public function __call($name, $args)
	{
		return NObjectMixin::callProperty($this, $name, $args);
	}

}
