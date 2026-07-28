<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * AUTO-GENERATED FILE - DO NOT EDIT MANUALLY.
 *
 * Tenant matrix: maps host regex patterns to Nextcloud $CONFIG overrides.
 * The file name intentionally does not end in `config.php`, so Nextcloud
 * does not load it directly.
 *
 * Loading chain:
 *   multitenancy.database.php -> multitenancy.config.php -> Nextcloud global config
 */
$CONFIG = [
	'/^domain01\.example\.coop$/' => [
		'dbname' => 'tenant01',
		'mail_smtphost' => 'smtp01.example.coop',
	],
	'/^domain02\.example\.coop$/' => [
		'dbname' => 'tenant02',
		'mail_smtphost' => 'smtp02.example.coop',
	],
];
