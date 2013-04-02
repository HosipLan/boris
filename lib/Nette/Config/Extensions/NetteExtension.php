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
 * Core Nette Framework services.
 *
 * @author     David Grudl
 * @package Nette\Config\Extensions
 */
class NNetteExtension extends NConfigCompilerExtension
{
	public $defaults = array(
		'session' => array(
			'debugger' => FALSE,
			'iAmUsingBadHost' => NULL,
			'autoStart' => 'smart',  // true|false|smart
			'expiration' => NULL,
		),
		'application' => array(
			'debugger' => TRUE,
			'errorPresenter' => NULL,
			'catchExceptions' => '%productionMode%',
			'mapping' => NULL
		),
		'routing' => array(
			'debugger' => TRUE,
			'routes' => array(), // of [mask => action]
		),
		'security' => array(
			'debugger' => TRUE,
			'frames' => 'SAMEORIGIN', // X-Frame-Options
			'users' => array(), // of [user => password]
			'roles' => array(), // of [role => parents]
			'resources' => array(), // of [resource => parents]
		),
		'mailer' => array(
			'smtp' => FALSE,
		),
		'database' => array(), // of [name => dsn, user, password, debugger, explain, autowired, reflection]
		'forms' => array(
			'messages' => array(),
		),
		'latte' => array(
			'xhtml' => TRUE,
			'macros' => array(),
		),
		'container' => array(
			'debugger' => FALSE,
		),
		'debugger' => array(
			'email' => NULL,
			'editor' => NULL,
			'browser' => NULL,
			'strictMode' => NULL,
			'bar' => array(), // of class name
			'blueScreen' => array(), // of callback
		),
	);

	public $databaseDefaults = array(
		'dsn' => NULL,
		'user' => NULL,
		'password' => NULL,
		'options' => NULL,
		'debugger' => TRUE,
		'explain' => TRUE,
		'reflection' => 'NDiscoveredReflection',
	);



	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		if (isset($config['xhtml'])) {
			$config['latte']['xhtml'] = $config['xhtml'];
		}
		$container->addDefinition('nette')->setClass('NNetteAccessor', array('@container'));

