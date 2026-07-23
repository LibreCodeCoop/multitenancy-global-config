<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase {
	private string $configDir;

	protected function setUp(): void {
		$this->configDir = sys_get_temp_dir() . '/multitenancy-test-' . uniqid();
		mkdir($this->configDir);
	}

	protected function tearDown(): void {
		putenv(Manager::ENV_CONFIG_FILE);
		unset($_SERVER['HTTP_HOST']);
		array_map('unlink', glob($this->configDir . '/*') ?: []);
		rmdir($this->configDir);
	}

	public function testReturnsEmptyArrayWhenMatrixFileIsMissing(): void {
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('dominio01.exemplo.coop'));
	}

	public function testReturnsTenantConfigWhenHostMatchesARegexKey(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => [
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

	public function testGetConfigFromEnvironmentResolvesUsingHttpHost(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$_SERVER['HTTP_HOST'] = 'dominio01.exemplo.coop';

		$this->assertSame(
			['dbname' => 'tenant01'],
			Manager::getConfigFromEnvironment($this->configDir),
		);
	}

	public function testGetConfigFromEnvironmentFallsBackToLocalhostWithoutHttpHost(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^localhost$/' => ['dbname' => 'local'],
		]);
		unset($_SERVER['HTTP_HOST']);

		$this->assertSame(
			['dbname' => 'local'],
			Manager::getConfigFromEnvironment($this->configDir),
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

	public function testGetConfigFromEnvironmentStripsThePortFromTheHost(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$_SERVER['HTTP_HOST'] = 'dominio01.exemplo.coop:8080';

		$this->assertSame(
			['dbname' => 'tenant01'],
			Manager::getConfigFromEnvironment($this->configDir),
		);
	}

	public function testGetConfigFromEnvironmentMatchesTheHostCaseInsensitively(): void {
		$this->writeMatrix(Manager::DEFAULT_CONFIG_FILE, [
			'/^dominio01\.exemplo\.coop$/' => ['dbname' => 'tenant01'],
		]);
		$_SERVER['HTTP_HOST'] = 'DOMINIO01.Exemplo.Coop';

		$this->assertSame(
			['dbname' => 'tenant01'],
			Manager::getConfigFromEnvironment($this->configDir),
		);
	}

	private function writeMatrix(string $fileName, array $matrix): void {
		file_put_contents(
			$this->configDir . '/' . $fileName,
			'<?php $CONFIG = ' . var_export($matrix, true) . ';',
		);
	}
}
