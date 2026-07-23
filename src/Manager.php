<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * Resolves the Nextcloud $CONFIG of a tenant from a per-domain config matrix.
 *
 * The matrix file lives in the Nextcloud config directory under a name that
 * intentionally does not match the `*.config.php` pattern, so Nextcloud does
 * not load it directly (see \OC\Config::readData()).
 */
final class Manager {
	public const DEFAULT_CONFIG_FILE = 'multitenancy.database.php';
	public const ENV_CONFIG_FILE = 'MULTITENANCY_CONFIG_FILE';

	public function __construct(
		private string $configDir,
	) {
	}

	/**
	 * Entry point for the `multitenancy.config.php` loader: resolves the
	 * tenant config for the current request host.
	 */
	public static function getConfigFromEnvironment(string $configDir): array {
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$host = preg_replace('/:\d+$/', '', strtolower($host));
		return (new self($configDir))->getConfig($host);
	}

	/**
	 * Returns the tenant config matching the given host, or an empty array
	 * when there is no match.
	 *
	 * @throws \RuntimeException when a matrix key is not a valid regex
	 */
	public function getConfig(string $host): array {
		foreach ($this->readMatrix() as $pattern => $tenantConfig) {
			$result = @preg_match($pattern, $host);
			if ($result === false) {
				throw new \RuntimeException(sprintf(
					'Invalid regex "%s" in multi-tenancy config matrix: %s',
					$pattern,
					preg_last_error_msg(),
				));
			}
			if ($result === 1) {
				return $tenantConfig;
			}
		}
		return [];
	}

	/**
	 * Reads the matrix file, mapping host regex patterns to tenant configs.
	 * Reading mirrors \OC\Config::readData(): include the file and pick up
	 * the $CONFIG variable it defines.
	 */
	private function readMatrix(): array {
		$fileName = getenv(self::ENV_CONFIG_FILE) ?: self::DEFAULT_CONFIG_FILE;
		$file = $this->configDir . '/' . $fileName;
		if (!file_exists($file)) {
			return [];
		}

		include $file;

		if (!isset($CONFIG) || !is_array($CONFIG)) {
			return [];
		}
		return $CONFIG;
	}
}
