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

Nextcloud includes every `config/*.config.php` file from inside
`OC\Config::readData()`, so the loader runs in the config class scope — `$this`
is the `OC\Config` instance. That is what makes a leak-free tenant config
possible:

```
multitenancy.database.php  ->  multitenancy.config.php  ->  src/loader.php
(tenant matrix, NOT            (thin shim, auto-loaded      (resolves the host and
auto-loaded by Nextcloud)      by Nextcloud)                injects the tenant config)
```

- The **tenant matrix** lives in `config/multitenancy.database.php`. The file
  name intentionally does **not** end in `config.php`, so Nextcloud does not
  load it directly.
- The **loader shim** `config/multitenancy.config.php` only points at the
  matrix directory and this module. The body in `src/loader.php` reads the
  request host (`$_SERVER['HTTP_HOST']`) and hands it to `Manager::getMatch()`,
  which matches it against the regex keys of the matrix and returns the
  matching entry — or null when nothing matches.

### Why not `$CONFIG`

Assigning `$CONFIG` in a `*.config.php` file merges the values into
`OC\Config::$cache`, and `writeData()` dumps that **entire** cache into
config.php on every single write. So one `occ config:system:set` — or
`maintenance:repair`, an upgrade, a settings form, a background job — would
turn the current tenant's values into instance-wide config, permanently.

Instead, tenant values are written into `OC\Config::$envCache`, the channel
behind the `NC_*` environment variables: Nextcloud reads it with priority and
never persists it. Unlike real `NC_*` variables, which `getenv()` can only
deliver as strings, this accepts arrays and booleans.

If a future Nextcloud release drops that channel, the loader falls back to
`$CONFIG` and raises an `E_USER_WARNING` saying so, rather than failing the
boot silently.

### Write-back

Because tenant values are never persisted, a write to one of them would
otherwise land in config.php as instance-wide config and be shadowed on the
next request. So the loader registers a shutdown reconciliation:

- A key **the tenant defines** whose value changed during the request was
  written by an admin and belongs to the tenant: it is moved into the matched
  matrix entry and reverted in config.php.
- Any **other** key is left alone in config.php. Those are instance-wide
  settings and none of this module's business.

Reconciliation is idempotent, which matters because Nextcloud instantiates
`OC\Config` twice while booting. Comments in both files survive a rewrite.

## Installation

This module is location-agnostic: clone it (or add it as a git submodule)
anywhere the PHP process can read — inside or outside the Nextcloud webroot —
and adjust the `require` path in the loader accordingly.

```bash
git clone https://github.com/LibreCodeCoop/multitenancy-global-config.git
```

1. Copy `examples/multitenancy.config.php` to your Nextcloud `config/`
   directory and adjust the `require` path to where you cloned this
   repository.
2. Create `config/multitenancy.database.php` with your tenant matrix
   (see `examples/multitenancy.database.php`).

The web server user needs write access to both files for write-back to work.

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
