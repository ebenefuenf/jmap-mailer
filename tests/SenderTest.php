<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SenderTest extends TestCase
{
	public function testMatchesTheFromAddressAndSubmitsTheEnvelope(): void
	{
		$send = self::sent();
		$envelope = new Envelope(new Address('bounce@example.com'), [new Address('other@example.org')]);

		$this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-bounce', 'email' => 'bounce@example.com'],
				['id' => 'id-mailer', 'email' => 'mailer@example.com'],
			]),
			self::upload(),
			$send,
		)->send($this->email(), $envelope);

		$this->assertSame(
			[
				'identityId' => 'id-mailer',
				'emailId' => '#email',
				'envelope' => [
					'mailFrom' => ['email' => 'bounce@example.com'],
					'rcptTo' => [['email' => 'other@example.org']],
				],
			],
			self::calls($send)[1][1]['create']['submission'],
		);
	}

	public function testFallsBackToTheEnvelopeSender(): void
	{
		$send = self::sent();
		$email = $this->email()->from('customer@example.net')->sender('mailer@example.com');

		$this->transport(self::session(), self::lookup(), self::upload(), $send)->send($email);

		$this->assertSame('id-mailer', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testMatchesTheSenderWithoutFromHeader(): void
	{
		$send = self::sent();
		$email = (new Email())
			->sender('mailer@example.com')
			->to('to@example.org')
			->text('Hello world');

		$this->transport(self::session(), self::lookup(), self::upload(), $send)->send($email);

		$this->assertSame('id-mailer', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testMatchesTheEnvelopeSenderOfRawMessages(): void
	{
		$upload = self::upload();
		$send = self::sent();
		$raw = "From: Other <other@example.com>\r\nTo: to@example.org\r\nSubject: Hello\r\n\r\nHello world\r\n";
		$envelope = new Envelope(new Address('mailer@example.com'), [new Address('to@example.org')]);

		$this->transport(self::session(), self::lookup(), $upload, $send)->send(new RawMessage($raw), $envelope);

		$this->assertSame($raw, $upload->getRequestOptions()['body']);
		$this->assertSame('id-mailer', self::calls($send)[1][1]['create']['submission']['identityId']);
	}
}
