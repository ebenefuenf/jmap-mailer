<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\TransportException;

/** @internal */
final readonly class Responses
{
	/**
	 * @param array<string, array{string, array<array-key, mixed>}> $responses method call ID => method name and
	 *     arguments; the name is "error" if the call failed
	 */
	public function __construct(
		private array $responses,
	) {}

	/**
	 * Reads the response to an API request. The first response per method call ID wins, because implicit calls,
	 * such as the Email/set after onSuccessDestroyEmail, reuse the ID of the call that caused them.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function fromJson(array $data): self
	{
		$responses = [];

		foreach (Json::list($data['methodResponses'] ?? null, 'methodResponses') as $response) {
			$responses[Json::string($response[2] ?? null, 'methodResponses')] ??= [
				Json::string($response[0] ?? null, 'methodResponses'),
				Json::array($response[1] ?? null, 'methodResponses'),
			];
		}

		return new self($responses);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function get(string $id): array
	{
		[$name, $arguments] = $this->responses[$id] ?? throw new TransportException(sprintf(
			'The JMAP server did not answer the method call "%s".',
			$id,
		));

		if ($name === 'error') {
			throw new TransportException(sprintf(
				'The JMAP method call "%s" failed: %s',
				$id,
				Json::error($arguments),
			));
		}

		return $arguments;
	}

	/**
	 * Returns the server ID of an object created by a /set or /import call.
	 */
	public function created(string $id, string $creationId, string $failure): string
	{
		$response = $this->get($id);
		$created = Json::array($response['created'] ?? [], 'created');
		$object = Json::optionalArray($created[$creationId] ?? null);

		if ($object !== null) {
			return Json::string($object['id'] ?? null, 'id');
		}

		$notCreated = Json::array($response['notCreated'] ?? [], 'notCreated');
		$error = Json::optionalArray($notCreated[$creationId] ?? null);

		throw new TransportException(sprintf(
			'%s: %s',
			$failure,
			$error === null ? 'the server gave no reason' : Json::error($error),
		));
	}
}
