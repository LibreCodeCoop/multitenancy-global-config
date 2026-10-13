<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\ConfigFile;
use LibreCode\MultiTenancyGlobalConfig\Tests\Fake\FailingStream;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class ConfigFileTest extends TestCase {
	private string $path;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-config-file-test');
		$this->path = vfsStream::url('multitenancy-config-file-test/config.php');
	}

	protected function tearDown(): void {
		FailingStream::reset();
	}

	public function testReadsNullWhenTheFileIsMissing(): void {
		$this->assertNull((new ConfigFile($this->path))->read());
	}

	public function testUpdateHandsTheCurrentConfigToTheChange(): void {
		$file = new ConfigFile($this->path);
		$file->write(['a' => 1]);

		$file->update(static fn (?array $config): array => $config + ['b' => 2]);

		$this->assertSame(['a' => 1, 'b' => 2], $file->read());
	}

	public function testKeepsWhatPrecedesTheConfigAssignment(): void {
		file_put_contents($this->path, "<?php\n// hand-written\n\$CONFIG = ['a' => 1];\n");

		(new ConfigFile($this->path))->write(['a' => 2]);

		$this->assertStringStartsWith("<?php\n// hand-written\n\$CONFIG = ", (string)file_get_contents($this->path));
	}

	public function testThrowsWhenTheConfigCannotBeWrittenInFull(): void {
		$file = new ConfigFile($this->path);
		$file->write(['a' => 1]);
		vfsStream::setQuota(64);

		$this->expectException(\RuntimeException::class);

		$file->write(['a' => str_repeat('x', 100)]);
	}

	public function testKeepsThePreviousContentWhenAWriteFailsMidway(): void {
		file_put_contents($this->path, "<?php\n// hand-written\n\$CONFIG = ['a' => 1];\n");
		$before = file_get_contents($this->path);
		$path = FailingStream::wrap($this->path);
		FailingStream::failWrites($path);

		try {
			(new ConfigFile($path))->write(['a' => 2]);
			$this->fail('the failed write must be reported');
		} catch (\RuntimeException $e) {
			$this->assertSame(sprintf('Could not write %s', $path), $e->getMessage());
		}

		$this->assertSame($before, file_get_contents($this->path));
	}

	public function testKeepsThePreviousContentWhenTheTruncationFails(): void {
		(new ConfigFile($this->path))->write(['a' => 1]);
		$before = file_get_contents($this->path);
		$path = FailingStream::wrap($this->path);
		FailingStream::failTruncates($path);

		try {
			(new ConfigFile($path))->write(['a' => 2]);
			$this->fail('the failed write must be reported');
		} catch (\RuntimeException) {
		}

		$this->assertSame($before, file_get_contents($this->path));
	}

	public function testReportsAPreviousContentThatCouldNotBeRestored(): void {
		(new ConfigFile($this->path))->write(['a' => 1]);
		$path = FailingStream::wrap($this->path);
		FailingStream::failWrites($path, 2);

		$this->expectExceptionMessage(sprintf('Could not write %s nor restore its previous content', $path));

		(new ConfigFile($path))->write(['a' => 2]);
	}
}
