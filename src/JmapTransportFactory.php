<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Creates a JmapTransport from a DSN like `jmap://TOKEN@api.fastmail.com` or `jmap://USER:PASSWORD@host`.
 *
 * Options: `session_url` replaces the session discovery through `https://host/.well-known/jmap`,
 * `identity` sets a fixed identity ID, and `keep_copy=0` deletes the copy in the Sent mailbox after sending.
 *
 * @api
 */
final class JmapTransportFactory extends AbstractTransportFactory
{
	#[\Override]
	public function create(Dsn $dsn): TransportInterface
	{
		if ($dsn->getScheme() !== 'jmap') {
			throw new UnsupportedSchemeException($dsn, 'jmap', $this->getSupportedSchemes());
		}

		return (new JmapTransport(
			self::sessionUrl($dsn),
			$this->getUser($dsn),
			$dsn->getPassword(),
			$this->client,
			$this->dispatcher,
			$this->logger,
		))
			->setIdentity(Json::optionalString($dsn->getOption('identity')))
			->setKeepCopy($dsn->getBooleanOption('keep_copy', true));
	}

	/**
	 * @return list<string>
	 */
	#[\Override]
	protected function getSupportedSchemes(): array
	{
		return ['jmap'];
	}

	private static function sessionUrl(Dsn $dsn): string
	{
		$url = Json::optionalString($dsn->getOption('session_url'));

		if ($url !== null) {
			return $url;
		}

		if ($dsn->getHost() === 'default') {
			throw new IncompleteDsnException('Set the JMAP server as host or pass the "session_url" option.');
		}

		$port = $dsn->getPort();

		return sprintf('https://%s%s/.well-known/jmap', $dsn->getHost(), $port === null ? '' : ':' . $port);
	}
}
