<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Database\Table
 */



/**
 * NSelection factory.
 *
 * @author     David Grudl
 * @package Nette\Database\Table
 */
class NSelectionFactory extends NObject
{
	/** @var NConnection */
	private $connection;

	/** @var IReflection */
	private $reflection;

	/** @var ICacheStorage */
	private $cacheStorage;


	public function __construct(NConnection $connection, IReflection $reflection = NULL, ICacheStorage $cacheStorage = NULL)
	{
		$this->connection = $connection;
		$this->reflection = ($tmp=$reflection) ? $tmp : new NConventionalReflection;
		$this->cacheStorage = $cacheStorage;
	}



	/** @return NTableSelection */
	public function create($table)
	{
		return new NTableSelection($this->connection, $table, $this->reflection, $this->cacheStorage);
	}

}
