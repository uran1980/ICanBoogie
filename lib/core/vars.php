<?php

/*
 * This file is part of the ICanBoogie package.
 *
 * (c) Olivier Laviale <olivier.laviale@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ICanBoogie;

/**
 * Accessor for the variables stored as files in the "/repository/var" directory.
 */
class Vars implements \ArrayAccess, \IteratorAggregate
{
	/**
	 * Magic pattern used to recognized automatically serialized values.
	 *
	 * @var string
	 */
	const MAGIC = "VAR\0SLZ\0";

	/**
	 * Length of the magic pattern {@link MAGIC}.
	 *
	 * @var int
	 */
	const MAGIC_LENGTH = 8;

	static private $release_after;

	/**
	 * Absolute path to the vars directory.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to the vars directory.
	 *
	 * @throws Exception when the path is not writable.
	 */
	public function __construct($path)
	{
		$this->path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		if (self::$release_after === null)
		{
			self::$release_after = strpos(PHP_OS, 'WIN') === 0 ? false : true;
		}

		if (!is_writable($this->path))
		{
			throw new Exception('The directory %directory is not writable.', array('directory' => $this->path));
		}
	}

	/**
	 * Stores the value of a var using the {@link store()} method.
	 *
	 * @see \ArrayAccess::offsetSet()
	 */
	public function offsetSet($name, $value)
	{
		$this->store($name, $value);
	}

	/**
	 * Checks if the var exists.
	 *
	 * @param string $name Name of the variable.
	 *
	 * @return bool true if the var exists, false otherwise.
	 *
	 * @see \ArrayAccess::offsetExists()
	 */
	public function offsetExists($name)
	{
		$pathname = $this->path . $name;

		return file_exists($pathname);
	}

	/**
	 * Deletes a var.
	 *
	 * @see \ArrayAccess::offsetUnset()
	 */
	public function offsetUnset($name)
	{
		$pathname = $this->path . $name;

		if (!file_exists($pathname))
		{
			return;
		}

		unlink($pathname);
	}

	/**
	 * Returns the value of the var using the {@link retrieve()} method.
	 *
	 * @see \ArrayAccess::offsetGet()
	 */
	public function offsetGet($name)
	{
		return $this->retrieve($name);
	}

