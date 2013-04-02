<?php

/**
 * This file is part of the Nette Framework.
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 * @package Nette\Latte
 */



/**
 * HTML element node.
 *
 * @author     David Grudl
 * @package Nette\Latte
 */
class NHtmlNode extends NObject
{
	/** @var string */
	public $name;

	/** @var bool */
	public $isEmpty = FALSE;

	/** @var array */
	public $attrs = array();

	/** @var array */
	public $macroAttrs = array();

	/** @var bool */
	public $closing = FALSE;

	/** @var NHtmlNode */
	public $parentNode;

	/** @var string */
	public $attrCode;

	/** @var int */
	public $offset;



	public function __construct($name, self $parentNode = NULL)
	{
		$this->name = $name;
		$this->parentNode = $parentNode;
	}

}
