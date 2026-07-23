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

	public function __construct(
		private string $configDir,
	) {
	}

	/**
	 * Returns the tenant config matching the given host, or an empty array
	 * when there is no match.
	 */
	public function getConfig(string $host): array {
		return [];
	}
}
