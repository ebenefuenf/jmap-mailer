<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\TransportException;

/** @internal */
final readonly class Session
{
	public function __construct(
		public string $apiUrl,
		public string $uploadUrl,
		public string $accountId,
	) {}

	/**
	 * Reads the session resource and checks that its primary submission account can send mail.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function fromJson(array $data): self
	{
		$primaryAccounts = Json::array($data['primaryAccounts'] ?? null, 'primaryAccounts');
		$accountId = $primaryAccounts[Client::SUBMISSION] ?? null;

		if (!is_string($accountId)) {
			throw new TransportException(
				'The JMAP server does not allow these credentials to send mail. Grant them email submission.',
			);
		}

		$accounts = Json::array($data['accounts'] ?? null, 'accounts');
		$account = Json::array($accounts[$accountId] ?? null, 'accounts');

		if (($account['isReadOnly'] ?? false) === true) {
			throw new TransportException(
				'The JMAP account is read-only for these credentials. Sending mail needs write access.',
			);
		}

		$capabilities = Json::array($account['accountCapabilities'] ?? null, 'accountCapabilities');

		if (!array_key_exists(Client::MAIL, $capabilities)) {
			throw new TransportException(
				'The JMAP server does not allow these credentials to access mail. Grant them email access.',
			);
		}

		return new self(
			Json::string($data['apiUrl'] ?? null, 'apiUrl'),
			Json::string($data['uploadUrl'] ?? null, 'uploadUrl'),
			$accountId,
		);
	}
}