		$this->setupCache($container);
		$this->setupHttp($container);
		$this->setupSession($container, $config['session']);
		$this->setupSecurity($container, $config['security']);
		$this->setupApplication($container, $config['application']);
		$this->setupRouting($container, $config['routing']);
		$this->setupMailer($container, $config['mailer']);
		$this->setupForms($container);
		$this->setupTemplating($container, $config['latte']);
		$this->setupDatabase($container, $config['database']);
	}



	private function setupCache(NDIContainerBuilder $container)
	{
		$container->addDefinition($this->prefix('cacheJournal'))
			->setClass('NFileJournal', array('%tempDir%'));

		$container->addDefinition('cacheStorage') // no namespace for back compatibility
			->setClass('NFileStorage', array('%tempDir%/cache'));

		$container->addDefinition($this->prefix('templateCacheStorage'))
			->setClass('NPhpFileStorage', array('%tempDir%/cache'))
			->setAutowired(FALSE);

		$container->addDefinition($this->prefix('cache'))
			->setClass('NCache', array(1 => '%namespace%'))
			->setParameters(array('namespace' => NULL));
	}



	private function setupHttp(NDIContainerBuilder $container)
	{
		$container->addDefinition($this->prefix('httpRequestFactory'))
			->setClass('NHttpRequestFactory')
			->addSetup('setEncoding', array('UTF-8'));

		$container->addDefinition('httpRequest') // no namespace for back compatibility
			->setClass('NHttpRequest')
			->setFactory('@\NHttpRequestFactory::createHttpRequest');

		$container->addDefinition('httpResponse') // no namespace for back compatibility
			->setClass('NHttpResponse');

		$container->addDefinition($this->prefix('httpContext'))
			->setClass('NHttpContext');
	}



	private function setupSession(NDIContainerBuilder $container, array $config)
	{
		$session = $container->addDefinition('session') // no namespace for back compatibility
			->setClass('NSession');

		if (isset($config['expiration'])) {
			$session->addSetup('setExpiration', array($config['expiration']));
		}
		if (isset($config['iAmUsingBadHost'])) {
			$session->addSetup('NFramework::$iAmUsingBadHost = ?;', array((bool) $config['iAmUsingBadHost']));
		}

		if ($container->parameters['debugMode'] && $config['debugger']) {
			$session->addSetup('NDebugger::$bar->addPanel(?)', array(
				new NDIStatement('NSessionPanel')
			));
		}

		unset($config['expiration'], $config['autoStart'], $config['iAmUsingBadHost'], $config['debugger']);
		if (!empty($config)) {
			$session->addSetup('setOptions', array($config));
		}
	}



	private function setupSecurity(NDIContainerBuilder $container, array $config)
	{
		$container->addDefinition($this->prefix('userStorage'))
			->setClass('NUserStorage');

		$user = $container->addDefinition('user') // no namespace for back compatibility
			->setClass('NUser');

		if ($container->parameters['debugMode'] && $config['debugger']) {
			$user->addSetup('NDebugger::$bar->addPanel(?)', array(
				new NDIStatement('NUserPanel')
			));
		}

		if ($config['users']) {
			$container->addDefinition($this->prefix('authenticator'))
				->setClass('NSimpleAuthenticator', array($config['users']));
		}

		if ($config['roles'] || $config['resources']) {
			$authorizator = $container->addDefinition($this->prefix('authorizator'))
				->setClass('NPermission');
			foreach ($config['roles'] as $role => $parents) {
				$authorizator->addSetup('addRole', array($role, $parents));
			}
			foreach ($config['resources'] as $resource => $parents) {
				$authorizator->addSetup('addResource', array($resource, $parents));
			}
		}
	}



	private function setupApplication(NDIContainerBuilder $container, array $config)
	{
		$application = $container->addDefinition('application') // no namespace for back compatibility
			->setClass('NApplication')
			->addSetup('$catchExceptions', $config['catchExceptions'])
			->addSetup('$errorPresenter', $config['errorPresenter'])
			->addSetup('!headers_sent() && header(?);', 'X-Powered-By: Nette Framework');

		if ($config['debugger']) {
			$application->addSetup('NRoutingDebugger::initializePanel');
		}

		$presenterFactory = $container->addDefinition($this->prefix('presenterFactory'))
			->setClass('NPresenterFactory', array(
				isset($container->parameters['appDir']) ? $container->parameters['appDir'] : NULL
			));
		if ($config['mapping']) {
			$presenterFactory->addSetup('$service->mapping = ? + $service->mapping;', array($config['mapping']));
		}
	}



	private function setupRouting(NDIContainerBuilder $container, array $config)
	{
		$router = $container->addDefinition('router') // no namespace for back compatibility
			->setClass('NRouteList');

		foreach ($config['routes'] as $mask => $action) {
			$router->addSetup('$service[] = new NRoute(?, ?);', array($mask, $action));
		}

		if ($container->parameters['debugMode'] && $config['debugger']) {
			$container->getDefinition('application')->addSetup('NDebugger::$bar->addPanel(?)', array(
				new NDIStatement('NRoutingDebugger')
			));
		}
	}



	private function setupMailer(NDIContainerBuilder $container, array $config)
	{
		if (empty($config['smtp'])) {
			$container->addDefinition($this->prefix('mailer'))
				->setClass('NSendmailMailer');
		} else {
			$container->addDefinition($this->prefix('mailer'))
				->setClass('NSmtpMailer', array($config));
		}

		$container->addDefinition($this->prefix('mail'))
			->setClass('NMail')
			->addSetup('setMailer')
			->setShared(FALSE);
	}



	private function setupForms(NDIContainerBuilder $container)
	{
		$container->addDefinition($this->prefix('basicForm'))
			->setClass('NForm')
			->setShared(FALSE);
	}



	private function setupTemplating(NDIContainerBuilder $container, array $config)
	{
		$latte = $container->addDefinition($this->prefix('latte'))
			->setClass('NLatteFilter')
			->setShared(FALSE);

		if (empty($config['xhtml'])) {
			$latte->addSetup('$service->getCompiler()->defaultContentType = ?', NLatteCompiler::CONTENT_HTML);
		}

		$container->addDefinition($this->prefix('template'))
			->setClass('NFileTemplate')
			->addSetup('registerFilter', array($latte))
			->addSetup('registerHelperLoader', array('NTemplateHelpers::loader'))
			->setShared(FALSE);

		foreach ($config['macros'] as $macro) {
			if (strpos($macro, '::') === FALSE && class_exists($macro)) {
				$macro .= '::install';

			} else {
				NValidators::assert($macro, 'callable');
			}

			$latte->addSetup($macro . '(?->compiler)', array('@self'));
		}
	}



	private function setupDatabase(NDIContainerBuilder $container, array $config)
	{
		if (isset($config['dsn'])) {
			$config = array('default' => $config);
		}

		$autowired = TRUE;
		foreach ((array) $config as $name => $info) {
			if (!is_array($info)) {
				continue;
			}
			$info += $this->databaseDefaults + array('autowired' => $autowired);
			$autowired = FALSE;

			foreach ((array) $info['options'] as $key => $value) {
				if (preg_match('#^PDO::\w+\z#', $key)) {
					unset($info['options'][$key]);
					$info['options'][constant($key)] = $value;
				}
			}

			if (!$info['reflection']) {
				$reflection = NULL;
			} elseif (is_string($info['reflection'])) {
				$reflection = new NDIStatement(preg_match('#^[a-z]+\z#', $info['reflection'])
					? 'Nette\Database\Reflection\\' . ucfirst($info['reflection']) . 'Reflection'
					: $info['reflection'], strtolower($info['reflection']) === 'discovered' ? array('@self') : array());
			} else {
				$tmp = NConfigCompiler::filterArguments(array($info['reflection']));
				$reflection = reset($tmp);
			}

			$connection = $container->addDefinition($this->prefix("database.$name"))
				->setClass('NConnection', array($info['dsn'], $info['user'], $info['password'], $info['options']))
				->setAutowired($info['autowired'])
				->addSetup('setSelectionFactory', array(
					new NDIStatement('NSelectionFactory', array('@self', $reflection)),
				))
				->addSetup('NDebugger::$blueScreen->addPanel(?)', array(
					'NDatabasePanel::renderException'
				));

			if ($container->parameters['debugMode'] && $info['debugger']) {
				$connection->addSetup('NDatabaseHelpers::createDebugPanel', array($connection, !empty($info['explain'])));
			}
		}
	}



	public function afterCompile(NPhpClassType $class)
	{
		$initialize = $class->methods['initialize'];
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		// debugger
		foreach (array('email', 'editor', 'browser', 'strictMode', 'maxLen', 'maxDepth', 'showLocation', 'scream') as $key) {
			if (isset($config['debugger'][$key])) {
				$initialize->addBody('NDebugger::$? = ?;', array($key, $config['debugger'][$key]));
			}
		}

		if ($container->parameters['debugMode']) {
			if ($config['container']['debugger']) {
				$config['debugger']['bar'][] = 'NContainerPanel';
			}

			foreach ((array) $config['debugger']['bar'] as $item) {
				$initialize->addBody($container->formatPhp(
					'NDebugger::$bar->addPanel(?);',
					NConfigCompiler::filterArguments(array(is_string($item) ? new NDIStatement($item) : $item))
				));
			}

			foreach ((array) $config['debugger']['blueScreen'] as $item) {
				$initialize->addBody($container->formatPhp(
					'NDebugger::$blueScreen->addPanel(?);',
					NConfigCompiler::filterArguments(array($item))
				));
			}
		}

		if (!empty($container->parameters['tempDir'])) {
			$initialize->addBody('NFileStorage::$useDirectories = ?;', array($this->checkTempDir($container->expand('%tempDir%/cache'))));
		}

		foreach ((array) $config['forms']['messages'] as $name => $text) {
			$initialize->addBody('NRules::$defaultMessages[NForm::?] = ?;', array($name, $text));
		}

		if ($config['session']['autoStart'] === 'smart') {
			$initialize->addBody('$this->session->exists() && $this->session->start();');
		} elseif ($config['session']['autoStart']) {
			$initialize->addBody('$this->session->start();');
		}

		if (empty($config['latte']['xhtml'])) {
			$initialize->addBody('NHtml::$xhtml = ?;', array((bool) $config['latte']['xhtml']));
		}

		if (isset($config['security']['frames']) && $config['security']['frames'] !== TRUE) {
			$frames = $config['security']['frames'];
			if ($frames === FALSE) {
				$frames = 'DENY';
			} elseif (preg_match('#^https?:#', $frames)) {
				$frames = "ALLOW-FROM $frames";
			}
			$initialize->addBody('header(?);', array("X-Frame-Options: $frames"));
		}

		foreach ($container->findByTag('run') as $name => $on) {
			if ($on) {
				$initialize->addBody('$this->getService(?);', array($name));
			}
		}
	}



	private function checkTempDir($dir)
	{
		// checks whether directory is writable
		$uniq = uniqid('_', TRUE);
		if (!@mkdir("$dir/$uniq")) { // @ - is escalated to exception
			throw new InvalidStateException("Unable to write to directory '$dir'. Make this directory writable.");
		}

		// checks whether subdirectory is writable
		$isWritable = @file_put_contents("$dir/$uniq/_", '') !== FALSE; // @ - error is expected
		if ($isWritable) {
			unlink("$dir/$uniq/_");
		}
		rmdir("$dir/$uniq");
		return $isWritable;
	}

}
