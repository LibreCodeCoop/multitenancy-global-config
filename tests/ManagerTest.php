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
		array_map('unlink', glob($this->configDir . '/*') ?: []);
		rmdir($this->configDir);
	}

	public function testReturnsEmptyArrayWhenMatrixFileIsMissing(): void {
		$manager = new Manager($this->configDir);

		$this->assertSame([], $manager->getConfig('dominio01.exemplo.coop'));
	}
}
