<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer\Tests;

use Ebenefuenf\JmapMailer\JmapTransport;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Email;

abstract class TestCase extends BaseTestCase
{
	protected const string SESSION_URL = 'https://jmap.example.com/.well-known/jmap';
	protected const string API_URL = 'https://api.example.com/jmap/api/';
	protected const string ACCOUNT = 'u1';
	protected const string SENT = 'mb-sent';
	protected const string DRAFTS = 'mb-drafts';

	protected function transport(MockResponse ...$responses): JmapTransport
	{
		return new JmapTransport(self::SESSION_URL, 'secret-token', null, new MockHttpClient($responses));
	}

	protected function loggedTransport(LoggerInterface $logger, MockResponse ...$responses): JmapTransport
	{
		return new JmapTransport(
			self::SESSION_URL,
			'secret-token',
			null,
			new MockHttpClient($responses),
			null,
			$logger,
		);
	}

	protected function email(): Email
	{
		return (new Email())
			->from('Mailer <mailer@example.com>')
			->to('to@example.org')
			->cc('cc@example.org')
			->bcc('bcc@example.org')
			->subject('Hello')
			->text('Hello world');
	}

	/**
	 * @param array<string, mixed> $override
	 */
	protected static function session(array $override = []): JsonMockResponse
	{
		return new JsonMockResponse(array_replace([
			'capabilities' => [
				'urn:ietf:params:jmap:core' => [],
				'urn:ietf:params:jmap:mail' => [],
				'urn:ietf:params:jmap:submission' => [],
			],
			'accounts' => [
				self::ACCOUNT => [
					'name' => 'mailer@example.com',
					'isPersonal' => true,
					'isReadOnly' => false,
					'accountCapabilities' => [
						'urn:ietf:params:jmap:mail' => [],
						'urn:ietf:params:jmap:submission' => [],
					],
				],
			],
			'primaryAccounts' => [
				'urn:ietf:params:jmap:mail' => self::ACCOUNT,
				'urn:ietf:params:jmap:submission' => self::ACCOUNT,
			],
			'apiUrl' => self::API_URL,
			'uploadUrl' => 'https://api.example.com/jmap/upload/{accountId}/',
		], $override));
	}

	/**
	 * @param list<array{id: string, email: string}> $identities
	 */
	protected static function lookup(
		array $identities = [['id' => 'id-mailer', 'email' => 'mailer@example.com']],
		?string $sent = self::SENT,
		?string $drafts = self::DRAFTS,
	): JsonMockResponse {
		return self::methodResponses(
			['Identity/get', ['accountId' => self::ACCOUNT, 'list' => $identities, 'notFound' => []], 'identities'],
			['Mailbox/query', ['accountId' => self::ACCOUNT, 'ids' => $sent === null ? [] : [$sent]], 'sent'],
			['Mailbox/query', ['accountId' => self::ACCOUNT, 'ids' => $drafts === null ? [] : [$drafts]], 'drafts'],
		);
	}

	protected static function upload(string $blobId = 'blob-1'): JsonMockResponse
	{
		return new JsonMockResponse([
			'accountId' => self::ACCOUNT,
			'blobId' => $blobId,
			'type' => 'message/rfc822',
			'size' => 42,
		]);
	}

	protected static function sent(string $submissionId = 'sub-1'): JsonMockResponse
	{
		return self::methodResponses(
			['Email/import', ['accountId' => self::ACCOUNT, 'created' => ['email' => ['id' => 'email-1']]], 'import'],
			[
				'EmailSubmission/set',
				[
					'accountId' => self::ACCOUNT,
					'created' => ['submission' => ['id' => $submissionId, 'undoStatus' => 'final']],
					'notCreated' => null,
				],
				'submit',
			],
		);
	}

	/**
	 * @param array{string, array<string, mixed>, string} ...$responses
	 */
	protected static function methodResponses(array ...$responses): JsonMockResponse
	{
		return new JsonMockResponse(['methodResponses' => $responses, 'sessionState' => 'state-1']);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function payload(MockResponse $response): array
	{
		$body = $response->getRequestOptions()['body'] ?? '';
		self::assertIsString($body);
		$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($payload);

		return $payload;
	}

	/**
	 * @return list<array{string, array<array-key, mixed>, string}>
	 */
	protected static function calls(MockResponse $response): array
	{
		/** @var list<array{string, array<array-key, mixed>, string}> */
		return self::payload($response)['methodCalls'];
	}
}
