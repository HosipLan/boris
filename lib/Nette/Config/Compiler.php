<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Config
 */



/**
 * DI container compiler.
 *
 * @author     David Grudl
 *
 * @property-read NConfigCompilerExtension[] $extensions
 * @property-read NDIContainerBuilder $containerBuilder
 * @property-read array $config
 * @package Nette\Config
 */
class NConfigCompiler extends NObject
{
	/** @var NConfigCompilerExtension[] */
	private $extensions = array();

	/** @var NDIContainerBuilder */
	private $container;

	/** @var array */
	private $config;

	/** @var array reserved section names */
	private static $reserved = array('services' => 1, 'factories' => 1, 'parameters' => 1);



	/**
	 * Add custom configurator extension.
	 * @return NConfigCompiler  provides a fluent interface
	 */
	public function addExtension($name, NConfigCompilerExtension $extension)
	{
		if (isset(self::$reserved[$name])) {
			throw new InvalidArgumentException("Name '$name' is reserved.");
		}
		$this->extensions[$name] = $extension->setCompiler($this, $name);
		return $this;
	}



	/**
	 * @return array
	 */
	public function getExtensions()
	{
		return $this->extensions;
	}



	/**
	 * @return NDIContainerBuilder
	 */
	public function getContainerBuilder()
	{
		return $this->container;
	}



	/**
	 * Returns configuration without expanded parameters.
	 * @return array
	 */
	public function getConfig()
	{
		return $this->config;
	}



	/**
	 * @return string
	 */
	public function compile(array $config, $className, $parentName)
	{
		$this->config = $config;
		$this->container = new NDIContainerBuilder;
		$this->processParameters();
		$this->processExtensions();
		$this->processServices();
		return $this->generateCode($className, $parentName);
	}



	public function processParameters()
	{
		if (isset($this->config['parameters'])) {
			$this->container->parameters = $this->config['parameters'];
		}
	}



	public function processExtensions()
	{
		for ($i = 0; $slice = array_slice($this->extensions, $i, 1); $i++) {
			reset($slice)->loadConfiguration();
		}

		if ($extra = array_diff_key($this->config, self::$reserved, $this->extensions)) {
			$extra = implode("', '", array_keys($extra));
			throw new InvalidStateException("Found sections '$extra' in configuration, but corresponding extensions are missing.");
		}
	}



	public function processServices()
	{
		$this->parseServices($this->container, $this->config);

		foreach ($this->extensions as $name => $extension) {
			if (isset($this->config[$name])) {
				$this->parseServices($this->container, $this->config[$name], $name);
			}
		}
	}



	public function generateCode($className, $parentName)
	{
		foreach ($this->extensions as $extension) {
			$extension->beforeCompile();
			$this->container->addDependency(NClassReflection::from($extension)->getFileName());
		}

		$classes = $this->container->generateClasses();
		$classes[0]->setName($className)
			->setExtends($parentName)
			->addMethod('initialize');

		foreach ($this->extensions as $extension) {
			$extension->afterCompile($classes[0]);
		}
		return implode("\n\n\n", $classes);
	}



	/********************* tools ****************d*g**/



