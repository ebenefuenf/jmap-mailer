<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests\Integration;

use Ebenefuenf\JmapMailer\JmapTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

/**
 * Sends a real message. Set JMAP_TEST_DSN, JMAP_TEST_FROM, and JMAP_TEST_TO, then run `composer test:integration`.
 */
final class SendTest extends TestCase
{
	public function testSendsThroughARealServer(): void
	{
		$dsn = self::env('JMAP_TEST_DSN');
		$from = self::env('JMAP_TEST_FROM');
		$to = self::env('JMAP_TEST_TO');

		if ($dsn === '' || $from === '' || $to === '') {
			$this->markTestSkipped('Set JMAP_TEST_DSN, JMAP_TEST_FROM, and JMAP_TEST_TO to send a real message.');
		}

		$transport = (new JmapTransportFactory())->create(Dsn::fromString($dsn));
		$email = (new Email())
			->from($from)
			->to($to)
			->subject('ebenefuenf/jmap-mailer integration test')
			->text('This message was sent by the integration test of ebenefuenf/jmap-mailer.')
			->html('<p>This message was sent by the integration test of <code>ebenefuenf/jmap-mailer</code>.</p>')
			->attach('The attachment arrived.', 'attachment.txt', 'text/plain');

		$sent = $transport->send($email);

		$this->assertNotNull($sent);
		$this->assertNotSame('', $sent->getMessageId());
	}

	private static function env(string $name): string
	{
		$value = getenv($name);

		return is_string($value) ? $value : '';
	}
}
