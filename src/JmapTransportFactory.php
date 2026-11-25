<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Creates a JmapTransport from a DSN like `jmap://TOKEN@api.fastmail.com` or `jmap://USER:PASSWORD@host`.
 * The `jmap+http` scheme allows plain HTTP instead of HTTPS.
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
		if (!$this->supports($dsn)) {
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
		return ['jmap', 'jmap+http'];
	}

	private static function sessionUrl(Dsn $dsn): string
	{
		$plain = $dsn->getScheme() === 'jmap+http';
		$url = Json::optionalString($dsn->getOption('session_url'));

		if ($url !== null) {
			$scheme = Client::scheme($url);

			if ($scheme === 'https' || $plain && $scheme === 'http') {
				return $url;
			}

			throw new InvalidArgumentException(
				$plain
					? 'The "session_url" option must be an "http://" or "https://" URL.'
					: 'The "session_url" option must be an "https://" URL. Use the "jmap+http" scheme for plain HTTP.',
			);
		}

		if ($dsn->getHost() === 'default') {
			throw new IncompleteDsnException('Set the JMAP server as host or pass the "session_url" option.');
		}

		$port = $dsn->getPort();

		return sprintf(
			'%s://%s%s/.well-known/jmap',
			$plain ? 'http' : 'https',
			$dsn->getHost(),
			$port === null ? '' : ':' . $port,
		);
	}
}
