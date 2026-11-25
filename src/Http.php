<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class Http
{
	/**
	 * Decodes the JSON body of a successful response.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function decode(ResponseInterface $response): array
	{
		try {
			$status = $response->getStatusCode();

			if ($status >= 300) {
				throw new HttpTransportException(self::failure($response, $status), $response, $status);
			}

			return $response->toArray();
		} catch (DecodingExceptionInterface $e) {
			throw new HttpTransportException(
				sprintf('The JMAP server returned invalid JSON: %s', $e->getMessage()),
				$response,
				previous: $e,
			);
		} catch (TransportExceptionInterface $e) {
			throw new HttpTransportException(
				sprintf('Could not reach the JMAP server: %s', $e->getMessage()),
				$response,
				previous: $e,
			);
		}
	}

	/**
	 * Describes a failed request, using the RFC 7807 problem details JMAP servers send with request-level errors.
	 */
	private static function failure(ResponseInterface $response, int $status): string
	{
		$message = sprintf('The JMAP server responded with HTTP status %d', $status);
		$problem = Json::optionalArray(json_decode($response->getContent(false), true));

		$type = Json::optionalString($problem['type'] ?? null);
		$detail = Json::optionalString($problem['detail'] ?? null) ?? Json::optionalString($problem['title'] ?? null);

		if ($type !== null) {
			$message .= sprintf(' (%s)', $type);
		}

		return $detail === null ? $message . '.' : $message . ': ' . $detail;
	}
}
