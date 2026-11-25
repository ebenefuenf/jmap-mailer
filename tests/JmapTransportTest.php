<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use Symfony\Component\HttpClient\MockHttpClient;

final class JmapTransportTest extends TestCase
{
	public function testSendsMessageThroughJmap(): void
	{
		$session = self::session();
		$lookup = self::lookup();
		$upload = self::upload();
		$send = self::sent();
		$email = $this->email();

		$sent = $this->transport($session, $lookup, $upload, $send)->send($email);

		$this->assertNotNull($sent);
		$this->assertSame('sub-1', $sent->getMessageId());
		$this->assertSame('GET', $session->getRequestMethod());
		$this->assertSame(self::SESSION_URL, $session->getRequestUrl());
		$this->assertSame(
			[
				['Identity/get', ['accountId' => self::ACCOUNT], 'identities'],
				['Mailbox/query', ['accountId' => self::ACCOUNT, 'filter' => ['role' => 'sent']], 'sent'],
				['Mailbox/query', ['accountId' => self::ACCOUNT, 'filter' => ['role' => 'drafts']], 'drafts'],
			],
			self::calls($lookup),
		);
		$this->assertSame('POST', $upload->getRequestMethod());
		$this->assertSame('https://api.example.com/jmap/upload/u1/', $upload->getRequestUrl());
		$this->assertContains('Content-Type: message/rfc822', $upload->getRequestOptions()['headers']);
		$this->assertSame($sent->toString(), $upload->getRequestOptions()['body']);
		$this->assertStringNotContainsString('bcc@example.org', $sent->toString());
		$this->assertSame('POST', $send->getRequestMethod());
		$this->assertSame(self::API_URL, $send->getRequestUrl());
		$this->assertSame(
			[
				'using' => [
					'urn:ietf:params:jmap:core',
					'urn:ietf:params:jmap:mail',
					'urn:ietf:params:jmap:submission',
				],
				'methodCalls' => [
					[
						'Email/import',
						[
							'accountId' => self::ACCOUNT,
							'emails' => [
								'email' => [
									'blobId' => 'blob-1',
									'mailboxIds' => [self::SENT => true],
									'keywords' => ['$seen' => true],
								],
							],
						],
						'import',
					],
					[
						'EmailSubmission/set',
						[
							'accountId' => self::ACCOUNT,
							'create' => [
								'submission' => [
									'identityId' => 'id-mailer',
									'emailId' => '#email',
									'envelope' => [
										'mailFrom' => ['email' => 'mailer@example.com'],
										'rcptTo' => [
											['email' => 'to@example.org'],
											['email' => 'cc@example.org'],
											['email' => 'bcc@example.org'],
										],
									],
								],
							],
						],
						'submit',
					],
				],
			],
			self::payload($send),
		);
	}

	public function testReusesSessionAndLookupForFurtherMessages(): void
	{
		$secondUpload = self::upload('blob-2');
		$secondSend = self::sent('sub-2');
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::sent(),
			$secondUpload,
			$secondSend,
		);

		$transport->send($this->email());
		$sent = $transport->send($this->email());

		$this->assertSame('sub-2', $sent?->getMessageId());
		$this->assertSame('https://api.example.com/jmap/upload/u1/', $secondUpload->getRequestUrl());
		$this->assertSame('Email/import', self::calls($secondSend)[0][0]);
	}

	public function testStringRepresentationNamesTheServer(): void
	{
		$client = new MockHttpClient();

		$this->assertSame('jmap://jmap.example.com', (string) $this->transport());
		$this->assertSame(
			'jmap://mail.example.com:8443',
			(string) new JmapTransport('https://mail.example.com:8443/jmap/session', 'user', 'password', $client),
		);
		$this->assertSame(
			'jmap+http://localhost:8080',
			(string) new JmapTransport('http://localhost:8080/jmap/session', 'user', 'password', $client),
		);
	}
}
