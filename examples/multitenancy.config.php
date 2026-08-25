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

/*
 * Nextcloud only resolves its app directories after reading the config, so
 * take them from the config it has merged so far -- config.php comes first,
 * and that is where apps_paths is defined.
 */
$multitenancyAppDirs = array_column($this->cache['apps_paths'] ?? [], 'path')
	?: [__DIR__ . '/../apps'];

$multitenancyLoader = null;
foreach ($multitenancyAppDirs as $multitenancyAppDir) {
	$multitenancyFound = glob($multitenancyAppDir . '/*multitenancy-global-config/src/loader.php') ?: [];
	if ($multitenancyFound !== []) {
		$multitenancyLoader = $multitenancyFound[0];
		break;
	}
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
	$multitenancyAppDirs,
	$multitenancyAppDir,
	$multitenancyFound,
	$multitenancyLoader,
);
