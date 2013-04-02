<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Loaders
 */



/**
 * Nette auto loader is responsible for loading classes and interfaces.
 *
 * @author     David Grudl
 *
 * @property-read array $indexedClasses
 * @property   ICacheStorage $cacheStorage
 * @package Nette\Loaders
 */
class NRobotLoader extends NAutoLoader
{
	const RETRY_LIMIT = 3;

	/** @var string|array  comma separated wildcards */
	public $ignoreDirs = '.*, *.old, *.bak, *.tmp, temp';

	/** @var array of file extension => callback */
	public $filters = array(
		'php' => NULL,
		'php5' => NULL,
	);

	/** @var bool */
	public $autoRebuild = TRUE;

	/** @var array */
	private $scanDirs = array();

	/** @var array of lowered-class => [file, time, orig, filter] or num-of-retry */
	private $classes = array();

	/** @var bool */
	private $rebuilt = FALSE;

	/** @var array of missing classes in this request */
	private $missing = array();

	/** @var ICacheStorage */
	private $cacheStorage;

	/** @var NPhpFileStorage */
	private $phpCacheStorage;



	public function __construct()
	{
		if (!extension_loaded('tokenizer')) {
			throw new NotSupportedException("PHP extension Tokenizer is not loaded.");
		}
	}



	/**
	 * Register autoloader.
	 * @param  bool  prepend autoloader?
	 * @return NRobotLoader  provides a fluent interface
	 */
	public function register()
	{
		$this->classes = $this->getCache()->load($this->getKey(), new NCallback($this, '_rebuildCallback'));
		parent::register();
		return $this;
	}



	/**
	 * Handles autoloading of classes, interfaces or traits.
	 * @param  string
	 * @return void
	 */
	public function tryLoad($type)
	{
		$type = ltrim(strtolower($type), '\\'); // PHP namespace bug #49143

		$info = & $this->classes[$type];
		if (isset($this->missing[$type]) || (is_int($info) && $info >= self::RETRY_LIMIT)) {
			return;
		}

		if ($this->autoRebuild) {
			if (!is_array($info) || !is_file($info['file'])) {
				$info = is_int($info) ? $info + 1 : 0;
				if ($this->rebuilt) {
					$this->getCache()->save($this->getKey(), $this->classes);
				} else {
					$this->rebuild();
				}
			} elseif (!$this->rebuilt && (filemtime($info['file']) !== $info['time']
				|| (!empty($info['filter']) && !$this->getPhpCache()->load($info['file']))
			)) {
				$this->updateFile($info['file']);
				if (!isset($this->classes[$type])) {
					$this->classes[$type] = 0;
				}
				$this->getCache()->save($this->getKey(), $this->classes);
			}
		}

		if (isset($this->classes[$type]['file'])) {
			if (empty($this->classes[$type]['filter'])) {
				NLimitedScope::load($this->classes[$type]['file'], TRUE);
			} else {
				$item = $this->getPhpCache()->load($this->classes[$type]['file']);
				NLimitedScope::load($item['file'], TRUE);
			}
			self::$count++;
		} else {
			$this->missing[$type] = TRUE;
		}
	}



	/**
	 * Add directory (or directories) to list.
	 * @param  string|array
	 * @return NRobotLoader  provides a fluent interface
	 * @throws DirectoryNotFoundException if path is not found
	 */
	public function addDirectory($path)
	{
		foreach ((array) $path as $val) {
			$real = realpath($val);
			if ($real === FALSE) {
				throw new DirectoryNotFoundException("Directory '$val' not found.");
			}
			$this->scanDirs[] = $real;
		}
		return $this;
	}



	/**
	 * @return array of class => filename
	 */
	public function getIndexedClasses()
	{
		$res = array();
		foreach ($this->classes as $class => $info) {
			if (is_array($info)) {
				$res[$info['orig']] = $info['file'];
			}
		}
		return $res;
	}



