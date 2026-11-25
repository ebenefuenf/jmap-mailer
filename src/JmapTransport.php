<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends mail through a JMAP server (RFC 8620, RFC 8621).
 *
 * Without a password, the user is sent as a bearer token; otherwise user and password are used for basic auth.
 *
 * @api
 */
final class JmapTransport extends AbstractTransport
{
	private readonly Client $jmap;
	private ?string $identity = null;
	private bool $keepCopy = true;
	private ?Account $account = null;

	public function __construct(
		private readonly string $sessionUrl,
		#[\SensitiveParameter]
		string $user,
		#[\SensitiveParameter]
		?string $password = null,
		?HttpClientInterface $client = null,
		?EventDispatcherInterface $dispatcher = null,
		?LoggerInterface $logger = null,
	) {
		parent::__construct($dispatcher, $logger);

		$this->jmap = new Client($client ?? HttpClient::create(), $sessionUrl, $user, $password);
	}

	/**
	 * Sends every message with this identity ID instead of the one matching the envelope sender.
	 *
	 * @return $this
	 */
	public function setIdentity(?string $identity): static
	{
		$this->identity = $identity;

		return $this;
	}

	/**
	 * Keeps a copy of each sent message in the Sent mailbox.
	 *
	 * @return $this
	 */
	public function setKeepCopy(bool $keepCopy): static
	{
		$this->keepCopy = $keepCopy;

		return $this;
	}

	#[\Override]
	public function __toString(): string
	{
		$host = parse_url($this->sessionUrl, PHP_URL_HOST);
		$port = parse_url($this->sessionUrl, PHP_URL_PORT);

		return 'jmap://' . (is_string($host) ? $host : '') . (is_int($port) ? ':' . $port : '');
	}

	#[\Override]
	protected function doSend(SentMessage $message): void
	{
		$envelope = $message->getEnvelope();
		$account = $this->account();
		$identity = $this->identity ?? $account->identity($envelope->getSender());
		$mailbox =
			$account->sent
				?? $account->drafts
				?? throw new TransportException(
					'The JMAP account has neither a Sent nor a Drafts mailbox to store the message in.',
				);
		$accountId = $this->jmap->session()->accountId;
		$blobId = $this->jmap->upload($message->toString(), 'message/rfc822');

		$submit = [
			'accountId' => $accountId,
			'create' => [
				'submission' => [
					'identityId' => $identity,
					'emailId' => '#email',
					'envelope' => [
						'mailFrom' => ['email' => $envelope->getSender()->getEncodedAddress()],
						'rcptTo' => array_map(
							static fn(Address $recipient): array => ['email' => $recipient->getEncodedAddress()],
							$envelope->getRecipients(),
						),
					],
				],
			],
		];

		if (!$this->keepCopy || $account->sent === null) {
			$submit['onSuccessDestroyEmail'] = ['#submission'];
		}

		$responses = $this->jmap->call([
			[
				'Email/import',
				[
					'accountId' => $accountId,
					'emails' => [
						'email' => [
							'blobId' => $blobId,
							'mailboxIds' => (object) [$mailbox => true],
							'keywords' => ['$seen' => true],
						],
					],
				],
				'import',
			],
			['EmailSubmission/set', $submit, 'submit'],
		]);
		$emailId = $responses->created('import', 'email', 'The JMAP server did not import the message');

		try {
			$submissionId = $responses->created('submit', 'submission', 'The JMAP server did not send the message');
		} catch (TransportException $e) {
			$this->discard($accountId, $emailId);

			throw $e;
		}

		$message->setMessageId($submissionId);
	}

	private function account(): Account
	{
		return $this->account ??= Account::lookup($this->jmap);
	}

	/**
	 * Deletes an imported message that was not sent, so it does not show up as sent.
	 */
	private function discard(string $accountId, string $emailId): void
	{
		try {
			$this->jmap
				->call([['Email/set', ['accountId' => $accountId, 'destroy' => [$emailId]], 'discard']])
				->get('discard');
		} catch (TransportException $e) {
			// The send already failed; a failed cleanup must not hide that error.
			$this->getLogger()->warning('Could not delete the unsent JMAP email "{id}": {error}', [
				'id' => $emailId,
				'error' => $e->getMessage(),
			]);
		}
	}
}
