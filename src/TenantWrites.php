<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * Splits a config.php write made while serving a tenant.
 *
 * Nextcloud persists its whole in-memory config on every write, and that
 * config holds the tenant values. A tenant key still holding the value it was
 * loaded with was not touched by this request, so config.php keeps its own
 * value for it. A tenant key holding anything else was written by this
 * request, so the value goes to the tenant's matrix entry instead. Keys the
 * tenant does not define are instance-wide settings and reach config.php
 * untouched.
 */
final class TenantWrites {
	/** @var list<string> */
	private array $keys;

	/** @var array<string,mixed>|null */
	private ?array $loaded = null;

	/**
	 * @param array<string,mixed> $served the tenant values being served, merged over the base config
	 * @param list<ConfigFile> $laterConfigFiles the *.config.php files Nextcloud loads after the shim
	 */
	public function __construct(
		private ConfigFile $configPhp,
		private ConfigFile $matrix,
		private string $pattern,
		private array $served,
		private array $laterConfigFiles,
	) {
		$this->keys = array_keys($served);
	}

	/**
	 * @param array<string,mixed> $written the whole config Nextcloud is persisting
	 * @param string|null $header the header Nextcloud wrote before $CONFIG
	 */
	public function persist(array $written, ?string $header = null): void {
		$base = $this->configPhp->read() ?? [];
		$tenantWrites = [];
		$tenantDeletes = [];

		foreach ($this->keys as $key) {
			if (!array_key_exists($key, $written)) {
				if (array_key_exists($key, $this->served)) {
					$tenantDeletes[] = $key;
				}
			} elseif (!$this->isLoadedValue($key, $written[$key])) {
				$tenantWrites[$key] = $written[$key];
			}

			if (array_key_exists($key, $base)) {
				$written[$key] = $base[$key];
			} else {
				unset($written[$key]);
			}
		}

		if ($tenantWrites !== [] || $tenantDeletes !== []) {
			$this->writeMatrixEntry($tenantWrites, $tenantDeletes);
		}

		$this->configPhp->write($written, $header);
	}

	/**
	 * The tenant values being served, after the writes persisted so far.
	 *
	 * @return array<string,mixed>
	 */
	public function served(): array {
		return $this->served;
	}

	/**
	 * Tenant keys the request deleted, which no longer have a tenant value.
	 *
	 * @return list<string>
	 */
	public function removed(): array {
		return array_values(array_diff($this->keys, array_keys($this->served)));
	}

	private function isLoadedValue(string $key, mixed $value): bool {
		if (!array_key_exists($key, $this->served)) {
			return false;
		}

		return $value === $this->served[$key] || $value === $this->loaded()[$key];
	}

	/**
	 * The tenant values as Nextcloud held them once it had read every config
	 * file: the ones loaded after the shim still merge over the tenant values.
	 *
	 * @return array<string,mixed>
	 */
	private function loaded(): array {
		if ($this->loaded !== null) {
			return $this->loaded;
		}

		$this->loaded = $this->served;
		foreach ($this->laterConfigFiles as $file) {
			$later = array_intersect_key($file->read() ?? [], $this->loaded);
			$this->loaded = array_replace_recursive($this->loaded, $later);
		}

		return $this->loaded;
	}

	/**
	 * @param array<string,mixed> $writes
	 * @param list<string> $deletes
	 */
	private function writeMatrixEntry(array $writes, array $deletes): void {
		$matrix = $this->matrix->read() ?? [];
		$entry = $matrix[$this->pattern] ?? [];
		$this->loaded();

		foreach ($writes as $key => $value) {
			$entry[$key] = $value;
			$this->served[$key] = $value;
			$this->loaded[$key] = $value;
		}
		foreach ($deletes as $key) {
			unset($entry[$key], $this->served[$key], $this->loaded[$key]);
		}

		$matrix[$this->pattern] = $entry;
		$this->matrix->write($matrix);
	}
}
