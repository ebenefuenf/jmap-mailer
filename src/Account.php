<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

/** @internal */
final readonly class Account
{
	/**
	 * @param list<array{id: string, email: string}> $identities
	 */
	public function __construct(
		private array $identities,
		public ?string $sent,
		public ?string $drafts,
	) {}

	/**
	 * Fetches the sending identities and the Sent and Drafts mailboxes.
	 */
	public static function lookup(Client $jmap): self
	{
		$accountId = $jmap->session()->accountId;
		$responses = $jmap->call([
			['Identity/get', ['accountId' => $accountId], 'identities'],
			['Mailbox/query', ['accountId' => $accountId, 'filter' => ['role' => 'sent']], 'sent'],
			['Mailbox/query', ['accountId' => $accountId, 'filter' => ['role' => 'drafts']], 'drafts'],
		]);
		$identities = [];

		foreach (Json::list($responses->get('identities')['list'] ?? null, 'list') as $identity) {
			$identities[] = [
				'id' => Json::string($identity['id'] ?? null, 'id'),
				'email' => Json::string($identity['email'] ?? null, 'email'),
			];
		}

		return new self($identities, self::first($responses->get('sent')), self::first($responses->get('drafts')));
	}

	/**
	 * Finds the identity for a sender address. An exact match wins over a wildcard identity like `*@example.com`.
	 */
	public function identity(Address $sender): string
	{
		$address = strtolower($sender->getAddress());
		$parts = explode('@', $address);
		$wildcard = '*@' . array_pop($parts);
		$fallback = null;

		foreach ($this->identities as $identity) {
			$email = strtolower($identity['email']);

			if ($email === $address) {
				return $identity['id'];
			}

			if ($email === $wildcard) {
				$fallback ??= $identity['id'];
			}
		}

		return $fallback ?? throw new TransportException(sprintf(
			'No JMAP identity allows sending from "%s". Available identities: %s.',
			$sender->getAddress(),
			$this->identities === [] ? 'none' : implode(', ', array_column($this->identities, 'email')),
		));
	}

	/**
	 * @param array<array-key, mixed> $query
	 */
	private static function first(array $query): ?string
	{
		$ids = Json::array($query['ids'] ?? null, 'ids');

		return Json::optionalString($ids[0] ?? null);
	}
}
