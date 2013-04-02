<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */



/**
 * NDebugger::enable() shortcut.
 */
function debug()
{
	NDebugger::$strictMode = TRUE;
	NDebugger::enable(NDebugger::DEVELOPMENT);
}



/**
 * NDebugger::dump() shortcut.
 */
function dump($var)
{
	foreach (func_get_args() as $arg) {
		NDebugger::dump($arg);
	}
	return $var;
}



/**
 * NDebugger::log() shortcut.
 */
function dlog($var = NULL)
{
	if (func_num_args() === 0) {
		NDebugger::log(new Exception, 'dlog');
	}
	foreach (func_get_args() as $arg) {
		NDebugger::log($arg, 'dlog');
	}
	return $var;
}
