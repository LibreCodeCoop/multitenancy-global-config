<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace LibreCode\MultiTenancyGlobalConfig;

/**
 * A PHP file defining a $CONFIG array — the format Nextcloud uses for
 * config.php, and which the tenant matrix follows.
 */
final class ConfigFile {
	private const HEADER = "<?php\n\n/*\n * This file is rewritten by multitenancy-global-config.\n * Comments and formatting are lost when that happens.\n */\n";

	public function __construct(
		private string $path,
	) {
	}

	/**
	 * Mirrors \OC\Config::readData(): include the file and pick up the $CONFIG
	 * it defines. Returns null when the file is missing or defines no array.
	 *
	 * @return array<string,mixed>|null
	 */
	public function read(): ?array {
		if (!file_exists($this->path)) {
			return null;
		}

		$this->invalidateOpcache();

		unset($CONFIG);
		include $this->path;

		return isset($CONFIG) && is_array($CONFIG) ? $CONFIG : null;
	}

	/**
	 * Rewrites the file under an exclusive lock. Everything preceding the
	 * previous $CONFIG assignment is kept, so hand-written comments survive.
	 *
	 * @param array<string,mixed> $data
	 * @throws \RuntimeException when the file cannot be written
	 */
	public function write(array $data): void {
		$content = $this->header() . '$CONFIG = ' . var_export($data, true) . ";\n";

		$pointer = fopen($this->path, 'c+');
		if ($pointer === false) {
			throw new \RuntimeException(sprintf('Could not open %s for writing', $this->path));
		}

		try {
			if (!flock($pointer, LOCK_EX)) {
				throw new \RuntimeException(sprintf('Could not acquire an exclusive lock on %s', $this->path));
			}
			ftruncate($pointer, 0);
			fwrite($pointer, $content);
			fflush($pointer);
			flock($pointer, LOCK_UN);
		} finally {
			fclose($pointer);
		}

		$this->invalidateOpcache();
	}

	/**
	 * Everything preceding the file's $CONFIG assignment, or a default header
	 * when there is none yet.
	 *
	 * The assignment is matched at the start of a line, because Nextcloud's own
	 * warning block quotes "$CONFIG = [];" inside a comment.
	 */
	private function header(): string {
		$existing = file_exists($this->path) ? (string)file_get_contents($this->path) : '';

		if (preg_match('/^\$CONFIG\s*=/m', $existing, $matches, PREG_OFFSET_CAPTURE) === 1) {
			return substr($existing, 0, $matches[0][1]);
		}

		return self::HEADER;
	}

	private function invalidateOpcache(): void {
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->path, true);
		}
	}
}
