<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Exception\TransportException;

final class SessionTest extends TestCase
{
	public function testSendsTheUserAsBearerTokenWithoutPassword(): void
	{
		$session = self::session();
		$lookup = self::lookup();
		$upload = self::upload();
		$send = self::sent();

		$this->transport($session, $lookup, $upload, $send)->send($this->email());

		foreach ([$session, $lookup, $upload, $send] as $response) {
			$this->assertContains('Authorization: Bearer secret-token', $response->getRequestOptions()['headers']);
		}
	}

	public function testSendsUserAndPasswordAsBasicAuth(): void
	{
		$session = self::session();
		$transport = new JmapTransport(self::SESSION_URL, 'user', 'p@ss', new MockHttpClient([
			$session,
			self::lookup(),
			self::upload(),
			self::sent(),
		]));

		$transport->send($this->email());

		$this->assertContains(
			'Authorization: Basic ' . base64_encode('user:p@ss'),
			$session->getRequestOptions()['headers'],
		);
	}

	public function testEncodesTheAccountIdInTheUploadUrl(): void
	{
		$upload = self::upload();
		$transport = $this->transport(
			self::session([
				'accounts' => ['a/b c' => ['accountCapabilities' => ['urn:ietf:params:jmap:mail' => []]]],
				'primaryAccounts' => ['urn:ietf:params:jmap:submission' => 'a/b c'],
			]),
			self::lookup(),
			$upload,
			self::sent(),
		);

		$transport->send($this->email());

		$this->assertSame('https://api.example.com/jmap/upload/a%2Fb%20c/', $upload->getRequestUrl());
	}

	/**
	 * @param array<string, mixed> $session
	 */
	#[DataProvider('unusableSessions')]
	public function testRejectsUnusableSessions(array $session, string $message): void
	{
		$transport = $this->transport(self::session($session));

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage($message);

		$transport->send($this->email());
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, string}>
	 */
	public static function unusableSessions(): iterable
	{
		$invalid = 'The JMAP server returned an invalid response: "%s" is missing or invalid.';

		yield 'no submission' => [
			['primaryAccounts' => ['urn:ietf:params:jmap:mail' => self::ACCOUNT]],
			'The JMAP server does not allow these credentials to send mail. Grant them email submission.',
		];
		yield 'read-only' => [
			[
				'accounts' => [
					self::ACCOUNT => [
						'isReadOnly' => true,
						'accountCapabilities' => ['urn:ietf:params:jmap:mail' => []],
					],
				],
			],
			'The JMAP account is read-only for these credentials. Sending mail needs write access.',
		];
		yield 'no mail' => [
			[
				'accounts' => [
					self::ACCOUNT => [
						'isReadOnly' => false,
						'accountCapabilities' => ['urn:ietf:params:jmap:submission' => []],
					],
				],
			],
			'The JMAP server does not allow these credentials to access mail. Grant them email access.',
		];
		yield 'no primary accounts' => [['primaryAccounts' => null], sprintf($invalid, 'primaryAccounts')];
		yield 'no accounts' => [['accounts' => null], sprintf($invalid, 'accounts')];
		yield 'unknown account' => [['accounts' => ['other' => []]], sprintf($invalid, 'accounts')];
		yield 'no capabilities' => [
			['accounts' => [self::ACCOUNT => ['isReadOnly' => false]]],
			sprintf($invalid, 'accountCapabilities'),
		];
		yield 'no API URL' => [['apiUrl' => null], sprintf($invalid, 'apiUrl')];
		yield 'empty upload URL' => [['uploadUrl' => ''], sprintf($invalid, 'uploadUrl')];
	}

	public function testTreatsAccountsWithoutReadOnlyFlagAsWritable(): void
	{
		$sent = $this->transport(
			self::session([
				'accounts' => [
					self::ACCOUNT => [
						'accountCapabilities' => ['urn:ietf:params:jmap:mail' => []],
					],
				],
			]),
			self::lookup(),
			self::upload(),
			self::sent(),
		)->send($this->email());

		$this->assertSame('sub-1', $sent?->getMessageId());
	}
}
