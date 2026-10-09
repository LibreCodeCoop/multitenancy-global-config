<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * Stream wrapper standing in for config.php while a tenant is served.
 *
 * \OC\Config::writeData() offers no hook, but it writes through its
 * configFilePath property. Pointing that property at this wrapper hands every
 * write to TenantWrites, which persists it to config.php and the tenant matrix.
 *
 * The wrapper implements exactly the calls writeData() makes: touch, chmod,
 * fopen in r+ mode, flock, ftruncate, fwrite, fflush and fclose.
 */
final class ConfigStream {
	public const PROTOCOL = 'multitenancy-config';

	/** @var array<string,array{writes:TenantWrites,read:\Closure():array<string,mixed>,serve:\Closure(TenantWrites):void,path:string}> */
	private static array $targets = [];

	/** @var resource|null */
	public $context;

	private string $id = '';
	private string $buffer = '';
	private bool $dirty = false;

	/**
	 * @param string $path the real config.php
	 * @param \Closure():array<string,mixed> $read returns the config Nextcloud is persisting
	 * @param \Closure(TenantWrites):void $serve called after a write, to serve the updated tenant values
	 * @return string the path to write config.php through
	 */
	public static function register(string $path, TenantWrites $writes, \Closure $read, \Closure $serve): string {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}

		$id = (string)count(self::$targets);
		self::$targets[$id] = ['writes' => $writes, 'read' => $read, 'serve' => $serve, 'path' => $path];

		return self::PROTOCOL . '://' . $id;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->id = self::idOf($path);

		return isset(self::$targets[$this->id]);
	}

	public function stream_write(string $data): int {
		$this->buffer .= $data;
		$this->dirty = true;

		return strlen($data);
	}

	public function stream_truncate(int $size): bool {
		$this->buffer = substr($this->buffer, 0, $size);
		$this->dirty = true;

		return true;
	}

	public function stream_flush(): bool {
		if (!$this->dirty) {
			return true;
		}

		$target = self::$targets[$this->id];
		$target['writes']->persist(($target['read'])(), ConfigFile::headerOf($this->buffer));
		($target['serve'])($target['writes']);
		$this->dirty = false;

		return true;
	}

	public function stream_close(): void {
		$this->stream_flush();
	}

	public function stream_lock(int $operation): bool {
		return true;
	}

	public function stream_eof(): bool {
		return true;
	}

	/** @return array<int|string,int> */
	public function stream_stat(): array {
		return [];
	}

	/** @return array<int|string,int>|false */
	public function url_stat(string $path, int $flags): array|false {
		$target = self::$targets[self::idOf($path)] ?? null;

		return $target === null ? false : @stat($target['path']);
	}

	public function stream_metadata(string $path, int $option, mixed $value): bool {
		$target = self::$targets[self::idOf($path)] ?? null;
		if ($target === null) {
			return false;
		}

		return match ($option) {
			STREAM_META_TOUCH => touch($target['path']),
			STREAM_META_ACCESS => chmod($target['path'], $value),
			default => false,
		};
	}

	private static function idOf(string $path): string {
		return substr($path, strlen(self::PROTOCOL . '://'));
	}
}
