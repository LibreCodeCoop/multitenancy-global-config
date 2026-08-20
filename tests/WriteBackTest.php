<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Manager;
use LibreCode\MultiTenancyGlobalConfig\TenantMatch;
use LibreCode\MultiTenancyGlobalConfig\WriteBack;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class WriteBackTest extends TestCase {
	private const PATTERN = '/^domain01\.example\.coop$/';

	private string $configDir;
	private string $configFile;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-writeback-test');
		$this->configDir = vfsStream::url('multitenancy-writeback-test');
		$this->configFile = $this->configDir . '/config.php';
	}

	public function testMovesAWrittenTenantKeyIntoTheMatchedMatrixEntry(): void {
		$this->writeConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE, [
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
		]);
		// config.php as it was at boot, and as it is after the admin wrote to it
		$bootConfig = ['version' => '35.0.0.1', 'mail_smtphost' => 'base.example.coop'];
		$this->writeConfigArray($this->configFile, ['version' => '35.0.0.1', 'mail_smtphost' => 'written.example.coop']);

		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'written.example.coop']],
			$this->readConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE),
		);
	}

	public function testRevertsTheWrittenTenantKeyInConfigPhp(): void {
		$this->writeConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE, [
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
		]);
		$bootConfig = ['version' => '35.0.0.1', 'mail_smtphost' => 'base.example.coop'];
		$this->writeConfigArray($this->configFile, ['version' => '35.0.0.1', 'mail_smtphost' => 'written.example.coop']);

		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame(
			['version' => '35.0.0.1', 'mail_smtphost' => 'base.example.coop'],
			$this->readConfigArray($this->configFile),
		);
	}

	public function testLeavesKeysTheTenantDoesNotDefineInConfigPhp(): void {
		$this->writeConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE, [
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
		]);
		$bootConfig = ['loglevel' => 0, 'mail_smtphost' => 'base.example.coop'];
		// the admin changed loglevel, which the tenant does not define
		$this->writeConfigArray($this->configFile, ['loglevel' => 2, 'mail_smtphost' => 'base.example.coop']);

		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame(
			['loglevel' => 2, 'mail_smtphost' => 'base.example.coop'],
			$this->readConfigArray($this->configFile),
			'an instance-wide setting must stay in config.php',
		);
		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']],
			$this->readConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE),
			'the matrix must not absorb keys the tenant does not define',
		);
	}

	public function testRemovesTheWrittenKeyFromConfigPhpWhenItWasAbsentAtBoot(): void {
		$this->writeConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE, [
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
		]);
		$bootConfig = ['version' => '35.0.0.1'];
		$this->writeConfigArray($this->configFile, ['version' => '35.0.0.1', 'mail_smtphost' => 'written.example.coop']);

		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame(['version' => '35.0.0.1'], $this->readConfigArray($this->configFile));
		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'written.example.coop']],
			$this->readConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE),
		);
	}

	public function testDoesNotRewriteTheFilesWhenNoTenantKeyChanged(): void {
		$matrixFile = $this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE;
		$this->writeConfigArray($matrixFile, [self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']]);
		$bootConfig = ['mail_smtphost' => 'base.example.coop'];
		$this->writeConfigArray($this->configFile, $bootConfig);
		$configBefore = file_get_contents($this->configFile);
		$matrixBefore = file_get_contents($matrixFile);

		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame($configBefore, file_get_contents($this->configFile));
		$this->assertSame($matrixBefore, file_get_contents($matrixFile));
	}

	/**
	 * \OC\Config is instantiated twice while Nextcloud boots, so the loader
	 * runs twice and reconciliation must be safe to repeat.
	 */
	public function testIsIdempotentWhenReconciledTwice(): void {
		$matrixFile = $this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE;
		$this->writeConfigArray($matrixFile, [self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']]);
		$bootConfig = ['mail_smtphost' => 'base.example.coop'];
		$this->writeConfigArray($this->configFile, ['mail_smtphost' => 'written.example.coop']);

		$this->writeBack($bootConfig)->reconcile();
		$this->writeBack($bootConfig)->reconcile();

		$this->assertSame(['mail_smtphost' => 'base.example.coop'], $this->readConfigArray($this->configFile));
		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'written.example.coop']],
			$this->readConfigArray($matrixFile),
		);
	}

	public function testDoesNothingWhenConfigPhpIsMissing(): void {
		$this->writeConfigArray($this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE, [
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
		]);

		$this->writeBack(['mail_smtphost' => 'base.example.coop'])->reconcile();

		$this->assertFileDoesNotExist($this->configFile);
	}

	/**
	 * @param array<string,mixed> $bootConfig
	 * @param array<string,mixed> $tenantConfig
	 */
	private function writeBack(array $bootConfig, array $tenantConfig = ['mail_smtphost' => 'smtp01.example.coop']): WriteBack {
		return new WriteBack(
			new Manager($this->configDir),
			new TenantMatch(self::PATTERN, $tenantConfig),
			$this->configFile,
			$bootConfig,
		);
	}

	/** @param array<string,mixed> $data */
	private function writeConfigArray(string $file, array $data): void {
		file_put_contents($file, '<?php $CONFIG = ' . var_export($data, true) . ';');
	}

	/** @return array<string,mixed> */
	private function readConfigArray(string $file): array {
		$CONFIG = [];
		include $file;
		return $CONFIG;
	}
}
