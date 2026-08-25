<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Tests\Fake\NextcloudConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers examples/multitenancy.config.php, the file an admin copies into the
 * Nextcloud config directory: it has to find the module on its own.
 *
 * The app directories are real fixture directories rather than vfsStream ones
 * because the lookup globs for the module, and glob() does not see stream
 * wrappers.
 */
final class ShimTest extends TestCase {
	use CapturesWarnings;

	private const SHIM = __DIR__ . '/../examples/multitenancy.config.php';
	private const APPS = __DIR__ . '/fixtures/apps';
	private const APPS_EXTRA = __DIR__ . '/fixtures/apps-extra';

	public function testRequiresTheModuleFromAConfiguredAppDirectory(): void {
		$config = new NextcloudConfig(['apps_paths' => [
			['path' => self::APPS, 'url' => '/apps'],
			['path' => self::APPS_EXTRA, 'url' => '/apps-extra'],
		]]);

		$required = $config->includeShim(self::SHIM);

		$this->assertSame(
			self::APPS_EXTRA . '/nextcloud-multitenancy-global-config/src/loader.php',
			$required,
		);
	}

	public function testWarnsWhenNoAppDirectoryHoldsTheModule(): void {
		$config = new NextcloudConfig(['apps_paths' => [
			['path' => self::APPS, 'url' => '/apps'],
		]]);

		$required = null;
		$warnings = $this->captureWarnings(function () use ($config, &$required): void {
			$required = $config->includeShim(self::SHIM);
		});

		$this->assertNull($required, 'an unrelated app must not be taken for the module');
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString(self::APPS, $warnings[0]);
	}

	public function testLooksIntoTheDefaultAppDirectoryWhenNoneAreConfigured(): void {
		$config = new NextcloudConfig();

		$warnings = $this->captureWarnings(function () use ($config): void {
			$config->includeShim(self::SHIM);
		});

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString(dirname(realpath(self::SHIM)) . '/../apps', $warnings[0]);
	}
}
