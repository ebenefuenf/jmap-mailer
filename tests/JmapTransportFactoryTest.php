<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;

final class JmapTransportFactoryTest extends TestCase
{
	public function testSupportsTheJmapScheme(): void
	{
		$factory = new JmapTransportFactory();

		$this->assertTrue($factory->supports(Dsn::fromString('jmap://token@api.fastmail.com')));
		$this->assertFalse($factory->supports(Dsn::fromString('smtp://user:pass@smtp.example.com')));
	}

	#[DataProvider('sessionUrls')]
	public function testDiscoversTheSession(string $dsn, string $sessionUrl, string $name): void
	{
		$session = self::session();
		$factory = new JmapTransportFactory(null, new MockHttpClient([
			$session,
			self::lookup(),
			self::upload(),
			self::sent(),
		]));

		$transport = $factory->create(Dsn::fromString($dsn));
		$transport->send($this->email());

		$this->assertSame($sessionUrl, $session->getRequestUrl());
		$this->assertSame($name, (string) $transport);
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function sessionUrls(): iterable
	{
		yield 'host' => [
			'jmap://token@api.fastmail.com',
			'https://api.fastmail.com/.well-known/jmap',
			'jmap://api.fastmail.com',
		];
		yield 'host and port' => [
			'jmap://token@mail.example.com:8443',
			'https://mail.example.com:8443/.well-known/jmap',
			'jmap://mail.example.com:8443',
		];
		yield 'session URL' => [
			'jmap://token@default?session_url=http://localhost:8080/jmap/session',
			'http://localhost:8080/jmap/session',
			'jmap://localhost:8080',
		];
		yield 'empty session URL' => [
			'jmap://token@mail.example.com?session_url=',
			'https://mail.example.com/.well-known/jmap',
			'jmap://mail.example.com',
		];
	}

	public function testUsesBearerTokenOrBasicAuth(): void
	{
		$bearer = $this->sessionRequest('jmap://fmu1-token@api.fastmail.com');
		$basic = $this->sessionRequest('jmap://user%40example.com:p%40ss@mail.example.com');

		$this->assertContains('Authorization: Bearer fmu1-token', $bearer->getRequestOptions()['headers']);
		$this->assertContains(
			'Authorization: Basic ' . base64_encode('user@example.com:p@ss'),
			$basic->getRequestOptions()['headers'],
		);
	}

	public function testConfiguresIdentityAndCopy(): void
	{
		$send = self::sent();
		$factory = new JmapTransportFactory(null, new MockHttpClient([
			self::session(),
			self::lookup([]),
			self::upload(),
			$send,
		]));

		$factory
			->create(Dsn::fromString('jmap://token@api.fastmail.com?identity=id-fixed&keep_copy=0'))
			->send(
				$this->email(),
			);

		$submit = self::calls($send)[1][1];
		$this->assertSame('id-fixed', $submit['create']['submission']['identityId']);
		$this->assertSame(['#submission'], $submit['onSuccessDestroyEmail']);
	}

	public function testMatchesTheSenderAndKeepsTheCopyByDefault(): void
	{
		$send = self::sent();
		$factory = new JmapTransportFactory(null, new MockHttpClient([
			self::session(),
			self::lookup(),
			self::upload(),
			$send,
		]));

		$factory->create(Dsn::fromString('jmap://token@api.fastmail.com?identity='))->send($this->email());

		$submit = self::calls($send)[1][1];
		$this->assertSame('id-mailer', $submit['create']['submission']['identityId']);
		$this->assertArrayNotHasKey('onSuccessDestroyEmail', $submit);
	}

	public function testRequiresAUser(): void
	{
		$this->expectException(IncompleteDsnException::class);
		$this->expectExceptionMessage('User is not set.');

		(new JmapTransportFactory())->create(Dsn::fromString('jmap://api.fastmail.com'));
	}

	public function testRequiresAHostOrSessionUrl(): void
	{
		$this->expectException(IncompleteDsnException::class);
		$this->expectExceptionMessage('Set the JMAP server as host or pass the "session_url" option.');

		(new JmapTransportFactory())->create(Dsn::fromString('jmap://token@default'));
	}

	public function testRejectsOtherSchemes(): void
	{
		$this->expectException(UnsupportedSchemeException::class);
		$this->expectExceptionMessage(
			'The "smtp" scheme is not supported; supported schemes for mailer "jmap" are: "jmap".',
		);

		(new JmapTransportFactory())->create(Dsn::fromString('smtp://token@api.fastmail.com'));
	}

	private function sessionRequest(string $dsn): MockResponse
	{
		$session = self::session();
		$factory = new JmapTransportFactory(null, new MockHttpClient([
			$session,
			self::lookup(),
			self::upload(),
			self::sent(),
		]));

		$factory->create(Dsn::fromString($dsn))->send($this->email());

		return $session;
	}
}
