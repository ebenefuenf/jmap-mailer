<?php

declare(strict_types=1);

namespace Ebenefuenf\JmapMailer;

use Symfony\Component\Mailer\Exception\TransportException;

/** @internal */
final class Json
{
	/**
	 * @return array<array-key, mixed>
	 */
	public static function array(mixed $value, string $name): array
	{
		return self::optionalArray($value) ?? throw self::invalid($name);
	}

	/**
	 * @return array<array-key, array<array-key, mixed>>
	 */
	public static function list(mixed $value, string $name): array
	{
		return array_map(static fn(mixed $item): array => self::array($item, $name), self::array($value, $name));
	}

	public static function string(mixed $value, string $name): string
	{
		return self::optionalString($value) ?? throw self::invalid($name);
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	public static function optionalArray(mixed $value): ?array
	{
		return is_array($value) ? $value : null;
	}

	/**
	 * @return non-empty-string|null
	 */
	public static function optionalString(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Describes a JMAP method error or SetError, e.g. "forbiddenFrom: Not allowed to send from this address".
	 *
	 * @param array<array-key, mixed> $error
	 */
	public static function error(array $error): string
	{
		$type = self::optionalString($error['type'] ?? null) ?? 'unknown error';
		$description = self::optionalString($error['description'] ?? null);

		return $description === null ? $type : $type . ': ' . $description;
	}

	private static function invalid(string $name): TransportException
	{
		return new TransportException(sprintf(
			'The JMAP server returned an invalid response: "%s" is missing or invalid.',
			$name,
		));
	}
}
