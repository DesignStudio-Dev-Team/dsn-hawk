<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

use DSN\Hawk\Support\EmailLog;

final class EmailLogReport implements ReportInterface {

	public function key(): string {
		return 'email_log';
	}

	public function isAvailable(): bool {
		return true;
	}

	public function collect(): ?array {
		return EmailLog::summary();
	}

	public function onSynced(): void {
		// no-op
	}
}
