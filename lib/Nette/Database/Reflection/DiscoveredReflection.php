<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Database\Reflection
 */



/**
 * Reflection metadata class with discovery for a database.
 *
 * @author     Jan Skrasek
 * @package Nette\Database\Reflection
 */
class NDiscoveredReflection extends NObject implements IReflection
{
	/** @var NConnection */
	protected $connection;

	/** @var NCache */
	protected $cache;

	/** @var array */
	protected $structure = array();

	/** @var array */
	protected $loadedStructure;



	/**
	 * Create autodiscovery structure.
	 */
	public function __construct(NConnection $connection, ICacheStorage $cacheStorage = NULL)
	{
		$this->connection = $connection;
		if ($cacheStorage) {
			$this->cache = new NCache($cacheStorage, 'Nette.Database.' . md5($connection->getDsn()));
			$this->structure = $this->loadedStructure = ($tmp=$this->cache->load('structure')) ? $tmp : array();
		}
	}



	public function __destruct()
	{
		if ($this->cache && $this->structure !== $this->loadedStructure) {
			$this->cache->save('structure', $this->structure);
		}
	}



	public function getPrimary($table)
	{
		$primary = & $this->structure['primary'][strtolower($table)];
		if (isset($primary)) {
			return empty($primary) ? NULL : $primary;
		}

		$columns = $this->connection->getSupplementalDriver()->getColumns($table);
		$primary = array();
		foreach ($columns as $column) {
			if ($column['primary']) {
				$primary[] = $column['name'];
			}
		}

		if (count($primary) === 0) {
			return NULL;
		} elseif (count($primary) === 1) {
			$primary = reset($primary);
		}

		return $primary;
	}



	public function getHasManyReference($table, $key, $refresh = TRUE)
	{
		if (isset($this->structure['hasMany'][strtolower($table)])) {
			$candidates = $columnCandidates = array();
			foreach ($this->structure['hasMany'][strtolower($table)] as $targetPair) {
				list($targetColumn, $targetTable) = $targetPair;
				if (stripos($targetTable, $key) === FALSE) {
					continue;
				}

				$candidates[] = array($targetTable, $targetColumn);
				if (stripos($targetColumn, $table) !== FALSE) {
					$columnCandidates[] = $candidate = array($targetTable, $targetColumn);
					if (strtolower($targetTable) === strtolower($key)) {
						return $candidate;
					}
				}
			}

			if (count($columnCandidates) === 1) {
				return reset($columnCandidates);
			} elseif (count($candidates) === 1) {
				return reset($candidates);
			}

			foreach ($candidates as $candidate) {
				list($targetTable, $targetColumn) = $candidate;
				if (strtolower($targetTable) === strtolower($key)) {
					return $candidate;
				}
			}
		}

		if ($refresh) {
			$this->reloadAllForeignKeys();
			return $this->getHasManyReference($table, $key, FALSE);
		}

		if (empty($candidates)) {
			throw new NMissingReferenceException("No reference found for \${$table}->related({$key}).");
		} else {
			throw new NAmbiguousReferenceKeyException('Ambiguous joining column in related call.');
		}
	}



	public function getBelongsToReference($table, $key, $refresh = TRUE)
	{
		if (isset($this->structure['belongsTo'][strtolower($table)])) {
			foreach ($this->structure['belongsTo'][strtolower($table)] as $column => $targetTable) {
				if (stripos($column, $key) !== FALSE) {
					return array($targetTable, $column);
				}
			}
		}

		if ($refresh) {
			$this->reloadForeignKeys($table);
			return $this->getBelongsToReference($table, $key, FALSE);
		}

		throw new NMissingReferenceException("No reference found for \${$table}->{$key}.");
	}



	protected function reloadAllForeignKeys()
	{
		foreach ($this->connection->getSupplementalDriver()->getTables() as $table) {
			if ($table['view'] == FALSE) {
				$this->reloadForeignKeys($table['name']);
			}
		}

		foreach ($this->structure['hasMany'] as & $table) {
			uksort($table, create_function('$a, $b', '
				return strlen($a) - strlen($b);
			'));
		}
	}



	protected function reloadForeignKeys($table)
	{
		foreach ($this->connection->getSupplementalDriver()->getForeignKeys($table) as $row) {
			$this->structure['belongsTo'][strtolower($table)][$row['local']] = $row['table'];
			$this->structure['hasMany'][strtolower($row['table'])][$row['local'] . $table] = array($row['local'], $table);
		}

		if (isset($this->structure['belongsTo'][$table])) {
			uksort($this->structure['belongsTo'][$table], create_function('$a, $b', '
				return strlen($a) - strlen($b);
			'));
		}
	}

}
