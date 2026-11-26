<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use Ebenefuenf\JmapMailer\JmapTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class UrlTest extends TestCase
{
	public function testResolvesRelativeUrlsAgainstTheSessionUrl(): void
	{
		$lookup = self::lookup();
		$upload = self::upload();
		$send = self::sent();

		$this->transport(
			self::session(['apiUrl' => '/jmap/api/', 'uploadUrl' => '/jmap/upload/{accountId}/']),
			$lookup,
			$upload,
			$send,
		)->send($this->email());

		$this->assertSame('https://jmap.example.com/jmap/api/', $lookup->getRequestUrl());
		$this->assertSame('https://jmap.example.com/jmap/upload/u1/', $upload->getRequestUrl());
		$this->assertSame('https://jmap.example.com/jmap/api/', $send->getRequestUrl());
	}

	public function testResolvesRelativeUrlsAgainstTheRedirectTarget(): void
	{
		$lookup = self::lookup();
		$upload = self::upload();

		$this->transport(
			self::session(
				['apiUrl' => 'api/', 'uploadUrl' => 'upload/{accountId}/'],
				['url' => 'https://mail.example.com/jmap/session'],
			),
			$lookup,
			$upload,
			self::sent(),
		)->send($this->email());

		$this->assertSame('https://mail.example.com/jmap/api/', $lookup->getRequestUrl());
		$this->assertSame('https://mail.example.com/jmap/upload/u1/', $upload->getRequestUrl());
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $info
	 */
	#[DataProvider('plainHttpUrls')]
	public function testSendsNoCredentialsOverPlainHttp(array $session, array $info, int $requests, string $url): void
	{
		$client = new MockHttpClient([self::session($session, $info), self::lookup(), self::upload(), self::sent()]);
		$transport = new JmapTransport(self::SESSION_URL, 'secret-token', null, $client);

		try {
			$transport->send($this->email());
			$this->fail('The transport must refuse the plain HTTP URL.');
		} catch (TransportException $e) {
			$this->assertSame(
				sprintf('The JMAP server refers to "%s", but credentials are only sent over HTTPS.', $url),
				$e->getMessage(),
			);
		}

		$this->assertSame($requests, $client->getRequestsCount());
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, string}>
	 */
	public static function plainHttpUrls(): iterable
	{
		yield 'API URL' => [
			['apiUrl' => 'http://api.example.com/jmap/api/'],
			[],
			1,
			'http://api.example.com/jmap/api/',
		];
		yield 'upper-case scheme' => [
			['apiUrl' => 'HTTP://api.example.com/jmap/api/'],
			[],
			1,
			'HTTP://api.example.com/jmap/api/',
		];
		yield 'upload URL' => [
			['uploadUrl' => 'http://api.example.com/jmap/upload/{accountId}/'],
			[],
			2,
			'http://api.example.com/jmap/upload/u1/',
		];
		yield 'redirected session' => [
			[],
			['url' => 'http://jmap.example.com/jmap/session'],
			1,
			'http://jmap.example.com/jmap/session',
		];
	}

	public function testFollowsRedirectsOnlyForTheSessionRequest(): void
	{
		$session = self::session();
		$lookup = self::lookup();
		$upload = self::upload();
		$send = self::sent();

		$this->transport($session, $lookup, $upload, $send)->send($this->email());

		$this->assertNotSame(0, $session->getRequestOptions()['max_redirects'] ?? null);
		$this->assertSame(0, $lookup->getRequestOptions()['max_redirects']);
		$this->assertSame(0, $upload->getRequestOptions()['max_redirects']);
		$this->assertSame(0, $send->getRequestOptions()['max_redirects']);
	}

	public function testAllowsPlainHttpWhenTheSessionUrlUsesIt(): void
	{
		$lookup = self::lookup();
		$upload = self::upload();
		$transport = new JmapTransport('http://localhost:8080/jmap/session', 'user', 'password', new MockHttpClient([
			self::session(['apiUrl' => 'http://localhost:8080/jmap/api/', 'uploadUrl' => '/jmap/upload/{accountId}/']),
			$lookup,
			$upload,
			self::sent(),
		]));

		$sent = $transport->send($this->email());

		$this->assertSame('sub-1', $sent?->getMessageId());
		$this->assertSame('http://localhost:8080/jmap/api/', $lookup->getRequestUrl());
		$this->assertSame('http://localhost:8080/jmap/upload/u1/', $upload->getRequestUrl());
	}

	#[DataProvider('invalidSessionUrls')]
	public function testRejectsInvalidSessionUrlOptions(string $dsn, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new JmapTransportFactory())->create(Dsn::fromString($dsn));
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidSessionUrls(): iterable
	{
		$https = 'The "session_url" option must be an "https://" URL. Use the "jmap+http" scheme for plain HTTP.';
		$any = 'The "session_url" option must be an "http://" or "https://" URL.';

		yield 'plain HTTP' => ['jmap://token@default?session_url=http://mail.example.com/jmap/session', $https];
		yield 'no scheme' => ['jmap://token@default?session_url=mail.example.com/jmap/session', $https];
		yield 'other scheme' => ['jmap+http://token@default?session_url=ftp://mail.example.com/session', $any];
	}

	public function testReportsUrlsTheHttpClientRefuses(): void
	{
		$transport = new JmapTransport('ftp://jmap.example.com/session', 'secret-token', null, new MockHttpClient());

		try {
			$transport->send($this->email());
			$this->fail('The transport must report the refused request.');
		} catch (TransportException $e) {
			$this->assertSame(
				'Could not send the request to the JMAP server: '
					. 'Unsupported scheme in "ftp://jmap.example.com/session": "http" or "https" expected.',
				$e->getMessage(),
			);
			$this->assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
		}
	}
}
