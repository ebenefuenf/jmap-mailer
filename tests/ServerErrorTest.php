<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;

final class ServerErrorTest extends TestCase
{
	public function testReportsProblemDetails(): void
	{
		$response = new JsonMockResponse([
			'type' => 'urn:ietf:params:jmap:error:limit',
			'status' => 400,
			'title' => 'Limit reached',
			'detail' => 'Too many concurrent requests',
		], ['http_code' => 400]);
		$transport = $this->transport($response);

		try {
			$transport->send($this->email());
			$this->fail('The transport must report the failed request.');
		} catch (HttpTransportException $e) {
			$this->assertSame(
				'The JMAP server responded with HTTP status 400 (urn:ietf:params:jmap:error:limit): '
					. 'Too many concurrent requests',
				$e->getMessage(),
			);
			$this->assertSame(400, $e->getCode());
			$this->assertSame(400, $e->getResponse()->getStatusCode());
		}
	}

	#[DataProvider('failedRequests')]
	public function testDescribesFailedRequests(MockResponse $response, string $message): void
	{
		$transport = $this->transport($response);

		$this->expectException(HttpTransportException::class);
		$this->expectExceptionMessage($message);

		$transport->send($this->email());
	}

	/**
	 * @return iterable<string, array{MockResponse, string}>
	 */
	public static function failedRequests(): iterable
	{
		yield 'title only' => [
			new JsonMockResponse(['title' => 'Unauthorized'], ['http_code' => 401]),
			'The JMAP server responded with HTTP status 401: Unauthorized',
		];
		yield 'type only' => [
			new JsonMockResponse(['type' => 'about:blank'], ['http_code' => 403]),
			'The JMAP server responded with HTTP status 403 (about:blank).',
		];
		yield 'no problem details' => [
			new MockResponse('Service Unavailable', ['http_code' => 503]),
			'The JMAP server responded with HTTP status 503.',
		];
		yield 'redirect without target' => [
			new MockResponse('', ['http_code' => 300]),
			'The JMAP server responded with HTTP status 300.',
		];
		yield 'invalid JSON' => [
			new MockResponse('<html>', ['http_code' => 200]),
			'The JMAP server returned invalid JSON: ',
		];
		yield 'network error' => [
			new MockResponse('', ['error' => 'Connection refused']),
			'Could not reach the JMAP server: Connection refused',
		];
	}

	public function testAcceptsAnySuccessStatus(): void
	{
		$sent = $this->transport(
			self::session(),
			self::lookup(),
			new JsonMockResponse(['blobId' => 'blob-1'], ['http_code' => 299]),
			self::sent(),
		)->send($this->email());

		$this->assertSame('sub-1', $sent?->getMessageId());
	}

	#[DataProvider('invalidResponses')]
	public function testRejectsInvalidResponses(MockResponse $lookup, string $message): void
	{
		$transport = $this->transport(self::session(), $lookup);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage($message);

		$transport->send($this->email());
	}

	/**
	 * @return iterable<string, array{MockResponse, string}>
	 */
	public static function invalidResponses(): iterable
	{
		$invalid = 'The JMAP server returned an invalid response: "%s" is missing or invalid.';
		$mailboxes = [
			['Mailbox/query', ['ids' => ['mb-sent']], 'sent'],
			['Mailbox/query', ['ids' => []], 'drafts'],
		];

		yield 'method error' => [
			self::methodResponses([
				'error',
				['type' => 'unknownMethod', 'description' => 'No such method'],
				'identities',
			]),
			'The JMAP method call "identities" failed: unknownMethod: No such method',
		];
		yield 'method error without type' => [
			self::methodResponses(['error', [], 'identities']),
			'The JMAP method call "identities" failed: unknown error',
		];
		yield 'missing response' => [
			self::methodResponses(['Identity/get', ['list' => []], 'identities']),
			'The JMAP server did not answer the method call "sent".',
		];
		yield 'no method responses' => [new JsonMockResponse([]), sprintf($invalid, 'methodResponses')];
		yield 'response is no list' => [
			new JsonMockResponse(['methodResponses' => ['Identity/get']]),
			sprintf($invalid, 'methodResponses'),
		];
		yield 'response without name' => [
			new JsonMockResponse(['methodResponses' => [[1 => [], 2 => 'identities']]]),
			sprintf($invalid, 'methodResponses'),
		];
		yield 'response without arguments' => [
			new JsonMockResponse(['methodResponses' => [['Identity/get', null, 'identities']]]),
			sprintf($invalid, 'methodResponses'),
		];
		yield 'response without call ID' => [
			new JsonMockResponse(['methodResponses' => [['Identity/get', []]]]),
			sprintf($invalid, 'methodResponses'),
		];
		yield 'identity without ID' => [
			self::methodResponses([
				'Identity/get',
				['list' => [['email' => 'a@example.com']]],
				'identities',
			], ...$mailboxes),
			sprintf($invalid, 'id'),
		];
		yield 'identity is no object' => [
			self::methodResponses(['Identity/get', ['list' => ['a@example.com']], 'identities'], ...$mailboxes),
			sprintf($invalid, 'list'),
		];
		yield 'no identity list' => [
			self::methodResponses(['Identity/get', [], 'identities'], ...$mailboxes),
			sprintf($invalid, 'list'),
		];
		yield 'no mailbox IDs' => [
			self::methodResponses(
				['Identity/get', ['list' => []], 'identities'],
				['Mailbox/query', [], 'sent'],
				['Mailbox/query', ['ids' => []], 'drafts'],
			),
			sprintf($invalid, 'ids'),
		];
	}

	public function testRejectsUploadWithoutBlobId(): void
	{
		$transport = $this->transport(self::session(), self::lookup(), new JsonMockResponse(['blobId' => '']));

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage('The JMAP server returned an invalid response: "blobId" is missing or invalid.');

		$transport->send($this->email());
	}

	public function testRejectsCreatedObjectWithoutId(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::methodResponses(
				['Email/import', ['created' => ['email' => ['blobId' => 'blob-1']]], 'import'],
				['EmailSubmission/set', ['created' => ['submission' => ['id' => 'sub-1']]], 'submit'],
			),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage('The JMAP server returned an invalid response: "id" is missing or invalid.');

		$transport->send($this->email());
	}

	public function testRejectsInvalidCreatedMaps(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::methodResponses(['Email/import', ['created' => 'email'], 'import']),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage('The JMAP server returned an invalid response: "created" is missing or invalid.');

		$transport->send($this->email());
	}

	public function testRejectsInvalidNotCreatedMaps(): void
	{
		$transport = $this->transport(
			self::session(),
			self::lookup(),
			self::upload(),
			self::methodResponses(['Email/import', ['created' => null, 'notCreated' => 'email'], 'import']),
		);

		$this->expectException(TransportException::class);
		$this->expectExceptionMessage(
			'The JMAP server returned an invalid response: "notCreated" is missing or invalid.',
		);

		$transport->send($this->email());
	}
}
