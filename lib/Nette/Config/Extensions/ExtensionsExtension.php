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
 * Enables registration of other extensions in $config file
 *
 * @author  Vojtech Dobes
 * @package Nette\Config\Extensions
 */
class NExtensionsExtension extends NConfigCompilerExtension
{

	public function loadConfiguration()
	{
		foreach ($this->getConfig() as $name => $class) {
			$this->compiler->addExtension($name, new $class);
		}
	}

}
