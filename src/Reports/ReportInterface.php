<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

interface ReportInterface {

	public function key(): string;

	public function isAvailable(): bool;

	public function collect(): ?array;

	/**
	 * Called after a sync run has been confirmed (HTTP 2xx).
	 * Used by reports that page through history (e.g. GF entry backfill)
	 * to advance their cursor only once Skyline has acknowledged receipt.
	 */
	public function onSynced(): void;
}
