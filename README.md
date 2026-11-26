# A JMAP transport for Symfony Mailer

This package contains a transport class for [Symfony Mailer](https://symfony.com/doc/current/mailer.html) to send transactional mails through a [JMAP](https://jmap.io/) server ([RFC 8620](https://www.rfc-editor.org/rfc/rfc8620), [RFC 8621](https://www.rfc-editor.org/rfc/rfc8621)), for example [Fastmail](https://www.fastmail.com/) or [Cyrus IMAP](https://www.cyrusimap.org/).

## Installation

```console
composer require ebenefuenf/jmap-mailer
```

The package requires PHP 8.3 or newer and works with Symfony Mailer 7.4 and 8. Of Symfony HttpClient, it needs 7.4.18 or a later 7.4 release, or 8.1.6 or newer: older versions can forward the credentials when a server redirects from HTTPS to plain HTTP.

## Configuration

Configure the transport with a DSN:

```text
jmap://TOKEN@api.fastmail.com
jmap://USER:PASSWORD@mail.example.com
```

- Without a password, the user part is sent as bearer token. With a password, user and password are sent with basic auth.
- URL-encode special characters in user and password, for example `@` as `%40`.
- The transport discovers the JMAP session at `https://HOST/.well-known/jmap`. Add a port to the host if needed, like `mail.example.com:8443`.
- Credentials are only sent over HTTPS. If the session resource or a redirect of the session request points to a plain HTTP URL, the transport refuses to use it. It does not follow redirects of API and upload requests, so a redirect cannot carry a message to another URL.
- For a server without TLS, such as a local test server, use the `jmap+http` scheme, like `jmap+http://USER:PASSWORD@localhost:8080`. It discovers the session over plain HTTP and sends the credentials unencrypted.

The DSN accepts these options:

| Option | Default | Description |
| --- | --- | --- |
| `session_url` | discovered | The URL of the JMAP session resource, for servers without `/.well-known/jmap`. With this option, the host may be `default`. It must be an `https://` URL, unless the scheme is `jmap+http`. |
| `identity` | matching `From` | The ID of the JMAP identity to send every message with. |
| `keep_copy` | `1` | Set to `0` to delete the copy in the Sent mailbox after sending. |

For example:

```text
jmap://USER:PASSWORD@default?session_url=https://mail.example.com/jmap/session&keep_copy=0
```

### Symfony applications

Register the transport factory as a service:

```yaml
# config/services.yaml
services:
    Ebenefuenf\JmapMailer\JmapTransportFactory:
        parent: mailer.transport_factory.abstract
        tags: ['mailer.transport_factory']
```

Then set the DSN as usual:

```dotenv
MAILER_DSN=jmap://TOKEN@api.fastmail.com
```

### Without the Symfony framework

Create the transport with the factory:

```php
use Ebenefuenf\JmapMailer\JmapTransportFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;

$transport = (new JmapTransportFactory())->create(Dsn::fromString('jmap://TOKEN@api.fastmail.com'));
$mailer = new Mailer($transport);
```

To combine it with the built-in transports, for example in a `failover()` DSN, add the factory to Symfony's default factories:

```php
use Ebenefuenf\JmapMailer\JmapTransportFactory;
use Symfony\Component\Mailer\Transport;

$transport = (new Transport([new JmapTransportFactory(), ...Transport::getDefaultFactories()]))
    ->fromString('failover(jmap://TOKEN@api.fastmail.com smtp://USER:PASSWORD@smtp.example.com)');
```

## How sending works

JMAP sends only messages that are stored in the account. For each message, the transport:

1. uploads the MIME message as Symfony renders it, including attachments and signatures,
2. imports it into the Sent mailbox, or into Drafts if the account has no Sent mailbox,
3. submits it with the SMTP envelope, which also carries the Bcc recipients.

Import and submission share one API request. The session, the identities, and the mailboxes are fetched once per transport instance, so further messages need two HTTP requests each. After a failed send, the transport fetches them again, which lets a long-running worker pick up a changed account.

The identity is the one whose address matches the `From` address, because a JMAP identity stands for a `From` address. If no identity matches it, or if you send a `RawMessage`, the transport tries the envelope sender, which is the `Sender`, `Return-Path`, or `From` address unless you pass an envelope. An exact match wins over a wildcard identity like `*@example.com`. An identity without an address, as Cyrus reports it, is used when no other identity matches. The `From` display name does not need to match the identity's name.

If no identity matches, the transport throws a `TransportException`. Its `getDebug()` output lists the addresses of the available identities.

The envelope sender is submitted as the SMTP return address with either identity. The server decides whether it accepts a return address that differs from the identity.

If the server rejects the submission, the transport deletes the imported message again and throws a `TransportException` with the server's reason, for example `forbiddenFrom`. Without `keep_copy=0`, a sent message stays in the Sent mailbox; with it, the server deletes the copy after a successful submission. A copy in Drafts is always deleted after sending.

`SentMessage::getMessageId()` returns the ID of the JMAP email submission.

## Limitations

- Credentials that can send mail through JMAP can also read and delete the mail of the account. Use a dedicated account for an application's mail.
- If `/.well-known/jmap` redirects to another host, Symfony's HTTP client does not forward the credentials. Set `session_url` to the redirect target instead.
- The copy in the Sent mailbox does not list the Bcc recipients, because Symfony removes the `Bcc` header from the message it sends.
- A server may refuse to store the same message twice and reject the import with `alreadyExists`. This affects an identical `RawMessage` that is sent again while the first copy exists. Symfony gives every other message a new `Message-ID`.
- The server reports bounces and other delivery failures after the submission. The transport does not wait for them; the submission ID lets you query the delivery status through JMAP.
- `SentMessage::getDebug()` stays empty. The HTTP debug output would contain the credentials.

## Servers

The transport is tested with Fastmail and Cyrus IMAP. Other JMAP servers, such as [Stalwart](https://stalw.art/), should work but are untested.

### Fastmail

Create an API token under Settings → Privacy & Security → API tokens:

- Choose the JMAP protocol.
- Turn off "Read-only access". A read-only token cannot store the message, and the transport refuses it.
- Enable the "Email submission" scope. Fastmail adds the "Email" scope automatically.

Then use the token in the DSN:

```dotenv
MAILER_DSN=jmap://TOKEN@api.fastmail.com
```

You can send from every address listed under Settings → My email addresses, because each of them is a JMAP identity.

A token with these scopes can read and delete all mail in the account. For an application's mail, consider a dedicated Fastmail user instead of a personal mailbox.

### Cyrus IMAP

Use the account's user name and password:

```dotenv
MAILER_DSN=jmap://USER:PASSWORD@mail.example.com
```

Cyrus reports a single identity without an address, which the transport uses for every `From` address.

## Development

Run the checks before committing:

```console
composer ci
```

It runs the [Mago](https://mago.carthage.software/) formatter and linter (`composer lint`), the [PHPUnit](https://phpunit.de/) test suite with a coverage analysis (`composer coverage`), and [Psalm](https://psalm.dev/) (`composer types`).

### Integration test

The integration test sends one real message, with an HTML part and an attachment. It is not part of the default test suite and is skipped unless all of these variables are set:

```console
JMAP_TEST_DSN='jmap://TOKEN@api.fastmail.com?keep_copy=0' \
JMAP_TEST_FROM='sender@example.com' \
JMAP_TEST_TO='recipient@example.org' \
composer test:integration
```

#### Local server

The [Cyrus Docker test server](https://github.com/cyrusimap/cyrus-docker-test-server) provides the users `user1` to `user5` at `example.com`, accepts any password, and keeps all mail inside the container:

```console
docker run --rm -p 127.0.0.1:8080:8080 ghcr.io/cyrusimap/cyrus-docker-test-server
```

Then run the test against it:

```console
JMAP_TEST_DSN='jmap+http://user1:secret@localhost:8080' \
JMAP_TEST_FROM='user1@example.com' \
JMAP_TEST_TO='user2@example.com' \
composer test:integration
```

### Mutation testing

Mutation testing with [Infection](https://infection.github.io/) is not part of `composer ci`, but the CI workflow runs it after the coverage step and enforces the minimum mutation score from `infection.json5.dist`. Pushes only mutate the changed lines; a weekly scheduled run covers the whole codebase. Run it locally with:

```console
composer mutation
```

Reports are written to `.infection/`. The script clears `JMAP_TEST_DSN`, so mutation testing never sends real mail.

## License

This project is licensed under the [MIT license](LICENSE.md).
