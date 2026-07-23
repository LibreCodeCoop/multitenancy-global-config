<!--
  - SPDX-FileCopyrightText: 2026 LibreCode Coop
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Nextcloud Multi-tenancy Global Config

Multi-tenant global config loader for Nextcloud: resolves `$CONFIG` per request
host from a domain config matrix.

## Problem

A single Nextcloud instance serving multiple domains (e.g.
`dominio01.exemplo.coop`, `dominio02.exemplo.coop`) may need different settings
per domain: database (`dbname`, `dbhost`, ...), SMTP (`mail_smtphost`), data
directory (`datadirectory`), etc. Nextcloud has no structured way to load
configuration based on the request host.

## How it works

Nextcloud automatically loads and merges every `config/*.config.php` file into
the global `$CONFIG` (see `OC\Config::readData()`). This module leverages that:

```
multitenancy.database.php  ->  multitenancy.config.php  ->  Nextcloud global config
(tenant matrix, NOT            (auto-loaded loader,         (merged per request)
auto-loaded by Nextcloud)      calls this module)
```

- The **tenant matrix** lives in `config/multitenancy.database.php`. The file
  name intentionally does **not** end in `config.php`, so Nextcloud does not
  load it directly.
- The **loader** `config/multitenancy.config.php` (auto-loaded by Nextcloud)
  calls `Manager::getConfigFromEnvironment()`, which matches the current
  request host (`$_SERVER['HTTP_HOST']`, falling back to `localhost`) against
  the regex keys of the matrix and returns the matching tenant config —
  or an empty array when nothing matches.

## Installation

This module is location-agnostic: clone it (or add it as a git submodule)
anywhere the PHP process can read — inside or outside the Nextcloud webroot —
and adjust the `require` path in the loader accordingly.

```bash
git clone https://github.com/LibreCodeCoop/nextcloud-multitenancy-global-config.git
```

1. Copy `examples/multitenancy.config.php` to your Nextcloud `config/`
   directory and adjust the `require_once` path to where you cloned this
   repository.
2. Create `config/multitenancy.database.php` with your tenant matrix
   (see `examples/multitenancy.database.php`).

## Tenant matrix format

Keys are complete regular expressions (with delimiters) matched against the
request host. The first matching key wins. Values are regular Nextcloud
`$CONFIG` arrays merged over the global config.

```php
<?php
$CONFIG = [
    '/^dominio01\.exemplo\.coop$/' => [
        'dbname' => 'tenant01',
        'mail_smtphost' => 'smtp01.exemplo.coop',
    ],
];
```

The matrix file name can be overridden with the `MULTITENANCY_CONFIG_FILE`
environment variable (relative to the `config/` directory).

## Development

```bash
composer install
composer test
```

## License

[AGPL-3.0-or-later](LICENSE)
