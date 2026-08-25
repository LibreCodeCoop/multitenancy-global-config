<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * A \OC\Config that kept envCache but no longer has cache.
 */
class EnvCacheOnlyNextcloudConfig {
	/** @var array<string,mixed> */
	protected array $envCache = [];

	/** @return array<string,mixed>|null */
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
