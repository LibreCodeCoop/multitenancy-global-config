<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * Stands in for \OC\Config: same members the loader relies on, same visibility.
 *
 * Nextcloud includes the loader from inside \OC\Config::readData(), so it runs
 * in the class scope and can reach protected members. Including it from here
 * exercises that exact mechanism.
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

	/** @return array<string,mixed> */
	public function envCache(): array {
		return $this->envCache;
	}
}
