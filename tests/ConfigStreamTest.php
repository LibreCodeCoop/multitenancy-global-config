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
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class ConfigStreamTest extends TestCase {
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
}
