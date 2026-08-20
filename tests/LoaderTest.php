<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Manager;
use LibreCode\MultiTenancyGlobalConfig\Tests\Fake\LegacyNextcloudConfig;
use LibreCode\MultiTenancyGlobalConfig\Tests\Fake\NextcloudConfig;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase {
	private const LOADER = __DIR__ . '/../src/loader.php';

	private string $configDir;
	private ?string $originalHost = null;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-loader-test');
		$this->configDir = vfsStream::url('multitenancy-loader-test');
		$this->originalHost = $_SERVER['HTTP_HOST'] ?? null;
		file_put_contents($this->configDir . '/config.php', '<?php $CONFIG = [];');
		file_put_contents(
			$this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE,
			'<?php $CONFIG = ' . var_export([
				'/^domain01\.example\.coop$/' => [
					'mail_smtphost' => 'smtp01.example.coop',
					'trusted_domains' => ['domain01.example.coop'],
				],
			], true) . ';',
		);
	}

	protected function tearDown(): void {
		if ($this->originalHost === null) {
			unset($_SERVER['HTTP_HOST']);
		} else {
			$_SERVER['HTTP_HOST'] = $this->originalHost;
		}
	}

	public function testWritesTheTenantConfigIntoTheNonPersistedChannel(): void {
		$_SERVER['HTTP_HOST'] = 'domain01.example.coop';
		$config = new NextcloudConfig(['mail_smtphost' => 'base.example.coop']);

		$config->includeLoader(self::LOADER, $this->configDir);

		$this->assertSame([
			'mail_smtphost' => 'smtp01.example.coop',
			'trusted_domains' => ['domain01.example.coop'],
		], $config->envCache());
	}

	public function testDoesNotDefineConfigWhenTheNonPersistedChannelIsUsed(): void {
		$_SERVER['HTTP_HOST'] = 'domain01.example.coop';
		$config = new NextcloudConfig();

		$defined = $config->includeLoader(self::LOADER, $this->configDir);

		$this->assertNull($defined, 'defining $CONFIG would leak the tenant config into config.php');
	}

	public function testLeavesTheChannelEmptyWhenNoTenantMatches(): void {
		$_SERVER['HTTP_HOST'] = 'unknown.example.coop';
		$config = new NextcloudConfig();

		$defined = $config->includeLoader(self::LOADER, $this->configDir);

		$this->assertSame([], $config->envCache());
		$this->assertNull($defined);
	}

	public function testFallsBackToConfigWhenTheNonPersistedChannelIsGone(): void {
		$_SERVER['HTTP_HOST'] = 'domain01.example.coop';
		$config = new LegacyNextcloudConfig();

		$defined = null;
		$this->captureWarnings(function () use ($config, &$defined): void {
			$defined = $config->includeLoader(self::LOADER, $this->configDir);
		});

		$this->assertSame([
			'mail_smtphost' => 'smtp01.example.coop',
			'trusted_domains' => ['domain01.example.coop'],
		], $defined, 'the tenant must still be served when the channel is unavailable');
	}

	public function testWarnsWhenFallingBackToTheMergedConfig(): void {
		$_SERVER['HTTP_HOST'] = 'domain01.example.coop';
		$config = new LegacyNextcloudConfig();

		$warnings = $this->captureWarnings(function () use ($config): void {
			$config->includeLoader(self::LOADER, $this->configDir);
		});

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('envCache', $warnings[0]);
	}

	/**
	 * Runs $body with E_USER_WARNING captured instead of reported, so the
	 * loader's fallback warning does not fail the test run.
	 *
	 * @return string[]
	 */
	private function captureWarnings(callable $body): array {
		$warnings = [];
		set_error_handler(
			function (int $severity, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			},
			E_USER_WARNING,
		);

		try {
			$body();
		} finally {
			restore_error_handler();
		}

		return $warnings;
	}
}
