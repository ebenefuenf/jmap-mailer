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
	 * @param ?string $default the ID of an identity without an address, as Cyrus reports it; it stands for any address
	 */
	public function __construct(
		private array $identities,
		private ?string $default,
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
		$default = null;

		foreach (Json::list($responses->get('identities')['list'] ?? null, 'list') as $identity) {
			$id = Json::string($identity['id'] ?? null, 'id');
			$email = Json::optionalString($identity['email'] ?? null);

			if ($email === null) {
				$default ??= $id;
			} else {
				$identities[] = ['id' => $id, 'email' => $email];
			}
		}

		return new self(
			$identities,
			$default,
			self::first($responses->get('sent')),
			self::first($responses->get('drafts')),
		);
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

		if ($this->default !== null) {
			return $this->default;
		}

		$e = new TransportException(sprintf(
			'No JMAP identity allows sending from "%s".',
			reset($senders)->getAddress(),
		));
		$e->appendDebug(sprintf(
			"Available JMAP identities: %s.\n",
			$this->identities === [] ? 'none' : implode(', ', array_column($this->identities, 'email')),
		));

		throw $e;
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