	/**
	 * Rebuilds class list cache.
	 * @return void
	 */
	public function rebuild()
	{
		$this->rebuilt = TRUE; // prevents calling rebuild() or updateFile() in tryLoad()
		$this->getCache()->save($this->getKey(), new NCallback($this, '_rebuildCallback'));
	}



	/**
	 * @internal
	 */
	public function _rebuildCallback()
	{
		$files = $missing = array();
		foreach ($this->classes as $class => $info) {
			if (is_array($info)) {
				$files[$info['file']]['time'] = $info['time'];
				$files[$info['file']]['filter'] = !empty($info['filter']);
				$files[$info['file']]['classes'][] = $info['orig'];
			} else {
				$missing[$class] = $info;
			}
		}

		$this->classes = array();
		foreach (array_unique($this->scanDirs) as $dir) {
			foreach ($this->createFileIterator($dir) as $file) {
				$file = $file->getPathname();
				if (isset($files[$file]) && $files[$file]['time'] == filemtime($file)) {
					$classes = $files[$file]['classes'];
					$filtered = $files[$file]['filter'];
				} else {
					list($classes, $filtered) = $this->processFile($file);
				}

				foreach ($classes as $class) {
					$info = & $this->classes[strtolower($class)];
					if (isset($info['file'])) {
						throw new InvalidStateException("Ambiguous class $class resolution; defined in {$info['file']} and in $file.");
					}
					$info = array('file' => $file, 'time' => filemtime($file), 'orig' => $class);
					if ($filtered) {
						$info['filter'] = TRUE;
					}
				}
			}
		}
		$this->classes += $missing;
		return $this->classes;
	}



