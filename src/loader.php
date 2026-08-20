<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader body.
 *
 * Nextcloud includes config/multitenancy.config.php from inside
 * \OC\Config::readData(), and that file includes this one, so this code runs in
 * the \OC\Config class scope: $this is the config instance and its protected
 * members are reachable.
 *
 * That is what makes a leak-free tenant config possible. Values assigned to
 * $CONFIG are merged into \OC\Config::$cache, which writeData() dumps into
 * config.php in full on every single write — so a tenant value assigned that
 * way becomes instance-wide config the moment anything calls setSystemValue().
 * $envCache, the channel behind the NC_* environment variables, is read with
 * priority and never persisted, so tenant values go there instead.
 *
 * Requires $multitenancyConfigDir to be set by the including file: the path to
 * the Nextcloud config directory holding the tenant matrix.
 *
 * @var string $multitenancyConfigDir
 */

require_once __DIR__ . '/Manager.php';
require_once __DIR__ . '/TenantMatch.php';
require_once __DIR__ . '/WriteBack.php';

$multitenancyManager = new \LibreCode\MultiTenancyGlobalConfig\Manager($multitenancyConfigDir);
$multitenancyMatch = $multitenancyManager->getMatch($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($multitenancyMatch !== null) {
	if (isset($this) && property_exists($this, 'envCache')) {
		// Reconcile config.php after Nextcloud has written it: tenant keys the
		// admin changed during this request belong in the matrix, not here.
		(new \LibreCode\MultiTenancyGlobalConfig\WriteBack(
			$multitenancyManager,
			$multitenancyMatch,
			$multitenancyConfigDir . '/config.php',
			$this->cache,
		))->register();

		foreach ($multitenancyMatch->config as $multitenancyKey => $multitenancyValue) {
			$this->envCache[$multitenancyKey] = $multitenancyValue;
		}
	} else {
		trigger_error(
			'multitenancy-global-config: \OC\Config::$envCache is unavailable, '
			. 'falling back to the merged config. Tenant values will leak into '
			. 'config.php on the next system config write.',
			E_USER_WARNING,
		);
		$CONFIG = $multitenancyMatch->config;
	}
}

unset($multitenancyManager, $multitenancyMatch, $multitenancyKey, $multitenancyValue);
