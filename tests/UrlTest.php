<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Exception\TransportException;
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
