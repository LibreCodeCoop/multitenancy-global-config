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
	private ?array $later = null;

	/** @var array<string,mixed>|null */
	private ?array $loaded = null;

	/**
	 * @param array<string,mixed> $served the tenant values being served, merged over the base config
	 * @param array<string,mixed> $base the base values of the tenant keys, before the tenant merged over them
	 * @param list<ConfigFile> $laterConfigFiles the *.config.php files Nextcloud loads after the shim
	 */
	public function __construct(
		private ConfigFile $configPhp,
		private ConfigFile $matrix,
		private string $pattern,
		private array $served,
		private array $base,
		private array $laterConfigFiles,
	) {
		$this->keys = array_keys($served);
	}

	/**
	 * Writes the matrix entry first and config.php last, both under the
	 * config.php lock. When config.php cannot be written, the matrix entry is
	 * rolled back, so a failed write leaves both files as they were.
	 *
	 * @param array<string,mixed> $written the whole config Nextcloud is persisting
	 * @param string|null $header the header Nextcloud wrote before $CONFIG
	 * @throws \RuntimeException when a file cannot be written
	 */
	public function persist(array $written, ?string $header = null): void {
		$tenantWrites = [];
		$tenantDeletes = [];
		$previousEntry = null;

		try {
			$this->configPhp->update(function (?array $configPhp) use ($written, &$tenantWrites, &$tenantDeletes, &$previousEntry): array {
				foreach ($this->keys as $key) {
					if (array_key_exists($key, $written)) {
						if (!$this->isLoadedValue($key, $written[$key])) {
							$tenantWrites[$key] = $written[$key];
						}
					} elseif (array_key_exists($key, $this->served)) {
						$tenantDeletes[] = $key;
					}

					if (array_key_exists($key, $configPhp ?? [])) {
						$written[$key] = $configPhp[$key];
					} else {
						unset($written[$key]);
					}
				}

				if ($tenantWrites !== [] || $tenantDeletes !== []) {
					$previousEntry = $this->writeMatrixEntry($tenantWrites, $tenantDeletes);
				}

				return $written;
			}, $header);
		} catch (\RuntimeException $e) {
			if ($previousEntry !== null) {
				$this->rollBackMatrixEntry([...array_keys($tenantWrites), ...$tenantDeletes], $previousEntry, $e);
			}
			throw $e;
		}

		$this->serve($tenantWrites, $tenantDeletes);
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

	/**
	 * The base values that apply again to the deleted tenant keys.
	 *
	 * @return array<string,mixed>
	 */
	public function restored(): array {
		return array_intersect_key($this->loaded(), array_flip($this->removed()));
	}

	private function isLoadedValue(string $key, mixed $value): bool {
		if (array_key_exists($key, $this->served) && $value === $this->served[$key]) {
			return true;
		}

		return array_key_exists($key, $this->loaded()) && $value === $this->loaded()[$key];
	}

	/**
	 * What Nextcloud held for each tenant key once it had read every config
	 * file: the ones loaded after the shim still merge over the tenant values.
	 *
	 * @return array<string,mixed>
	 */
	private function loaded(): array {
		return $this->loaded ??= array_replace_recursive($this->served, $this->later());
	}

	/**
	 * @return array<string,mixed>
	 */
	private function later(): array {
		if ($this->later !== null) {
			return $this->later;
		}

		$this->later = [];
		foreach ($this->laterConfigFiles as $file) {
			$values = array_intersect_key($file->read() ?? [], array_flip($this->keys));
			$this->later = array_replace_recursive($this->later, $values);
		}

		return $this->later;
	}

	/**
	 * @param array<string,mixed> $writes
	 * @param list<string> $deletes
	 * @return array<string,mixed> the entry as it was before
	 */
	private function writeMatrixEntry(array $writes, array $deletes): array {
		$previousEntry = [];
		$this->matrix->update(function (?array $matrix) use ($writes, $deletes, &$previousEntry): array {
			$entry = $previousEntry = $matrix[$this->pattern] ?? [];
			foreach ($writes as $key => $value) {
				$entry[$key] = $this->tenantValue($key, $value);
			}
			foreach ($deletes as $key) {
				unset($entry[$key]);
			}

			$matrix ??= [];
			$matrix[$this->pattern] = $entry;
			return $matrix;
		});

		return $previousEntry;
	}

	/**
	 * Puts back the given keys of the matrix entry, leaving any other change
	 * made to the matrix in the meantime.
	 *
	 * @param list<string> $keys
	 * @param array<string,mixed> $previousEntry
	 * @throws \RuntimeException when the matrix cannot be written
	 */
	private function rollBackMatrixEntry(array $keys, array $previousEntry, \RuntimeException $cause): void {
		try {
			$this->matrix->update(function (?array $matrix) use ($keys, $previousEntry): array {
				$entry = $matrix[$this->pattern] ?? [];
				foreach ($keys as $key) {
					if (array_key_exists($key, $previousEntry)) {
						$entry[$key] = $previousEntry[$key];
					} else {
						unset($entry[$key]);
					}
				}

				$matrix ??= [];
				$matrix[$this->pattern] = $entry;
				return $matrix;
			});
		} catch (\RuntimeException $e) {
			throw new \RuntimeException(
				sprintf('%s, and the tenant matrix entry could not be rolled back: %s', $cause->getMessage(), $e->getMessage()),
				0,
				$cause,
			);
		}
	}

	/**
	 * @param array<string,mixed> $writes
	 * @param list<string> $deletes
	 */
	private function serve(array $writes, array $deletes): void {
		$this->loaded();
		foreach ($writes as $key => $value) {
			$this->served[$key] = $value;
			$this->loaded[$key] = $value;
		}
		foreach ($deletes as $key) {
			unset($this->served[$key], $this->loaded[$key]);
			$baseValue = array_replace_recursive(
				array_intersect_key($this->base, [$key => true]),
				array_intersect_key($this->later(), [$key => true]),
			);
			$this->loaded += $baseValue;
		}
	}

	/**
	 * The value to keep in the matrix entry so that, merged over the base
	 * value the way Nextcloud merges config files, it yields the written one.
	 * Only what differs from the base is kept in a map, so later changes to
	 * the base still reach the tenant. Lists are kept whole, as tenant lists
	 * are meant to be written in full.
	 */
	private function tenantValue(string $key, mixed $value): mixed {
		$base = $this->base[$key] ?? null;
		if (!is_array($value) || !is_array($base) || array_is_list($value)) {
			return $value;
		}

		$overrides = self::overrides($value, $base);

		return array_replace_recursive($base, $overrides) === $value ? $overrides : $value;
	}

	/**
	 * @param array<array-key,mixed> $value
	 * @param array<array-key,mixed> $base
	 * @return array<array-key,mixed>
	 */
	private static function overrides(array $value, array $base): array {
		$overrides = [];
		foreach ($value as $key => $item) {
			if (!array_key_exists($key, $base)) {
				$overrides[$key] = $item;
			} elseif (is_array($item) && is_array($base[$key]) && !array_is_list($item)) {
				$nested = self::overrides($item, $base[$key]);
				if ($nested !== []) {
					$overrides[$key] = $nested;
				}
			} elseif ($item !== $base[$key]) {
				$overrides[$key] = $item;
			}
		}

		return $overrides;
	}
}
