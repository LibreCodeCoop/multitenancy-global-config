<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

use LibreCode\MultiTenancyGlobalConfig\ConfigFile;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class ConfigFileTest extends TestCase {
	private string $path;

	protected function setUp(): void {
		vfsStream::setup('multitenancy-config-file-test');
		$this->path = vfsStream::url('multitenancy-config-file-test/config.php');
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
}
