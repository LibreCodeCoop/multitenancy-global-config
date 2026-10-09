<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Included by config/multitenancy.config.php from inside \OC\Config::readData(),
 * so this runs in the config class scope: $this is the \OC\Config instance.
 *
 * @var string $multitenancyConfigDir path to the Nextcloud config directory
 * @var string|null $multitenancyShimFile path to the shim including this file
 */

require_once __DIR__ . '/ConfigFile.php';
require_once __DIR__ . '/ConfigStream.php';
require_once __DIR__ . '/Manager.php';
require_once __DIR__ . '/TenantMatch.php';
require_once __DIR__ . '/TenantWrites.php';

$multitenancyManager = new \LibreCode\MultiTenancyGlobalConfig\Manager($multitenancyConfigDir);
$multitenancyMatch = $multitenancyManager->getMatch($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($multitenancyMatch !== null) {
	if (isset($this) && property_exists($this, 'envCache')) {
		$multitenancyBaseValues = property_exists($this, 'cache')
			? array_intersect_key($this->cache, $multitenancyMatch->config)
			: [];
		$multitenancyServed = [];
		foreach ($multitenancyMatch->config as $multitenancyKey => $multitenancyValue) {
			$multitenancyBase = $multitenancyBaseValues[$multitenancyKey] ?? null;
			$multitenancyServed[$multitenancyKey] = is_array($multitenancyValue) && is_array($multitenancyBase)
				? array_replace_recursive($multitenancyBase, $multitenancyValue)
				: $multitenancyValue;
		}
		$this->envCache = array_replace($this->envCache, $multitenancyServed);

		if (property_exists($this, 'cache') && property_exists($this, 'configFilePath')) {
			$this->cache = array_replace($this->cache, $multitenancyServed);

			$multitenancyConfigFiles = glob($multitenancyConfigDir . '/*.config.php') ?: [];
			natsort($multitenancyConfigFiles);
			$multitenancyConfigFiles = array_values($multitenancyConfigFiles);
			$multitenancyShimName = basename($multitenancyShimFile ?? 'multitenancy.config.php');
			$multitenancyShimIndex = array_search(
				$multitenancyShimName,
				array_map('basename', $multitenancyConfigFiles),
				true,
			);

			$this->configFilePath = \LibreCode\MultiTenancyGlobalConfig\ConfigStream::register(
				$this->configFilePath,
				new \LibreCode\MultiTenancyGlobalConfig\TenantWrites(
					new \LibreCode\MultiTenancyGlobalConfig\ConfigFile($this->configFilePath),
					$multitenancyManager->matrixFile(),
					$multitenancyMatch->pattern,
					$multitenancyServed,
					$multitenancyBaseValues,
					array_map(
						static fn (string $file) => new \LibreCode\MultiTenancyGlobalConfig\ConfigFile($file),
						$multitenancyShimIndex === false ? [] : array_slice($multitenancyConfigFiles, $multitenancyShimIndex + 1),
					),
				),
				fn (): array => $this->cache,
				function (\LibreCode\MultiTenancyGlobalConfig\TenantWrites $writes): void {
					$this->envCache = array_replace($this->envCache, $writes->served());
					foreach ($writes->removed() as $key) {
						unset($this->envCache[$key]);
					}
					$this->cache = array_replace($this->cache, $writes->restored());
				},
			);
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

unset(
	$multitenancyManager,
	$multitenancyMatch,
	$multitenancyServed,
	$multitenancyBaseValues,
	$multitenancyKey,
	$multitenancyValue,
	$multitenancyBase,
	$multitenancyConfigFiles,
	$multitenancyShimName,
	$multitenancyShimIndex,
);
