<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Symfony\Component\Mailer\Exception\TransportException;

final class MailboxTest extends TestCase
{
	public function testKeepsTheCopyInSentByDefault(): void
	{
		$send = self::sent();

		$this->transport(self::session(), self::lookup(), self::upload(), $send)->send($this->email());

		$this->assertArrayNotHasKey('onSuccessDestroyEmail', self::calls($send)[1][1]);
	}

	public function testDeletesTheCopyWhenNotKeepingIt(): void
	{
		$send = self::methodResponses(
			['Email/import', ['accountId' => self::ACCOUNT, 'created' => ['email' => ['id' => 'email-1']]], 'import'],
			[
				'EmailSubmission/set',
				[
					'accountId' => self::ACCOUNT,
					'created' => ['submission' => ['id' => 'sub-1']],
				],
				'submit',
			],
			['Email/set', ['accountId' => self::ACCOUNT, 'destroyed' => ['email-1']], 'submit'],
		);
		$transport = $this->transport(self::session(), self::lookup(), self::upload(), $send)->setKeepCopy(false);

		$sent = $transport->send($this->email());

		$this->assertSame('sub-1', $sent?->getMessageId());
		$submit = self::calls($send)[1][1];
		$this->assertSame(['#submission'], $submit['onSuccessDestroyEmail']);
		$this->assertSame([self::SENT => true], self::calls($send)[0][1]['emails']['email']['mailboxIds']);
	}

	public function testUsesDraftsAndDeletesTheCopyWithoutSentMailbox(): void
	{
		$send = self::sent();

		$this->transport(self::session(), self::lookup(sent: null), self::upload(), $send)->send($this->email());

		$this->assertSame([self::DRAFTS => true], self::calls($send)[0][1]['emails']['email']['mailboxIds']);
		$this->assertSame(['#submission'], self::calls($send)[1][1]['onSuccessDestroyEmail']);
	}

	public function testSendsMailboxIdsAsObjectEvenIfNumeric(): void
	{
		$send = self::sent();

		$this->transport(self::session(), self::lookup(sent: '0'), self::upload(), $send)->send($this->email());

		$this->assertStringContainsString('"mailboxIds":{"0":true}', (string) $send->getRequestOptions()['body']);
	}

	public function testFailsWithoutSentAndDraftsMailbox(): void
	{
		$transport = $this->transport(self::session(), self::lookup(sent: null, drafts: null));

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage(
			'The JMAP account has neither a Sent nor a Drafts mailbox to store the message in.',
		);

		$transport->send($this->email());
	}
}
