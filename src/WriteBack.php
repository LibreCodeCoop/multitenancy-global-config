<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * Routes system config writes back to the tenant matrix.
 *
 * Nextcloud persists its whole merged config cache into config.php on every
 * write (\OC\Config::writeData()) and offers no hook before that happens. So
 * the tenant values are injected into the non-persisted channel and this class
 * reconciles config.php afterwards: a tenant key whose value changed during
 * the request was written by an admin and belongs to the tenant, so it is
 * moved into the matrix and reverted in config.php.
 *
 * Keys the tenant does not define are left untouched in config.php: they are
 * instance-wide settings and none of the module's business.
 */
final class WriteBack {
	/**
	 * @param ConfigFile $nextcloudConfig the Nextcloud config.php
	 * @param array<string,mixed> $bootConfig config.php contents at boot
	 */
	public function __construct(
		private ConfigFile $nextcloudConfig,
		private ConfigFile $matrix,
		private TenantMatch $match,
		private array $bootConfig,
	) {
	}

	/**
	 * Defers reconciliation to the end of the request, after Nextcloud has had
	 * its chance to write config.php.
	 *
	 * Nextcloud instantiates \OC\Config twice while booting, so this may run
	 * twice; reconciliation is idempotent.
	 */
	public function register(): void {
		register_shutdown_function($this->reconcile(...));
	}

	public function reconcile(): void {
		$current = $this->nextcloudConfig->read();
		if ($current === null) {
			return;
		}

		$writes = [];
		foreach (array_keys($this->match->config) as $key) {
			if (!array_key_exists($key, $current)) {
				continue;
			}
			if (array_key_exists($key, $this->bootConfig)) {
				if ($current[$key] === $this->bootConfig[$key]) {
					continue;
				}
				$writes[$key] = $current[$key];
				$current[$key] = $this->bootConfig[$key];
			} else {
				$writes[$key] = $current[$key];
				unset($current[$key]);
			}
		}

		if ($writes === []) {
			return;
		}

		$matrix = $this->matrix->read() ?? [];
		foreach ($writes as $key => $value) {
			$matrix[$this->match->pattern][$key] = $value;
		}
		$this->matrix->write($matrix);
		$this->nextcloudConfig->write($current);
	}
}
