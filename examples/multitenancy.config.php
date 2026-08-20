<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Multi-tenancy loader. Copy this file to the Nextcloud config/ directory and
 * adjust the require path to where this module is installed.
 *
 * Nextcloud auto-loads every config/*.config.php file; this shim only says
 * where the tenant matrix and the module live.
 */

$multitenancyConfigDir = __DIR__;

require __DIR__ . '/../apps-extra/multitenancy-global-config/src/loader.php';
