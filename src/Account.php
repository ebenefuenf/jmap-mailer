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
	 * Finds the identity for the first of the addresses that has one.
	 *
	 * @param non-empty-array<array-key, Address> $senders
	 */
	public function identity(array $senders): string
	{
		foreach ($senders as $sender) {
			$identity = $this->match($sender);

			if ($identity !== null) {
				return $identity;
			}
		}

		throw new TransportException(sprintf(
			'No JMAP identity allows sending from "%s". Available identities: %s.',
			reset($senders)->getAddress(),
			$this->identities === [] ? 'none' : implode(', ', array_column($this->identities, 'email')),
		));
	}

	/**
	 * An exact match wins over a wildcard identity like `*@example.com`.
	 */
	private function match(Address $sender): ?string
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

		return $fallback;
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
