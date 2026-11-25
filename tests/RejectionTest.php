<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Exception\TransportException;

final class RejectionTest extends TestCase
{
	public function testFailsWhenTheImportIsRejected(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::methodResponses(
				[
					'Email/import',
					[
						'accountId' => self::ACCOUNT,
						'created' => null,
						'notCreated' => ['email' => ['type' => 'overQuota', 'description' => 'The mailbox is full']],
					],
					'import',
				],
				['error', ['type' => 'invalidResultReference'], 'submit'],
			),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage('The JMAP server did not import the message: overQuota: The mailbox is full');

		$transport->send($this->email());
	}

	public function testDeletesTheImportedMessageWhenTheSubmissionIsRejected(): void
	{
		$discard = self::methodResponses([
			'Email/set',
			['accountId' => self::ACCOUNT, 'destroyed' => ['email-1']],
			'discard',
		]);
		$logger = new Logger();
		$transport = $this->loggedTransport(
			$logger,
			self::session(),
			self::lookup(),
			self::upload(),
			self::rejected(),
			$discard,
		);

		try {
			$transport->send($this->email());
			$this->fail('The transport must report the rejected submission.');
		} catch (TransportException $e) {
			$this->assertSame(
				'The JMAP server did not send the message: forbiddenFrom: Not allowed to send from this address',
				$e->getMessage(),
			);
		}

		$this->assertSame(
			[['Email/set', ['accountId' => self::ACCOUNT, 'destroy' => ['email-1']], 'discard']],
			self::calls($discard),
		);
		$this->assertSame([], $logger->records);
	}

	public function testLogsWhenTheRejectedMessageCannotBeDeleted(): void
	{
		$logger = new Logger();
		$transport = $this->loggedTransport(
			$logger,
			self::session(),
			self::lookup(),
			self::upload(),
			self::rejected(),
			self::methodResponses(['error', ['type' => 'forbidden'], 'discard']),
		);

		try {
			$transport->send($this->email());
			$this->fail('The transport must report the rejected submission.');
		} catch (TransportException $e) {
			$this->assertStringStartsWith('The JMAP server did not send the message', $e->getMessage());
		}

		$this->assertSame(
			[[
				'warning',
				'Could not delete the unsent JMAP email "{id}": {error}',
				['id' => 'email-1', 'error' => 'The JMAP method call "discard" failed: forbidden'],
			]],
			$logger->records,
		);
	}

	public function testReportsRejectionWithoutReason(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::methodResponses(
				[
					'Email/import',
					['accountId' => self::ACCOUNT, 'created' => ['email' => ['id' => 'email-1']]],
					'import',
				],
				['EmailSubmission/set', ['accountId' => self::ACCOUNT, 'created' => []], 'submit'],
			),
			self::methodResponses(['Email/set', ['destroyed' => ['email-1']], 'discard']),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage('The JMAP server did not send the message: the server gave no reason');

		$transport->send($this->email());
	}

	private static function rejected(): JsonMockResponse
	{
		return self::methodResponses(
			['Email/import', ['accountId' => self::ACCOUNT, 'created' => ['email' => ['id' => 'email-1']]], 'import'],
			[
				'EmailSubmission/set',
				[
					'accountId' => self::ACCOUNT,
					'created' => null,
					'notCreated' => [
						'submission' => [
							'type' => 'forbiddenFrom',
							'description' => 'Not allowed to send from this address',
						],
					],
				],
				'submit',
			],
		);
	}
}
