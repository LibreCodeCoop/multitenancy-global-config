<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\ConfigFile;
use LibreCode\MultiTenancyGlobalConfig\TenantWrites;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class TenantWritesTest extends TestCase {
	private const PATTERN = '/^domain01\.example\.coop$/';

	private ConfigFile $configPhp;
	private ConfigFile $matrix;
	private ConfigFile $laterConfig;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-writes-test');
		$configDir = vfsStream::url('multitenancy-writes-test');
		$this->configPhp = new ConfigFile($configDir . '/config.php');
		$this->matrix = new ConfigFile($configDir . '/multitenancy.database.php');
		$this->laterConfig = new ConfigFile($configDir . '/smtp.config.php');

		$this->configPhp->write(['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop']);
		$this->matrix->write([self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']]);
	}

	/**
	 * @param array<string,mixed> $served
	 * @param array<string,mixed> $base
	 */
	private function writes(
		array $served = ['mail_smtphost' => 'smtp01.example.coop'],
		array $base = ['mail_smtphost' => 'base.example.coop'],
	): TenantWrites {
		return new TenantWrites($this->configPhp, $this->matrix, self::PATTERN, $served, $base, [$this->laterConfig]);
	}

	public function testKeepsTheConfigPhpValueOfAnUntouchedTenantKey(): void {
		$this->writes()->persist([
			'version' => '36.0.0.0',
			'mail_smtphost' => 'smtp01.example.coop',
			'loglevel' => 2,
		]);

		$this->assertSame(
			['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop', 'loglevel' => 2],
			$this->configPhp->read(),
		);
		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']],
			$this->matrix->read(),
		);
	}

	public function testRoutesAWrittenTenantKeyToTheMatchedMatrixEntry(): void {
		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'written.example.coop']);

		$this->assertSame(
			['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop'],
			$this->configPhp->read(),
		);
		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'written.example.coop']],
			$this->matrix->read(),
		);
	}

	public function testRoutesATenantWriteOfTheBaseValueToTheMatrixEntry(): void {
		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop']);

		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'base.example.coop']],
			$this->matrix->read(),
		);
	}

	public function testLeavesATenantKeyOutOfConfigPhpWhenConfigPhpDidNotHaveIt(): void {
		$this->configPhp->write(['version' => '36.0.0.0']);

		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'smtp01.example.coop']);

		$this->assertSame(['version' => '36.0.0.0'], $this->configPhp->read());
	}

	public function testDoesNotTakeAValueFromALaterConfigFileForATenantWrite(): void {
		$this->laterConfig->write(['mail_smtphost' => 'later.example.coop']);

		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'later.example.coop', 'loglevel' => 2]);

		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']],
			$this->matrix->read(),
		);
	}

	public function testRemovesADeletedTenantKeyFromTheMatrixEntry(): void {
		$this->matrix->write([self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop', 'mail_domain' => 'domain01.example.coop']]);
		$writes = $this->writes(['mail_smtphost' => 'smtp01.example.coop', 'mail_domain' => 'domain01.example.coop']);

		$writes->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'smtp01.example.coop']);

		$this->assertSame(
			[self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop']],
			$this->matrix->read(),
		);
		$this->assertSame(['mail_domain'], $writes->removed());
		$this->assertSame(
			['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop'],
			$this->configPhp->read(),
		);
	}

	public function testDoesNotRewriteTheMatrixWhenNoTenantKeyChanged(): void {
		$matrixFile = vfsStream::url('multitenancy-writes-test/multitenancy.database.php');
		file_put_contents($matrixFile, "<?php\n// hand-written\n\$CONFIG = ['" . self::PATTERN . "' => ['mail_smtphost' => 'smtp01.example.coop']];\n");
		$before = file_get_contents($matrixFile);

		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'smtp01.example.coop']);

		$this->assertSame($before, file_get_contents($matrixFile));
	}

	public function testServesTheWrittenValueAfterwards(): void {
		$writes = $this->writes();

		$writes->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'written.example.coop']);
		$writes->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'written.example.coop', 'loglevel' => 2]);

		$this->assertSame(['mail_smtphost' => 'written.example.coop'], $writes->served());
		$this->assertSame(
			['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop', 'loglevel' => 2],
			$this->configPhp->read(),
		);
	}

	public function testKeepsTheHeaderNextcloudWrote(): void {
		$header = "<?php\n/* nextcloud warning */\n";

		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'smtp01.example.coop'], $header);

		$this->assertStringStartsWith(
			$header . '$CONFIG = ',
			(string)file_get_contents(vfsStream::url('multitenancy-writes-test/config.php')),
		);
	}

	public function testKeepsTheOtherMatrixEntriesWhenWritingOne(): void {
		$this->matrix->write([
			self::PATTERN => ['mail_smtphost' => 'smtp01.example.coop'],
			'/^domain02\.example\.coop$/' => ['mail_smtphost' => 'smtp02.example.coop'],
		]);

		$this->writes()->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'written.example.coop']);

		$this->assertSame(
			['mail_smtphost' => 'smtp02.example.coop'],
			$this->matrix->read()['/^domain02\.example\.coop$/'],
		);
	}

	public function testKeepsOnlyWhatDiffersFromTheBaseOfAWrittenMap(): void {
		$this->matrix->write([self::PATTERN => ['redis' => ['port' => 6380]]]);
		$writes = $this->writes(
			['redis' => ['host' => 'base-redis', 'timeout' => 5, 'port' => 6380]],
			['redis' => ['host' => 'base-redis', 'timeout' => 5]],
		);

		$writes->persist(['version' => '36.0.0.0', 'redis' => ['host' => 'base-redis', 'timeout' => 5, 'port' => 6381]]);

		$this->assertSame([self::PATTERN => ['redis' => ['port' => 6381]]], $this->matrix->read());
	}

	public function testKeepsAWrittenListWhole(): void {
		$this->matrix->write([self::PATTERN => ['trusted_domains' => ['x.example.coop']]]);
		$writes = $this->writes(
			['trusted_domains' => ['x.example.coop', 'b.example.coop']],
			['trusted_domains' => ['a.example.coop', 'b.example.coop']],
		);

		$writes->persist(['version' => '36.0.0.0', 'trusted_domains' => ['x.example.coop', 'b.example.coop', 'd.example.coop']]);

		$this->assertSame(
			[self::PATTERN => ['trusted_domains' => ['x.example.coop', 'b.example.coop', 'd.example.coop']]],
			$this->matrix->read(),
		);
	}

	public function testRestoresTheBaseValueOfADeletedTenantKey(): void {
		$writes = $this->writes();

		$writes->persist(['version' => '36.0.0.0']);

		$this->assertSame(['mail_smtphost' => 'base.example.coop'], $writes->restored());
	}

	public function testDoesNotTakeTheRestoredBaseValueForATenantWrite(): void {
		$writes = $this->writes();
		$writes->persist(['version' => '36.0.0.0']);

		$writes->persist(['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop', 'loglevel' => 2]);

		$this->assertSame([self::PATTERN => []], $this->matrix->read());
	}
}
