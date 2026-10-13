<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig\Tests;

trait CapturesWarnings {
	/** @return string[] */
	private function captureWarnings(callable $body): array {
		$warnings = [];
		set_error_handler(
			function (int $severity, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			},
			E_USER_WARNING,
		);

		try {
			$body();
		} finally {
			restore_error_handler();
		}

		return $warnings;
	}
}
