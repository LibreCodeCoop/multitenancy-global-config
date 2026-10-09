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
	 * Mirrors \OC\Config::readData(): include the file under a shared lock
	 * and pick up the $CONFIG it defines. Returns null when the file is
	 * missing or defines no array.
	 *
	 * @return array<string,mixed>|null
	 * @throws \RuntimeException when the file cannot be locked
	 */
	public function read(): ?array {
		if (!file_exists($this->path)) {
			return null;
		}

		$pointer = $this->open('r', LOCK_SH);
		try {
			return $this->includeConfig(false);
		} finally {
			flock($pointer, LOCK_UN);
			fclose($pointer);
		}
	}

	/**
	 * Rewrites the file under an exclusive lock. Unless a header is given,
	 * everything preceding the previous $CONFIG assignment is kept, so
	 * hand-written comments survive.
	 *
	 * @param array<string,mixed> $data
	 * @throws \RuntimeException when the file cannot be written
	 */
	public function write(array $data, ?string $header = null): void {
		$this->update(static fn (): array => $data, $header);
	}

	/**
	 * Reads, changes and rewrites the file under one exclusive lock, so no
	 * concurrent write is lost in between.
	 *
	 * @param \Closure(array<string,mixed>|null):array<string,mixed> $change
	 * @throws \RuntimeException when the file cannot be written
	 */
	public function update(\Closure $change, ?string $header = null): void {
		$pointer = $this->open('c+', LOCK_EX);
		try {
			$header ??= self::headerOf((string)stream_get_contents($pointer)) ?? self::HEADER;
			$content = $header . '$CONFIG = ' . var_export($change($this->includeConfig(true)), true) . ";\n";

			if (!rewind($pointer) || !ftruncate($pointer, 0)
				|| fwrite($pointer, $content) !== strlen($content) || !fflush($pointer)) {
				throw new \RuntimeException(sprintf('Could not write %s', $this->path));
			}
		} finally {
			flock($pointer, LOCK_UN);
			fclose($pointer);
		}

		$this->invalidateOpcache();
	}

	/**
	 * @return resource
	 * @throws \RuntimeException when the file cannot be opened or locked
	 */
	private function open(string $mode, int $lock) {
		$pointer = fopen($this->path, $mode);
		if ($pointer === false) {
			throw new \RuntimeException(sprintf('Could not open %s', $this->path));
		}

		if (!flock($pointer, $lock)) {
			fclose($pointer);
			throw new \RuntimeException(sprintf('Could not lock %s', $this->path));
		}

		return $pointer;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function includeConfig(bool $forceOpcache): ?array {
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->path, $forceOpcache);
		}

		include $this->path;

		return isset($CONFIG) && is_array($CONFIG) ? $CONFIG : null;
	}

	/**
	 * Everything preceding the $CONFIG assignment in the given PHP source, or
	 * null when it has none.
	 *
	 * The assignment is matched at the start of a line, because Nextcloud's own
	 * warning block quotes "$CONFIG = [];" inside a comment.
	 */
	public static function headerOf(string $source): ?string {
		if (preg_match('/^\$CONFIG\s*=/m', $source, $matches, PREG_OFFSET_CAPTURE) === 1) {
			return substr($source, 0, $matches[0][1]);
		}

		return null;
	}

	private function invalidateOpcache(): void {
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->path, true);
		}
	}
}
