<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\TransportException;
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
	private readonly bool $plain;

	public function __construct(
		private readonly HttpClientInterface $http,
		private readonly string $sessionUrl,
		#[\SensitiveParameter]
		string $user,
		#[\SensitiveParameter]
		?string $password = null,
	) {
		$this->auth = $password === null ? ['auth_bearer' => $user] : ['auth_basic' => [$user, $password]];
		$this->plain = self::scheme($sessionUrl) === 'http';
	}

	/**
	 * Returns the lower-case scheme of an absolute URL.
	 */
	public static function scheme(string $url): ?string
	{
		$scheme = parse_url($url, PHP_URL_SCHEME);

		return is_string($scheme) ? strtolower($scheme) : null;
	}

	public function session(): Session
	{
		if ($this->session === null) {
			$response = $this->send('GET', $this->sessionUrl, []);
			$data = Http::decode($response);
			$url = $this->secure(Json::optionalString($response->getInfo('url')) ?? $this->sessionUrl);
			$this->session = Session::fromJson($data, $url);
		}

		return $this->session;
	}

	/**
	 * Uploads a blob and returns its ID.
	 */
	public function upload(string $contents, string $type): string
	{
		$session = $this->session();
		$url = str_replace('{accountId}', rawurlencode($session->accountId), $session->uploadUrl);
		$data = $this->post($url, [
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
		$data = $this->post($this->session()->apiUrl, [
			'json' => [
				'using' => [self::CORE, self::MAIL, self::SUBMISSION],
				'methodCalls' => $calls,
			],
		]);

		return Responses::fromJson($data);
	}

	/**
	 * Sends a request to a URL from the session resource, which may be relative to the session URL.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return array<array-key, mixed>
	 */
	private function post(string $url, array $options): array
	{
		return Http::decode($this->send('POST', $url, $options + ['base_uri' => $this->session()->url]));
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function send(string $method, string $url, array $options): ResponseInterface
	{
		$url = $this->secure($url);

		try {
			return $this->http->request($method, $url, $options + $this->auth);
		} catch (TransportExceptionInterface $e) {
			throw new TransportException(
				sprintf('Could not send the request to the JMAP server: %s', $e->getMessage()),
				previous: $e,
			);
		}
	}

	/**
	 * Refuses plain HTTP unless the session URL uses it, so a redirect or the session cannot move credentials off TLS.
	 */
	private function secure(string $url): string
	{
		if (!$this->plain && self::scheme($url) === 'http') {
			throw new TransportException(sprintf(
				'The JMAP server refers to "%s", but credentials are only sent over HTTPS.',
				$url,
			));
		}

		return $url;
	}
}
