<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * A matrix entry that matched the request host.
 *
 * The pattern is kept alongside the config because write-back needs to know
 * which matrix entry a value belongs to.
 */
final class TenantMatch {
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		public readonly string $pattern,
		public readonly array $config,
	) {
	}
}
