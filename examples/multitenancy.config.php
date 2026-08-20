<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader. Copy this file to the Nextcloud config/ directory.
 *
 * Nextcloud auto-loads every config/*.config.php file from inside
 * \OC\Config::readData(), which is what lets the module reach the config
 * instance. Keep this file a thin shim: it only says where the tenant matrix
 * lives and where the module is installed.
 *
 * Loading chain:
 *   multitenancy.database.php -> multitenancy.config.php -> src/loader.php
 *
 * Adjust the require path below to where this module is installed.
 */

$multitenancyConfigDir = __DIR__;

require __DIR__ . '/../apps-extra/multitenancy-global-config/src/loader.php';
