<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

final class IdentityTest extends TestCase
{
	public function testUsesTheEnvelopeInsteadOfTheHeaders(): void
	{
		$send = self::sent();
		$envelope = new Envelope(new Address('bounce@example.com'), [new Address('other@example.org')]);

		$this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-mailer', 'email' => 'mailer@example.com'],
				['id' => 'id-bounce', 'email' => 'bounce@example.com'],
			]),
			self::upload(),
			$send,
		)->send($this->email(), $envelope);

		$this->assertSame(
			[
				'identityId' => 'id-bounce',
				'emailId' => '#email',
				'envelope' => [
					'mailFrom' => ['email' => 'bounce@example.com'],
					'rcptTo' => [['email' => 'other@example.org']],
				],
			],
			self::calls($send)[1][1]['create']['submission'],
		);
	}

	public function testMatchesIdentitiesCaseInsensitively(): void
	{
		$send = self::sent();
		$email = $this->email()->from('MAILER@Example.COM');

		$this->transport(
			self::session(),
			self::lookup([['id' => 'id-mailer', 'email' => 'Mailer@example.com']]),
			self::upload(),
			$send,
		)->send($email);

		$this->assertSame('id-mailer', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testPrefersExactIdentityOverWildcard(): void
	{
		$send = self::sent();

		$this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-other', 'email' => 'other@example.com'],
				['id' => 'id-wildcard', 'email' => '*@example.com'],
				['id' => 'id-second-wildcard', 'email' => '*@example.com'],
				['id' => 'id-mailer', 'email' => 'mailer@example.com'],
			]),
			self::upload(),
			$send,
		)->send($this->email());

		$this->assertSame('id-mailer', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testFallsBackToFirstWildcardIdentity(): void
	{
		$send = self::sent();

		$this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-other-domain', 'email' => '*@example.org'],
				['id' => 'id-wildcard', 'email' => '*@EXAMPLE.com'],
				['id' => 'id-second-wildcard', 'email' => '*@example.com'],
			]),
			self::upload(),
			$send,
		)->send($this->email());

		$this->assertSame('id-wildcard', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testFailsWithoutMatchingIdentity(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-a', 'email' => 'a@example.com'],
				['id' => 'id-b', 'email' => '*@example.org'],
			]),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage(
			'No JMAP identity allows sending from "mailer@example.com". Available identities: a@example.com, *@example.org.',
		);

		$transport->send($this->email());
	}

	public function testFailsWithoutAnyIdentity(): void
	{
		$transport = $this->transport(self::session(), self::lookup([]));

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage(
			'No JMAP identity allows sending from "mailer@example.com". Available identities: none.',
		);

		$transport->send($this->email());
	}

	public function testUsesConfiguredIdentity(): void
	{
		$send = self::sent();
		$transport = $this->transport(self::session(), self::lookup([]), self::upload(), $send)->setIdentity(
			'id-fixed',
		);

		$transport->send($this->email());

		$this->assertSame('id-fixed', self::calls($send)[1][1]['create']['submission']['identityId']);
	}
}
