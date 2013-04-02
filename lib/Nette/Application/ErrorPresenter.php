<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package NetteModule
 */



/**
 * Default Error Presenter.
 *
 * @author     David Grudl
 * @package NetteModule
 */
class Nette_ErrorPresenter extends NObject implements IPresenter
{

	/**
	 * @return IPresenterResponse
	 */
	public function run(NPresenterRequest $request)
	{
		$e = $request->parameters['exception'];
		if ($e instanceof NBadRequestException) {
			$code = $e->getCode();
		} else {
			$code = 500;
			NDebugger::log($e, NDebugger::ERROR);
		}
		ob_start();
		require dirname(__FILE__) . '/templates/error.phtml';
		return new NTextResponse(ob_get_clean());
	}

}
