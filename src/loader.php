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

require_once __DIR__ . '/Manager.php';

$multitenancyConfig = \LibreCode\MultiTenancyGlobalConfig\Manager::getConfigFromHost(
	$multitenancyConfigDir,
	$_SERVER['HTTP_HOST'] ?? 'localhost',
);

if ($multitenancyConfig !== []) {
	if (isset($this) && property_exists($this, 'envCache')) {
		foreach ($multitenancyConfig as $multitenancyKey => $multitenancyValue) {
			// Same merge Nextcloud applies to every *.config.php it loads, so a
			// tenant overriding one sub-key keeps the rest of the base value.
			$multitenancyBase = $this->cache[$multitenancyKey] ?? null;
			$this->envCache[$multitenancyKey] = is_array($multitenancyValue) && is_array($multitenancyBase)
				? array_replace_recursive($multitenancyBase, $multitenancyValue)
				: $multitenancyValue;
		}
	} else {
		trigger_error(
			'multitenancy-global-config: \OC\Config::$envCache is unavailable, '
			. 'falling back to the merged config. Tenant values will leak into '
			. 'config.php on the next system config write.',
			E_USER_WARNING,
		);
		$CONFIG = $multitenancyConfig;
	}
}

unset($multitenancyConfig, $multitenancyKey, $multitenancyValue, $multitenancyBase);
