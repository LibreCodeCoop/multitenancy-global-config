<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Manager;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
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

		$this->assertSame([], $manager->getConfig('domain01.example.coop'));
	}

	#[DataProvider('hostMatchingProvider')]
	public function testMatchesHostAgainstRegexKey(string $host, array $expected): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^domain01\.example\.coop$/' => [
				'dbname' => 'tenant01',
				'mail_smtphost' => 'smtp01.example.coop',
			],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame($expected, $manager->getConfig($host));
	}

	public static function hostMatchingProvider(): array {
		$tenantConfig = ['dbname' => 'tenant01', 'mail_smtphost' => 'smtp01.example.coop'];

		return [
			'exact match' => ['domain01.example.coop', $tenantConfig],
			'port is stripped before matching' => ['domain01.example.coop:8080', $tenantConfig],
			'host is matched case-insensitively' => ['DOMAIN01.Example.Coop', $tenantConfig],
			'no regex key matches the host' => ['unknown.example.coop', []],
		];
	}

	public function testFirstMatchingRegexKeyWins(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^domain01\./' => ['dbname' => 'first'],
			'/\.example\.coop$/' => ['dbname' => 'second'],
		]);
		$manager = new Manager($this->configDir);

		$this->assertSame(['dbname' => 'first'], $manager->getConfig('domain01.example.coop'));
	}

	public function testEnvironmentVariableOverridesTheMatrixFileName(): void {
		$this->writeMatrix('custom.database.php', [
			'/^domain01\.example\.coop$/' => ['dbname' => 'tenant01'],
		]);
		putenv(Manager::ENV_CONFIG_FILE . '=custom.database.php');
		$manager = new Manager($this->configDir);

		$this->assertSame(['dbname' => 'tenant01'], $manager->getConfig('domain01.example.coop'));
	}

	public function testGetConfigFromHostResolvesTheTenantWithoutAnInstance(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^domain01\.example\.coop$/' => ['dbname' => 'tenant01'],
		]);

		$this->assertSame(
			['dbname' => 'tenant01'],
			Manager::getConfigFromHost($this->configDir, 'domain01.example.coop'),
		);
	}

	public function testReturnsEmptyArrayWhenMatrixFileDoesNotDefineAConfigArray(): void {
		file_put_contents(
			$this->configDir . '/' . Manager::DEFAULT_CONFIG_FILE,
			'<?php // no $CONFIG defined here',
		);
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('domain01.example.coop'));
	}

	public function testThrowsWhenAMatrixKeyIsAnInvalidRegex(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'invalid-pattern' => ['dbname' => 'tenant01'],
		]);
		$manager = new Manager($this->configDir);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('invalid-pattern');

		$manager->getConfig('domain01.example.coop');
	}

	private function writeMatrix(string $fileName, array $matrix): void {
		file_put_contents(
			$this->configDir . '/' . $fileName,
			'<?php $CONFIG = ' . var_export($matrix, true) . ';',
		);
	}
}
