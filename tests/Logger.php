<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

final class Logger extends AbstractLogger
{
	/** @var list<array{mixed, string|Stringable, array<array-key, mixed>}> */
	public array $records = [];

	#[\Override]
	public function log(mixed $level, string|Stringable $message, array $context = []): void
	{
		$this->records[] = [$level, $message, $context];
	}
}
