<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader body, included by config/multitenancy.config.php.
 *
 * Nextcloud includes that file from inside \OC\Config::readData(), so this code
 * runs in the config class scope: $this is the \OC\Config instance. Tenant
 * values go into $envCache, which Nextcloud reads with priority and never
 * persists; $CONFIG would end up in config.php instance-wide on the next write.
 *
 * @var string $multitenancyConfigDir path to the Nextcloud config directory
 */

require_once __DIR__ . '/ConfigFile.php';
require_once __DIR__ . '/Manager.php';
require_once __DIR__ . '/TenantMatch.php';
require_once __DIR__ . '/WriteBack.php';

$multitenancyManager = new \LibreCode\MultiTenancyGlobalConfig\Manager($multitenancyConfigDir);
$multitenancyMatch = $multitenancyManager->getMatch($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($multitenancyMatch !== null) {
	if (isset($this) && property_exists($this, 'envCache')) {
		(new \LibreCode\MultiTenancyGlobalConfig\WriteBack(
			new \LibreCode\MultiTenancyGlobalConfig\ConfigFile($multitenancyConfigDir . '/config.php'),
			$multitenancyManager->matrixFile(),
			$multitenancyMatch,
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
