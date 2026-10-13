<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\ConfigFile;
use LibreCode\MultiTenancyGlobalConfig\ConfigStream;
use LibreCode\MultiTenancyGlobalConfig\TenantWrites;
use LibreCode\MultiTenancyGlobalConfig\Tests\Fake\FailingStream;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class ConfigStreamTest extends TestCase {
	protected function tearDown(): void {
		FailingStream::reset();
	}

	public function testDoesNotRetryAWriteThatFailed(): void {
		vfsStream::setup('multitenancy-stream-test');
		$missingDir = vfsStream::url('multitenancy-stream-test/missing');
		$reads = 0;
		$url = ConfigStream::register(
			$missingDir . '/config.php',
			new TenantWrites(
				new ConfigFile($missingDir . '/config.php'),
				new ConfigFile($missingDir . '/multitenancy.database.php'),
				'/^domain01\.example\.coop$/',
				['mail_smtphost' => 'smtp01.example.coop'],
				[],
				[],
			),
			function () use (&$reads): array {
				$reads++;
				return ['version' => '36.0.0.0'];
			},
			static function (): void {
			},
		);
		$pointer = fopen($url, 'r+');
		fwrite($pointer, '<?php $CONFIG = [];');

		try {
			@fflush($pointer);
			$this->fail('the failed write must be reported');
		} catch (\RuntimeException) {
		}
		fclose($pointer);

		$this->assertSame(1, $reads);
	}

	public function testReportsAFailedConfigPhpWriteWithBothFilesUnchanged(): void {
		vfsStream::setup('multitenancy-stream-test');
		$configPhpFile = new ConfigFile(vfsStream::url('multitenancy-stream-test/config.php'));
		$matrixFile = new ConfigFile(vfsStream::url('multitenancy-stream-test/multitenancy.database.php'));
		$configPhpFile->write(['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop']);
		$matrixFile->write(['/^domain01\.example\.coop$/' => ['mail_smtphost' => 'smtp01.example.coop']]);
		$configPhpPath = FailingStream::wrap(vfsStream::url('multitenancy-stream-test/config.php'));
		FailingStream::failWrites($configPhpPath);
		$served = 0;
		$url = ConfigStream::register(
			$configPhpPath,
			new TenantWrites(
				new ConfigFile($configPhpPath),
				$matrixFile,
				'/^domain01\.example\.coop$/',
				['mail_smtphost' => 'smtp01.example.coop'],
				['mail_smtphost' => 'base.example.coop'],
				[],
			),
			static fn (): array => ['version' => '36.0.0.0', 'mail_smtphost' => 'written.example.coop'],
			static function () use (&$served): void {
				$served++;
			},
		);
		$pointer = fopen($url, 'r+');
		ftruncate($pointer, 0);
		fwrite($pointer, '<?php $CONFIG = [];');

		try {
			@fflush($pointer);
			$this->fail('the failed write must be reported');
		} catch (\RuntimeException $e) {
			$this->assertSame(sprintf('Could not write %s', $configPhpPath), $e->getMessage());
		}
		fclose($pointer);

		$this->assertSame(['version' => '36.0.0.0', 'mail_smtphost' => 'base.example.coop'], $configPhpFile->read());
		$this->assertSame(['/^domain01\.example\.coop$/' => ['mail_smtphost' => 'smtp01.example.coop']], $matrixFile->read());
		$this->assertSame(0, $served);
	}
}