	/**
	 * Cache a variable in the repository.
	 *
	 * If the value is an array or a string it is serialized and prepended with a magic
	 * identifier. This magic identifier is used to recognized previously serialized values when
	 * they are read back.
	 *
	 * @param string $key The key used to identify the value. Keys are unique, so storing a second
	 * value with the same key will overwrite the previous value.
	 * @param mixed $value The value to store for the key.
	 * @param int $ttl The time to live in seconds for the stored value. If no _ttl_ is supplied
	 * (or if the _tll_ is __0__), the value will persist until it is removed from the cache
	 * manually or otherwise fails to exist in the cache.
	 *
	 * @throws Exception when a file operation fails.
	 */
	public function store($key, $value, $ttl=0)
	{
		$pathname = $this->path . $key;
		$ttl_mark = $pathname . '.ttl';

		if ($ttl)
		{
			$future = time() + $ttl;

			touch($ttl_mark, $future, $future);
		}
		else if (file_exists($ttl_mark))
		{
			unlink($ttl_mark);
		}

		$dir = dirname($pathname);

		if (!file_exists($dir))
		{
			mkdir($dir, 0705, true);
		}

		$uniq_id = uniqid(mt_rand(), true);
		$tmp_pathname = $dir . '/var-' . $uniq_id;
		$garbage_pathname = $dir . '/garbage-var-' . $uniq_id;

		#
		# If the value is an array or a string it is serialized and prepended with a magic
		# identifier.
		#

		if (is_array($value) || is_object($value))
		{
			$value = self::MAGIC . serialize($value);
		}

		if ($value === true)
		{
			touch($pathname);
		}
		else if ($value === false || $value === null)
		{
			$this->offsetUnset($key);
		}
		else
		{
			#
			# We lock the file create/update, but we write the data in a temporary file, which is then
			# renamed once the data is written.
			#

			$fh = fopen($pathname, 'a+');

			if (!$fh)
			{
				throw new Exception('Unable to open %pathname: :message', array('pathname' => $pathname, 'message' => Debug::$last_error_message));
			}

			if (self::$release_after && !flock($fh, LOCK_EX))
			{
				throw new Exception('Unable to get to exclusive lock on %pathname: :message', array('pathname' => $pathname, 'message' => Debug::$last_error_message));
			}

			file_put_contents($tmp_pathname, $value);

			#
			# Windows, this is for you
			#
			if (!self::$release_after)
			{
				fclose($fh);
			}

			if (!rename($pathname, $garbage_pathname))
			{
				throw new Exception('Unable to rename %old as %new.', array('old' => $pathname, 'new' => $garbage_pathname));
			}

			if (!rename($tmp_pathname, $pathname))
			{
				throw new Exception('Unable to rename %old as %new.', array('old' => $tmp_pathname, 'new' => $pathname));
			}

			if (!unlink($garbage_pathname))
			{
				throw new Exception('Unable to delete %pathname: :message', array('pathname' => $garbage_pathname, 'message' => Debug::$last_error_message));
			}

			if (self::$release_after)
			{
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		}
	}

	/**
	 * Returns the value of variable.
	 *
	 * If the value is marked with the magic identifier it is not serialized.
	 *
	 * @param string $name
	 * @param mixed $default The value returned if the variable does not exists. Defaults to null.
	 *
	 * @return mixed
	 */
	public function retrieve($name, $default=null)
	{
		$pathname = $this->path . $name;
		$ttl_mark = $pathname . '.ttl';

		if (file_exists($ttl_mark) && fileatime($ttl_mark) < time() || !file_exists($pathname))
		{
			return $default;
		}

		$value = file_get_contents($pathname);

		if (substr($value, 0, self::MAGIC_LENGTH) == self::MAGIC)
		{
			$value = unserialize(substr($value, self::MAGIC_LENGTH));
		}

		return $value;
	}

	/**
	 * Returns a directory iterator for the variables.
	 *
	 * @return VarsIterator
	 *
	 * @see IteratorAggregate::getIterator()
	 */
	public function getIterator()
	{
		$iterator = new \DirectoryIterator($this->path);

		return new VarsIterator($iterator);
	}

	/**
	 * Returns a file iterator for variables matching a regex.
	 *
	 * @param string $regex
	 *
	 * @return \ICanBoogie\VarsIterator
	 */
	public function matching($regex)
	{
		$dir = new \DirectoryIterator($this->path);
		$filter = new \RegexIterator($dir, $regex);

		return new VarsIterator($filter);
	}
}

/**
 * Iterates through variables.
 *
 * The iterator is usually created using the Vars::matching() method.
 *
 * The iterator can also be used to delete matching variables.
 */
class VarsIterator implements \Iterator
{
	/**
	 * Iterator.
	 *
	 * @var \Iterator
	 */
	protected $iterator;

	public function __construct(\Iterator $iterator)
	{
		$this->iterator = $iterator;
	}

	/**
	 * Returns the directory iterator.
	 *
	 * Dot files are skipped.
	 *
	 * @return \DirectoryIterator
	 */
	public function current()
	{
		$file = $this->iterator->current();

		if ($file->isDot())
		{
			$this->iterator->next();

			$file = $this->current();
		}

		return $file;
	}

	public function next()
	{
		$this->iterator->next();
	}

	/**
	 * Returns the pathname of the variable, which is the same as its key.
	 *
	 * @return string
	 */
	public function key()
	{
		return $this->iterator->current()->getFilename();
	}

	public function valid()
	{
		return $this->iterator->valid();
	}

	public function rewind()
	{
		$this->iterator->rewind();
	}

	/**
	 * Deletes the variables found by the iterator.
	 */
	public function delete()
	{
		foreach ($this->iterator as $file)
		{
 			unlink($file->getPathname());
		}
	}
}