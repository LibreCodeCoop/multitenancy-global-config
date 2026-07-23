<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader. Copy this file to the Nextcloud config/ directory.
 *
 * Nextcloud auto-loads every config/*.config.php file and merges its $CONFIG
 * into the global config (see \OC\Config::readData()). This loader resolves
 * the tenant config for the current request host from the tenant matrix.
 *
 * Loading chain:
 *   multitenancy.database.php -> multitenancy.config.php -> Nextcloud global config
 *
 * Adjust the require_once path below to where this module is installed.
 */
require_once __DIR__ . '/../apps-extra/nextcloud-multitenancy-global-config/src/Manager.php';

$CONFIG = \LibreCode\MultiTenancyGlobalConfig\Manager::getConfigFromEnvironment(__DIR__);
