<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * Stands in for \OC\Config: same members the loader relies on, same visibility.
 */
class NextcloudConfig {
	/** @var array<string,mixed> */
	protected array $envCache = [];

	/** @param array<string,mixed> $cache */
	public function __construct(
		protected array $cache = [],
	) {
	}

	/** @return array<string,mixed>|null the $CONFIG the loader defined, if any */
	public function includeLoader(string $loaderFile, string $configDir): ?array {
		$multitenancyConfigDir = $configDir;

		include $loaderFile;

		return $CONFIG ?? null;
	}

	/**
	 * Includes the config shim the way Nextcloud does, without handing it a
	 * config directory: the shim takes its own.
	 *
	 * @return string|null the module loader the shim required, if any
	 */
	public function includeShim(string $shimFile): ?string {
		$multitenancyRequiredLoader = null;

		include $shimFile;

		return $multitenancyRequiredLoader;
	}

	/** @return array<string,mixed> */
	public function envCache(): array {
		return $this->envCache;
	}
}