	/**
	 * Parses section 'services' from configuration file.
	 * @return void
	 */
	public static function parseServices(NDIContainerBuilder $container, array $config, $namespace = NULL)
	{
		$services = isset($config['services']) ? $config['services'] : array();
		$factories = isset($config['factories']) ? $config['factories'] : array();
		$all = array_merge($services, $factories);

		uasort($all, create_function('$a, $b', '
			return strcmp(NConfigHelpers::isInheriting($a), NConfigHelpers::isInheriting($b));
		'));

		foreach ($all as $origName => $def) {
			$shared = array_key_exists($origName, $services);
			if ((string) (int) $origName === (string) $origName) {
				$name = (string) (count($container->getDefinitions()) + 1);
			} elseif ($shared && array_key_exists($origName, $factories)) {
				throw new NServiceCreationException("It is not allowed to use services and factories with the same name: '$origName'.");
			} else {
				$name = ($namespace ? $namespace . '.' : '') . strtr($origName, '\\', '_');
			}

			if (($parent = NConfigHelpers::takeParent($def)) && $parent !== $name) {
				$container->removeDefinition($name);
				$definition = $container->addDefinition($name);
				if ($parent !== NConfigHelpers::OVERWRITE) {
					foreach ($container->getDefinition($parent) as $k => $v) {
						$definition->$k = unserialize(serialize($v)); // deep clone
					}
				}
			} elseif ($container->hasDefinition($name)) {
				$definition = $container->getDefinition($name);
				if ($definition->shared !== $shared) {
					throw new NServiceCreationException("It is not allowed to use service and factory with the same name '$name'.");
				}
			} else {
				$definition = $container->addDefinition($name);
			}
			try {
				self::parseService($definition, $def, $shared);
			} catch (Exception $e) {
				throw new NServiceCreationException("Service '$name': " . $e->getMessage(), NULL, $e);
			}

			if ($definition->class === 'self') {
				$definition->class = $origName;
			}
			if ($definition->factory && $definition->factory->entity === 'self') {
				$definition->factory->entity = $origName;
			}
		}
	}



	/**
	 * Parses single service from configuration file.
	 * @return void
	 */
	public static function parseService(NDIServiceDefinition $definition, $config, $shared = TRUE)
	{
		if ($config === NULL) {
			return;

		} elseif (!$shared && is_string($config) && interface_exists($config)) {
			$config = array('class' => NULL, 'implement' => $config);

		} elseif (!$shared && $config instanceof stdClass && interface_exists($config->value)) {
			$config = array('class' => NULL, 'implement' => $config->value, 'factory' => array_shift($config->attributes));

		} elseif (!is_array($config)) {
			$config = array('class' => NULL, 'create' => $config);
		}

		if (array_key_exists('factory', $config)) {
			$config['create'] = $config['factory'];
			unset($config['factory']);
		};

		$known = $shared
			? array('class', 'create', 'arguments', 'setup', 'autowired', 'inject', 'run', 'tags')
			: array('class', 'create', 'arguments', 'setup', 'autowired', 'inject', 'parameters', 'implement');

		if ($error = array_diff(array_keys($config), $known)) {
			throw new InvalidStateException("Unknown or deprecated key '" . implode("', '", $error) . "' in definition of service.");
		}

		$arguments = array();
		if (array_key_exists('arguments', $config)) {
			NValidators::assertField($config, 'arguments', 'array');
			$arguments = self::filterArguments($config['arguments']);
			$definition->setArguments($arguments);
		}

		if (array_key_exists('class', $config) || array_key_exists('create', $config)) {
			$definition->class = NULL;
			$definition->factory = NULL;
		}

		if (array_key_exists('class', $config)) {
			NValidators::assertField($config, 'class', 'string|stdClass|null');
			if ($config['class'] instanceof stdClass) {
				$definition->setClass($config['class']->value, self::filterArguments($config['class']->attributes));
			} else {
				$definition->setClass($config['class'], $arguments);
			}
		}

		if (array_key_exists('create', $config)) {
			NValidators::assertField($config, 'create', 'callable|stdClass|null');
			if ($config['create'] instanceof stdClass) {
				$definition->setFactory($config['create']->value, self::filterArguments($config['create']->attributes));
			} else {
				$definition->setFactory($config['create'], $arguments);
			}
		}

		if (isset($config['setup'])) {
			if (NConfigHelpers::takeParent($config['setup'])) {
				$definition->setup = array();
			}
			NValidators::assertField($config, 'setup', 'list');
			foreach ($config['setup'] as $id => $setup) {
				NValidators::assert($setup, 'callable|stdClass', "setup item #$id");
				if ($setup instanceof stdClass) {
					NValidators::assert($setup->value, 'callable', "setup item #$id");
					$definition->addSetup($setup->value, self::filterArguments($setup->attributes));
				} else {
					$definition->addSetup($setup);
				}
			}
		}

		$definition->setShared($shared);
		if (isset($config['parameters'])) {
			NValidators::assertField($config, 'parameters', 'array');
			$definition->setParameters($config['parameters']);
		}

		if (isset($config['implement'])) {
			NValidators::assertField($config, 'implement', 'string');
			$definition->setImplement($config['implement']);
			$definition->setAutowired(TRUE);
		}

		if (isset($config['autowired'])) {
			NValidators::assertField($config, 'autowired', 'bool');
			$definition->setAutowired($config['autowired']);
		}

		if (isset($config['inject'])) {
			NValidators::assertField($config, 'inject', 'bool');
			$definition->setInject($config['inject']);
		}

		if (isset($config['run'])) {
			$config['tags']['run'] = (bool) $config['run'];
		}

		if (isset($config['tags'])) {
			NValidators::assertField($config, 'tags', 'array');
			if (NConfigHelpers::takeParent($config['tags'])) {
				$definition->tags = array();
			}
			foreach ($config['tags'] as $tag => $attrs) {
				if (is_int($tag) && is_string($attrs)) {
					$definition->addTag($attrs);
				} else {
					$definition->addTag($tag, $attrs);
				}
			}
		}
	}



	/**
	 * Removes ... and replaces entities with NDIStatement.
	 * @return array
	 */
	public static function filterArguments(array $args)
	{
		foreach ($args as $k => $v) {
			if ($v === '...') {
				unset($args[$k]);
			} elseif ($v instanceof stdClass && isset($v->value, $v->attributes)) {
				$args[$k] = new NDIStatement($v->value, self::filterArguments($v->attributes));
			}
		}
		return $args;
	}

}
