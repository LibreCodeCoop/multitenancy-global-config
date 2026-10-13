<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader. Copy this file to the Nextcloud config/ directory.
 *
 * Nextcloud auto-loads every config/*.config.php file; this shim looks the
 * module up in the app directories of this instance and tells it where the
 * tenant matrix lives. Cloning the module into one of those directories is
 * then the whole installation, with no path to adjust here.
 *
 * To keep the module outside of them, drop the lookup below and require its
 * src/loader.php directly.
 */

$multitenancyConfigDir = __DIR__;
$multitenancyShimFile = __FILE__;

/*
 * Nextcloud only resolves its app directories after reading the config, so
 * take them from the config it has merged so far: apps_paths is set either in
 * config.php or, as on the official image, in apps.config.php, and both are
 * read before this file.
 */
$multitenancyAppDirs = array_column($this->cache['apps_paths'] ?? [], 'path')
	?: [__DIR__ . '/../apps'];

$multitenancyFound = [];
foreach (['multitenancy-global-config', '*multitenancy-global-config'] as $multitenancyDirName) {
	foreach ($multitenancyAppDirs as $multitenancyAppDir) {
		$multitenancyFound = array_merge(
			$multitenancyFound,
			glob($multitenancyAppDir . '/' . $multitenancyDirName . '/src/loader.php') ?: [],
		);
	}
	if ($multitenancyFound !== []) {
		break;
	}
}
$multitenancyLoader = $multitenancyFound[0] ?? null;

if (count($multitenancyFound) > 1) {
	trigger_error(
		'multitenancy-global-config: several copies of the module were found ('
		. implode(', ', $multitenancyFound)
		. '); loading ' . $multitenancyLoader . '.',
		E_USER_WARNING,
	);
}

if ($multitenancyLoader === null) {
	trigger_error(
		'multitenancy-global-config: the module was not found in '
		. implode(', ', $multitenancyAppDirs)
		. '. Every tenant is being served the base config.',
		E_USER_WARNING,
	);
} else {
	require $multitenancyLoader;
}

unset(
	$multitenancyConfigDir,
	$multitenancyShimFile,
	$multitenancyAppDirs,
	$multitenancyAppDir,
	$multitenancyDirName,
	$multitenancyFound,
	$multitenancyLoader,
);
