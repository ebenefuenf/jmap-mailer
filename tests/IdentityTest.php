<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Symfony\Component\Mailer\Exception\TransportException;

final class IdentityTest extends TestCase
{
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

	public function testUsesAnIdentityWithoutAddressAsLastResort(): void
	{
		$send = self::sent();

		$this->transport(
			self::session(),
			self::methodResponses(
				[
					'Identity/get',
					[
						'list' => [
							['id' => 'id-other', 'email' => 'other@example.com'],
							['id' => 'id-any'],
							['id' => 'id-second-any', 'email' => ''],
						],
					],
					'identities',
				],
				['Mailbox/query', ['ids' => [self::SENT]], 'sent'],
				['Mailbox/query', ['ids' => [self::DRAFTS]], 'drafts'],
			),
			self::upload(),
			$send,
		)->send($this->email());

		$this->assertSame('id-any', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testPrefersAMatchingIdentityOverOneWithoutAddress(): void
	{
		$send = self::sent();

		$this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-any', 'email' => ''],
				['id' => 'id-wildcard', 'email' => '*@example.com'],
			]),
			self::upload(),
			$send,
		)->send($this->email());

		$this->assertSame('id-wildcard', self::calls($send)[1][1]['create']['submission']['identityId']);
	}

	public function testFailsWithoutMatchingIdentity(): void
	{
		$email = $this->email()->returnPath('bounce@example.com');
		$transport = $this->transport(
			self::session(),
			self::lookup([
				['id' => 'id-a', 'email' => 'a@example.com'],
				['id' => 'id-b', 'email' => '*@example.org'],
			]),
		);

		try {
			$transport->send($email);
			$this->fail('The transport must report the missing identity.');
		} catch (TransportException $e) {
			$this->assertSame('No JMAP identity allows sending from "mailer@example.com".', $e->getMessage());
			$this->assertSame("Available JMAP identities: a@example.com, *@example.org.\n", $e->getDebug());
		}
	}

	public function testFailsWithoutAnyIdentity(): void
	{
		$transport = $this->transport(self::session(), self::lookup([]));

		try {
			$transport->send($this->email());
			$this->fail('The transport must report the missing identity.');
		} catch (TransportException $e) {
			$this->assertSame('No JMAP identity allows sending from "mailer@example.com".', $e->getMessage());
			$this->assertSame("Available JMAP identities: none.\n", $e->getDebug());
		}
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
