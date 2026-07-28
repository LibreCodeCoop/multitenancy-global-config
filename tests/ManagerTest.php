<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Manager;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase {
	private string $configDir;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-test');
		$this->configDir = vfsStream::url('multitenancy-test');
	}

	protected function tearDown(): void {
		putenv(Manager::ENV_CONFIG_FILE);
	}

	public function testReturnsEmptyArrayWhenMatrixFileIsMissing(): void {
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('dominio01.exemplo.coop'));
	}

	public function testReturnsTenantConfigWhenHostMatchesARegexKey(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^domain01\.example\.coop$/' => [
				'dbname' => 'tenant01',
				'mail_smtphost' => 'smtp01.exemplo.coop',
			],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame(
			[
				'dbname' => 'tenant01',
				'mail_smtphost' => 'smtp01.exemplo.coop',
			],
			$manager->getConfig('dominio01.exemplo.coop'),
		);
	}

	public function testReturnsEmptyArrayWhenNoRegexKeyMatchesTheHost(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('unknown.exemplo.coop'));
	}

	public function testFirstMatchingRegexKeyWins(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\./' => ['dbname' => 'first'],
			'/\.exemplo\.coop$/' => ['dbname' => 'second'],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame(['dbname' => 'first'], $manager->getConfig('dominio01.exemplo.coop'));
	}

	public function testEnvironmentVariableOverridesTheMatrixFileName(): void {
		$this->writeMatrix('custom.database.php', [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		putenv(Manager::ENV_CONFIG_FILE . '=custom.database.php');
		$manager = new Manager($this->configDir);

		$this->assertSame(['dbname' => 'tenant01'], $manager->getConfig('dominio01.exemplo.coop'));
	}

	public function testGetConfigFromHostResolvesTheTenantWithoutAnInstance(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);

		$this->assertSame(
			['dbname' => 'tenant01'],
			Manager::getConfigFromHost($this->configDir, 'dominio01.exemplo.coop'),
		);
	}

	public function testReturnsEmptyArrayWhenMatrixFileDoesNotDefineAConfigArray(): void {
		file_put_contents(
			$this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE,
			'<?php // no $CONFIG defined here',
		);
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('dominio01.exemplo.coop'));
	}

	public function testStripsThePortFromTheHostBeforeMatching(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame(
			['dbname' => 'tenant01'],
			$manager->getConfig('dominio01.exemplo.coop:8080'),
		);
	}

	public function testMatchesTheHostCaseInsensitively(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame(
			['dbname' => 'tenant01'],
			$manager->getConfig('DOMINIO01.Exemplo.Coop'),
		);
	}

	public function testThrowsWhenAMatrixKeyIsAnInvalidRegex(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'invalid-pattern' => ['dbname' => 'tenant01'],
		]);
		$manager = new Manager($this->configDir);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('invalid-pattern');

		$manager->getConfig('dominio01.exemplo.coop');
	}

	private function writeMatrix(string $fileName, array $matrix): void {
		file_put_contents(
			$this->configDir . '/' . $fileName,
			'<?php $CONFIG = ' . var_export($matrix, true) . ';',
		);
	}
}
