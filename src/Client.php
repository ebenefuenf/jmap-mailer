<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class Client
{
	public const string CORE = 'urn:ietf:params:jmap:core';
	public const string MAIL = 'urn:ietf:params:jmap:mail';
	public const string SUBMISSION = 'urn:ietf:params:jmap:submission';

	private ?Session $session = null;

	/** @var array<string, string|list<string>> */
	private readonly array $auth;

	public function __construct(
		private readonly HttpClientInterface $http,
		private readonly string $sessionUrl,
		#[\SensitiveParameter]
		string $user,
		#[\SensitiveParameter]
		?string $password = null,
	) {
		$this->auth = $password === null ? ['auth_bearer' => $user] : ['auth_basic' => [$user, $password]];
	}

	public function session(): Session
	{
		return $this->session ??= Session::fromJson($this->request('GET', $this->sessionUrl, []));
	}

	/**
	 * Uploads a blob and returns its ID.
	 */
	public function upload(string $contents, string $type): string
	{
		$session = $this->session();
		$url = str_replace('{accountId}', rawurlencode($session->accountId), $session->uploadUrl);
		$data = $this->request('POST', $url, [
			'headers' => ['Content-Type' => $type],
			'body' => $contents,
		]);

		return Json::string($data['blobId'] ?? null, 'blobId');
	}

	/**
	 * @param list<array{string, array<string, mixed>, string}> $calls method name, arguments, and call ID
	 */
	public function call(array $calls): Responses
	{
		$data = $this->request('POST', $this->session()->apiUrl, [
			'json' => [
				'using' => [self::CORE, self::MAIL, self::SUBMISSION],
				'methodCalls' => $calls,
			],
		]);

		return Responses::fromJson($data);
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return array<array-key, mixed>
	 */
	private function request(string $method, string $url, array $options): array
	{
		$response = $this->http->request($method, $url, $options + $this->auth);

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
