<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class UrlTest extends TestCase
{
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
