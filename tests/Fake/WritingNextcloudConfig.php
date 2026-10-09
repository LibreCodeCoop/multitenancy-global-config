<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * Stands in for \OC\Config down to how it writes config.php: the same
 * members, the same comparison against the cache, and the same file calls.
 */
class WritingNextcloudConfig {
	/** @var array<string,mixed> */
	protected array $cache = [];

	/** @var array<string,mixed> */
	protected array $envCache = [];

	public function __construct(
		protected string $configFilePath,
	) {
		include $this->configFilePath;
		$this->cache = $CONFIG;
	}

	public function includeLoader(string $loaderFile, string $configDir): void {
		$multitenancyConfigDir = $configDir;

		include $loaderFile;
	}

	public function configFilePath(): string {
		return $this->configFilePath;
	}

	public function getValue(string $key, mixed $default = null): mixed {
		return $this->envCache[$key] ?? $this->cache[$key] ?? $default;
	}

	/** @param array<string,mixed> $configs */
	public function setValues(array $configs): void {
		$needsUpdate = false;
		foreach ($configs as $key => $value) {
			if ($value !== null) {
				$needsUpdate = $this->set($key, $value) || $needsUpdate;
			} else {
				$needsUpdate = $this->delete($key) || $needsUpdate;
			}
		}

		if ($needsUpdate) {
			$this->writeData();
		}
	}

	private function set(string $key, mixed $value): bool {
		if (!isset($this->cache[$key]) || $this->cache[$key] !== $value) {
			$this->cache[$key] = $value;
			return true;
		}

		return false;
	}

	private function delete(string $key): bool {
		if (isset($this->cache[$key])) {
			unset($this->cache[$key]);
			return true;
		}

		return false;
	}

	private function writeData(): void {
		$content = "<?php\n/* nextcloud warning */\n";
		$content .= '$CONFIG = ';
		$content .= var_export($this->cache, true);
		$content .= ";\n";

		touch($this->configFilePath);
		$filePointer = fopen($this->configFilePath, 'r+');
		chmod($this->configFilePath, 0640);
		flock($filePointer, LOCK_EX);
		ftruncate($filePointer, 0);
		fwrite($filePointer, $content);
		fflush($filePointer);
		flock($filePointer, LOCK_UN);
		fclose($filePointer);
	}
}
