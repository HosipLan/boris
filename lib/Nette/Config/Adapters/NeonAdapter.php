<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Config\Adapters
 */



/**
 * Reading and generating NEON files.
 *
 * @author     David Grudl
 * @package Nette\Config\Adapters
 */
class NConfigNeonAdapter extends NObject implements IConfigAdapter
{
	/** @internal */
	const INHERITING_SEPARATOR = '<', // child < parent
		PREVENT_MERGING = '!';

	/**
	 * Reads configuration from NEON file.
	 * @param  string  file name
	 * @return array
	 */
	public function load($file)
	{
		return $this->process((array) NNeon::decode(file_get_contents($file)));
	}



	private function process(array $arr)
	{
		$res = array();
		foreach ($arr as $key => $val) {
			if (substr($key, -1) === self::PREVENT_MERGING) {
				if (!is_array($val) && $val !== NULL) {
					throw new InvalidStateException("Replacing operator is available only for arrays, item '$key' is not array.");
				}
				$key = substr($key, 0, -1);
				$val[NConfigHelpers::EXTENDS_KEY] = NConfigHelpers::OVERWRITE;

			} elseif (preg_match('#^(\S+)\s+' . self::INHERITING_SEPARATOR . '\s+(\S+)\z#', $key, $matches)) {
				if (!is_array($val) && $val !== NULL) {
					throw new InvalidStateException("Inheritance operator is available only for arrays, item '$key' is not array.");
				}
				list(, $key, $val[NConfigHelpers::EXTENDS_KEY]) = $matches;
				if (isset($res[$key])) {
					throw new InvalidStateException("Duplicated key '$key'.");
				}
			}

			if (is_array($val)) {
				$val = $this->process($val);
			} elseif ($val instanceof NNeonEntity) {
				$val = (object) array('value' => $val->value, 'attributes' => $this->process($val->attributes));
			}
			$res[$key] = $val;
		}
		return $res;
	}



	/**
	 * Generates configuration in NEON format.
	 * @return string
	 */
	public function dump(array $data)
	{
		$tmp = array();
		foreach ($data as $name => $secData) {
			if ($parent = NConfigHelpers::takeParent($secData)) {
				$name .= ' ' . self::INHERITING_SEPARATOR . ' ' . $parent;
			}
			$tmp[$name] = $secData;
		}
		return "# generated by Nette\n\n" . NNeon::encode($tmp, NNeon::BLOCK);
	}

}
