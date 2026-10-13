<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * Stream wrapper passing every call through to another file, except for the
 * writes and truncations it was told to fail.
 */
final class FailingStream {
	private const PROTOCOL = 'multitenancy-failing';

	/** @var array<string,string> */
	private static array $targets = [];

	/** @var array<string,array{skip:int,times:int,writtenBytes:int}> */
	private static array $failingWrites = [];

	/** @var array<string,int> */
	private static array $failingTruncates = [];

	/** @var array<string,\Closure():void> */
	private static array $beforeWrites = [];

	/** @var resource|null */
	public $context;

	/** @var resource|null */
	private $inner = null;

	private string $id = '';
	private bool $full = false;

	/**
	 * @return string the path reaching $target through this wrapper
	 */
	public static function wrap(string $target): string {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}

		$id = (string)count(self::$targets);
		self::$targets[$id] = $target;

		return self::PROTOCOL . '://' . $id;
	}

	/**
	 * After letting $skip writes through, makes the next $times writes to
	 * $path store only their first $writtenBytes bytes, as a full disk would,
	 * until the file is sought.
	 */
	public static function failWrites(string $path, int $times = 1, int $writtenBytes = 8, int $skip = 0): void {
		self::$failingWrites[self::idOf($path)] = ['skip' => $skip, 'times' => $times, 'writtenBytes' => $writtenBytes];
	}

	public static function failTruncates(string $path, int $times = 1): void {
		self::$failingTruncates[self::idOf($path)] = $times;
	}

	/**
	 * Runs $action once, right before the next write to $path.
	 *
	 * @param \Closure():void $action
	 */
	public static function beforeWrite(string $path, \Closure $action): void {
		self::$beforeWrites[self::idOf($path)] = $action;
	}

	public static function reset(): void {
		self::$failingWrites = [];
		self::$failingTruncates = [];
		self::$beforeWrites = [];
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->id = self::idOf($path);
		$inner = fopen(self::$targets[$this->id], $mode);
		if ($inner === false) {
			return false;
		}

		$this->inner = $inner;
		return true;
	}

	public function stream_read(int $count): string|false {
		return fread($this->inner, $count);
	}

	public function stream_write(string $data): int {
		if ($this->full) {
			return 0;
		}

		$action = self::$beforeWrites[$this->id] ?? null;
		if ($action !== null) {
			unset(self::$beforeWrites[$this->id]);
			$action();
		}

		$failure = self::$failingWrites[$this->id] ?? null;
		if ($failure !== null && $failure['skip'] > 0) {
			self::$failingWrites[$this->id]['skip']--;
		} elseif ($failure !== null && $failure['times'] > 0) {
			self::$failingWrites[$this->id]['times']--;
			$this->full = true;
			return (int)fwrite($this->inner, substr($data, 0, $failure['writtenBytes']));
		}

		return (int)fwrite($this->inner, $data);
	}

	public function stream_truncate(int $size): bool {
		if ((self::$failingTruncates[$this->id] ?? 0) > 0) {
			self::$failingTruncates[$this->id]--;
			return false;
		}

		return ftruncate($this->inner, $size);
	}

	public function stream_seek(int $offset, int $whence): bool {
		$this->full = false;
		return fseek($this->inner, $offset, $whence) === 0;
	}

	public function stream_tell(): int {
		return (int)ftell($this->inner);
	}

	public function stream_eof(): bool {
		return feof($this->inner);
	}

	public function stream_flush(): bool {
		return fflush($this->inner);
	}

	public function stream_lock(int $operation): bool {
		return flock($this->inner, $operation);
	}

	public function stream_close(): void {
		fclose($this->inner);
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	/** @return array<int|string,int>|false */
	public function stream_stat(): array|false {
		return fstat($this->inner);
	}

	/** @return array<int|string,int>|false */
	public function url_stat(string $path, int $flags): array|false {
		return @stat(self::$targets[self::idOf($path)]);
	}

	private static function idOf(string $path): string {
		return substr($path, strlen(self::PROTOCOL . '://'));
	}
}
