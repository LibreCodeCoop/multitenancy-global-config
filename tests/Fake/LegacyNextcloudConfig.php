<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * A \OC\Config without envCache, standing in for a Nextcloud release that
 * dropped or renamed the non-persisted channel the loader relies on.
 */
class LegacyNextcloudConfig {
	/** @param array<string,mixed> $cache */
	public function __construct(
		protected array $cache = [],
	) {
	}

	/** @return array<string,mixed>|null */
	public function includeLoader(string $loaderFile, string $configDir): ?array {
		$multitenancyConfigDir = $configDir;

		include $loaderFile;

		return $CONFIG ?? null;
	}
}
