<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests\Fake;

/**
 * A \OC\Config without the non-persisted channel.
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