	/**
	 * Creates an iterator scaning directory for PHP files, subdirectories and 'netterobots.txt' files.
	 * @return Iterator
	 */
	private function createFileIterator($dir)
	{
		if (!is_dir($dir)) {
			return new ArrayIterator(array(new SplFileInfo($dir)));
		}

		$ignoreDirs = is_array($this->ignoreDirs) ? $this->ignoreDirs : preg_split('#[,\s]+#', $this->ignoreDirs);
		$disallow = array();
		foreach ($ignoreDirs as $item) {
			if ($item = realpath($item)) {
				$disallow[$item] = TRUE;
			}
		}

		$iterator = NFinder::findFiles(array_map(create_function('$ext', ' return "*.$ext"; '), array_keys($this->filters)))
			->filter(create_function('$file', 'extract($GLOBALS[0]['.array_push($GLOBALS[0], array('disallow'=>&$disallow)).'-1], EXTR_REFS);
				return !isset($disallow[$file->getPathname()]);
			'))
			->from($dir)
			->exclude($ignoreDirs)
			->filter($filter = create_function('$dir', 'extract($GLOBALS[0]['.array_push($GLOBALS[0], array('disallow'=>&$disallow)).'-1], EXTR_REFS);
				$path = $dir->getPathname();
				if (is_file("$path/netterobots.txt")) {
					foreach (file("$path/netterobots.txt") as $s) {
						if (preg_match(\'#^(?:disallow\\\\s*:)?\\\\s*(\\\\S+)#i\', $s, $matches)) {
							$disallow[$path . str_replace(\'/\', DIRECTORY_SEPARATOR, rtrim(\'/\' . ltrim($matches[1], \'/\'), \'/\'))] = TRUE;
						}
					}
				}
				return !isset($disallow[$path]);
			'));

		$filter(new SplFileInfo($dir));
		return $iterator;
	}



	/**
	 * @return void
	 */
	private function updateFile($file)
	{
		foreach ($this->classes as $class => $info) {
			if (isset($info['file']) && $info['file'] === $file) {
				unset($this->classes[$class]);
			}
		}

		if (is_file($file)) {
			list($classes, $filtered) = $this->processFile($file);
			foreach ($classes as $class) {
				$info = & $this->classes[strtolower($class)];
				if (isset($info['file']) && @filemtime($info['file']) !== $info['time']) { // intentionally ==, file may not exists
					$this->updateFile($info['file']);
					$info = & $this->classes[strtolower($class)];
				}
				if ($this->rebuilt) { // caused by processFile() or previous updateFile()
					return;
				}
				if (isset($info['file'])) {
					throw new InvalidStateException("Ambiguous class $class resolution; defined in {$info['file']} and in $file.");
				}
				$info = array('file' => $file, 'time' => filemtime($file), 'orig' => $class);
				if ($filtered) {
					$info['filter'] = TRUE;
				}
			}
		}
	}



	/**
	 * @return array [classes, filtered?]
	 */
	private function processFile($file)
	{
		$filtered = FALSE;
		$code = file_get_contents($file);
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if (!empty($this->filters[$ext])) {
			$res = call_user_func($this->filters[$ext], $code);
			if ($filtered = ($code !== $res)) {
				$this->getPhpCache()->save($file, $code = $res);
			}
		}
		return array($this->scanPhp($code), $filtered);
	}



	/**
	 * Searches classes, interfaces and traits in PHP file.
	 * @param  string
	 * @return array
	 */
	private function scanPhp($code)
	{
		$T_NAMESPACE = PHP_VERSION_ID < 50300 ? -1 : T_NAMESPACE;
		$T_NS_SEPARATOR = PHP_VERSION_ID < 50300 ? -1 : T_NS_SEPARATOR;
		$T_TRAIT = PHP_VERSION_ID < 50400 ? -1 : T_TRAIT;

		$expected = FALSE;
		$namespace = '';
		$level = $minLevel = 0;
		$classes = array();

		if (preg_match('#//nette'.'loader=(\S*)#', $code, $matches)) {
			foreach (explode(',', $matches[1]) as $name) {
				$classes[] = $name;
			}
			return $classes;
		}

		foreach (@token_get_all($code) as $token) { // intentionally @
			if (is_array($token)) {
				switch ($token[0]) {
				case T_COMMENT:
				case T_DOC_COMMENT:
				case T_WHITESPACE:
					continue 2;

				case $T_NS_SEPARATOR:
				case T_STRING:
					if ($expected) {
						$name .= $token[1];
					}
					continue 2;

				case $T_NAMESPACE:
				case T_CLASS:
				case T_INTERFACE:
				case $T_TRAIT:
					$expected = $token[0];
					$name = '';
					continue 2;
				case T_CURLY_OPEN:
				case T_DOLLAR_OPEN_CURLY_BRACES:
					$level++;
				}
			}

			if ($expected) {
				switch ($expected) {
				case T_CLASS:
				case T_INTERFACE:
				case $T_TRAIT:
					if ($level === $minLevel) {
						$classes[] = $namespace . $name;
					}
					break;

				case $T_NAMESPACE:
					$namespace = $name ? $name . '\\' : '';
					$minLevel = $token === '{' ? 1 : 0;
				}

				$expected = NULL;
			}

			if ($token === '{') {
				$level++;
			} elseif ($token === '}') {
				$level--;
			}
		}
		return $classes;
	}



	/********************* backend ****************d*g**/



	/**
	 * @return NRobotLoader
	 */
	public function setCacheStorage(ICacheStorage $storage, NPhpFileStorage $phpCacheStorage = NULL)
	{
		$this->cacheStorage = $storage;
		$this->phpCacheStorage = $phpCacheStorage;
		return $this;
	}



	/**
	 * @return ICacheStorage
	 */
	public function getCacheStorage()
	{
		return $this->cacheStorage;
	}



	/**
	 * @return NCache
	 */
	protected function getCache()
	{
		if (!$this->cacheStorage) {
			trigger_error('Missing cache storage.', E_USER_WARNING);
			$this->cacheStorage = new NDevNullStorage;
		}
		return new NCache($this->cacheStorage, 'Nette.RobotLoader');
	}



	/**
	 * @return NCache
	 */
	protected function getPhpCache()
	{
		return new NCache($this->phpCacheStorage, 'Nette.RobotLoader.filters');
	}



	/**
	 * @return string
	 */
	protected function getKey()
	{
		return array($this->ignoreDirs, array_keys($this->filters), $this->scanDirs);
	}

}
