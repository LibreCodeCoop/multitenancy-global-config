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

	private const FILE_WARNING = "\n/*\n * This file is rewritten by multitenancy-global-config.\n * Comments and formatting are lost when that happens.\n */\n";

	public function __construct(
		private string $configDir,
	) {
	}

	/**
	 * Entry point for the `multitenancy.config.php` loader: resolves the
	 * tenant config for the given request host.
	 */
	public static function getConfigFromHost(string $configDir, string $host): array {
		return (new self($configDir))->getConfig($host);
	}

	/**
	 * Returns the tenant config matching the given host, or an empty array
	 * when there is no match.
	 *
	 * @throws \RuntimeException when a matrix key is not a valid regex
	 */
	public function getConfig(string $host): array {
		return $this->getMatch($host)?->config ?? [];
	}

	/**
	 * Returns the matrix entry matching the given host, or null when there is
	 * no match. The host is normalized before matching: the port is stripped
	 * and the name is lowercased.
	 *
	 * @throws \RuntimeException when a matrix key is not a valid regex
	 */
	public function getMatch(string $host): ?TenantMatch {
		$host = preg_replace('/:\d+$/', '', strtolower($host));
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
				return new TenantMatch($pattern, $tenantConfig);
			}
		}
		return null;
	}

	/**
	 * Reads the matrix file, mapping host regex patterns to tenant configs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function readMatrix(): array {
		return $this->readConfigArray($this->matrixPath()) ?? [];
	}

	/**
	 * @param array<string,array<string,mixed>> $matrix
	 */
	public function writeMatrix(array $matrix): void {
		$this->writeConfigArray($this->matrixPath(), $matrix);
	}

	/**
	 * Reads a PHP config file. Mirrors \OC\Config::readData(): include the
	 * file and pick up the $CONFIG variable it defines. Returns null when the
	 * file is missing or defines no $CONFIG array.
	 *
	 * @return array<string,mixed>|null
	 */
	public function readConfigArray(string $file): ?array {
		if (!file_exists($file)) {
			return null;
		}

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($file, true);
		}

		unset($CONFIG);
		include $file;

		return isset($CONFIG) && is_array($CONFIG) ? $CONFIG : null;
	}

	/**
	 * Writes a PHP config file, holding an exclusive lock while doing so.
	 * Everything the file had before its $CONFIG assignment is preserved, so
	 * hand-written comments survive a rewrite.
	 *
	 * @param array<string,mixed> $data
	 */
	public function writeConfigArray(string $file, array $data): void {
		$content = $this->headerOf($file) . '$CONFIG = ' . var_export($data, true) . ";\n";

		$pointer = fopen($file, 'c+');
		if ($pointer === false) {
			throw new \RuntimeException(sprintf('Could not open %s for writing', $file));
		}

		try {
			if (!flock($pointer, LOCK_EX)) {
				throw new \RuntimeException(sprintf('Could not acquire an exclusive lock on %s', $file));
			}
			ftruncate($pointer, 0);
			fwrite($pointer, $content);
			fflush($pointer);
			flock($pointer, LOCK_UN);
		} finally {
			fclose($pointer);
		}

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($file, true);
		}
	}

	/**
	 * Returns everything preceding the file's $CONFIG assignment, or a default
	 * header when the file has none yet.
	 *
	 * The assignment is matched at the start of a line, because Nextcloud's own
	 * warning block quotes "$CONFIG = [];" inside a comment.
	 */
	private function headerOf(string $file): string {
		$existing = file_exists($file) ? (string)file_get_contents($file) : '';

		if (preg_match('/^\$CONFIG\s*=/m', $existing, $matches, PREG_OFFSET_CAPTURE) === 1) {
			return substr($existing, 0, $matches[0][1]);
		}

		return "<?php\n" . self::FILE_WARNING;
	}

	private function matrixPath(): string {
		$fileName = getenv(self::ENV_CONFIG_FILE) ?: self::DEFAULT_CONFIG_FILE;
		return $this->configDir . '/' . $fileName;
	}
}
