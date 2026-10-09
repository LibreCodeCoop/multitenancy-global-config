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
  request host (`$_SERVER['HTTP_HOST']`) and hands it to
  `Manager::getMatch()`, which matches it against the regex keys of the
  matrix and returns the matching pattern and entry — or null when nothing
  matches. `Manager::getConfigFromHost()` remains for shims copied from
  earlier versions, which call it directly.

### How the shim finds the module

The shim carries no path to this module: it looks for it in the app
directories the instance is configured with. Nextcloud only resolves those
into `OC::$APPSROOTS` *after* the config is read, so the shim reads
`apps_paths` from the config merged so far — it is set in `config.php` or, as
on the official image, in `apps.config.php`, and both are read before the
shim — and falls back to the default `apps/` directory when it is not set, the
same as Nextcloud does.

A directory named exactly `multitenancy-global-config` is preferred; failing
that, any directory whose name ends with it is accepted. When several match,
the shim raises an `E_USER_WARNING` listing them and loads the first.

Nothing is looked up by app id, so this module is not a Nextcloud app and does
not need enabling; the directory only has to sit in one of those paths. If it
is in none of them, the shim raises an `E_USER_WARNING` naming the directories
it searched, rather than silently serving every tenant the base config.

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

Because Nextcloud reads that channel first, a tenant value also wins over a
`*.config.php` loaded after the shim — on the official image, the `smtp`,
`redis`, `s3` and `reverse-proxy` files generated from environment variables.
Those files still apply to every host no tenant matches.

If a future Nextcloud release drops that channel, the loader falls back to
`$CONFIG` and raises an `E_USER_WARNING` saying so, rather than failing the
boot silently.

### How tenant values merge

A tenant value that is an array is merged over the base value with
`array_replace_recursive`, exactly as Nextcloud merges every `*.config.php` it
loads. So a tenant overriding `redis.port` keeps the base `redis.host`.

Lists merge by index, which is the same rule — a tenant listing fewer entries
than the base inherits the leftovers. Write tenant lists in full, especially
`trusted_domains`.

### Changing tenant config

Writes made while a tenant is served — `occ config:system:set` and
`config:system:delete`, the admin settings forms, background jobs — go where
they belong:

- a key the tenant defines is written to the tenant's entry in the matrix
  file, and config.php keeps its own value for it. For a map such as `redis`,
  only what differs from the base value is kept, so later base changes still
  reach the tenant; a list is kept whole;
- any other key is an instance-wide setting and is written to config.php, as
  usual.

`OC\Config::writeData()` has no hook, but it writes through its
`configFilePath` property. The loader points that property at a stream
wrapper, so every config.php write reaches the module as it happens, with the
whole config Nextcloud is persisting. The tenant values are also loaded into
`OC\Config::$cache`, so `set()` compares against the value the admin sees: a
settings form saved untouched writes nothing for the tenant, and a tenant
value set back to the base value is not dropped.

A tenant key still holding the value it was served with was not written by
the request. That includes a value another `*.config.php` loaded after the
shim merged over it, so such a file never ends up in the matrix.

Deleting a tenant key removes it from the matrix entry, and the tenant falls
back to the base value, from that request on.

config.php and the matrix are each read, changed and rewritten under one
exclusive lock, and read under a shared one, as Nextcloud does for
config.php, so concurrent writes never see a half-written file nor drop each
other's changes. A write that fails is reported and not retried.

Writes made for a host no tenant matches, such as `occ` without `HTTP_HOST`,
are left to Nextcloud untouched.

### Known limits

- A tenant value of `null` is ignored: Nextcloud probes the channel with
  `isset()`, so the base value applies. Omit the key instead.
- Other `*.config.php` files still get flattened into config.php by Nextcloud
  on the next write. That is upstream behaviour and unrelated to tenants, but
  it is why config.php keeps changing.
- Writing back a tenant matrix rewrites it with `var_export()`: everything
  before its `$CONFIG` assignment is kept, but comments inside the array are
  lost.
- The web server needs write access to the matrix file, as it already has to
  config.php.

## Installation

1. Clone this module (or add it as a git submodule) into any of the app
   directories of your instance — whatever `apps_paths` lists in `config.php`,
   or `apps/` when it lists nothing:

   ```bash
   cd /path/to/nextcloud/apps
   git clone https://github.com/LibreCodeCoop/multitenancy-global-config.git
   ```

2. Copy `examples/multitenancy.config.php` to your Nextcloud `config/`
   directory, as is — it finds the module by itself.
3. Create `config/multitenancy.database.php` with your tenant matrix
   (see `examples/multitenancy.database.php`).

To keep the module somewhere else entirely — outside the webroot, say — it is
still location-agnostic: in the copied shim, replace the lookup with a plain
`require` of the module's `src/loader.php`.

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
