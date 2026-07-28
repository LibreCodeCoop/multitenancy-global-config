<!--
  - SPDX-FileCopyrightText: 2026 LibreCode Coop
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Multi-tenancy Global Config

Multi-tenant global config loader for Nextcloud: resolves `$CONFIG` per request
host from a domain config matrix.

## Problem

A single Nextcloud instance serving multiple domains (e.g.
`domain01.example.coop`, `domain02.example.coop`) may need different settings
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
  reads the request host (`$_SERVER['HTTP_HOST']`, falling back to `localhost`)
  and hands it to `Manager::getConfigFromHost()`, which matches it against the
  regex keys of the matrix and returns the matching tenant config — or an empty
  array when nothing matches. Reading the superglobal is the loader's job, so
  the `Manager` itself stays free of global state.

## Installation

This module is location-agnostic: clone it (or add it as a git submodule)
anywhere the PHP process can read — inside or outside the Nextcloud webroot —
and adjust the `require` path in the loader accordingly.

```bash
git clone https://github.com/LibreCodeCoop/multitenancy-global-config.git
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
    '/^domain01\.example\.coop$/' => [
        'dbname' => 'tenant01',
        'mail_smtphost' => 'smtp01.example.coop',
    ],
];
```

The matrix file name can be overridden with the `MULTITENANCY_CONFIG_FILE`
environment variable (relative to the `config/` directory).

A matrix key that is not a valid regex raises a `RuntimeException`: a broken
matrix fails loudly instead of silently routing tenants to the base config.

## Trust boundary

The `Host` header is fully client-controlled. Anchored regex keys (`/^...$/`)
prevent one tenant from selecting another tenant's config, but a host that
matches no key falls through to the **base** Nextcloud config. Configure your
reverse proxy to route only known tenant hosts to this instance.

## CLI (occ, cron, background jobs)

CLI processes have no `Host` header, so they fall back to `localhost` and run
against the base config. To run a command as a specific tenant, set `HTTP_HOST`
in the environment (PHP CLI exposes environment variables in `$_SERVER`):

```bash
HTTP_HOST=domain01.example.coop php occ maintenance:mode --on
```

## Development

```bash
composer install
composer test
```

## License

[AGPL-3.0-or-later](LICENSE)
