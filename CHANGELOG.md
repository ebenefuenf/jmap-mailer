# Changelog

## [Unreleased](https://github.com/ebenefuenf/jmap-mailer/commits/main)

### Added

- `JmapTransport` sends mail through a JMAP server: it uploads the rendered message, imports it into the Sent mailbox, and submits it with the SMTP envelope, including Bcc recipients.
- `JmapTransportFactory` creates the transport from `jmap://` DSNs with bearer token or basic auth, session discovery through `/.well-known/jmap`, and the options `session_url`, `identity`, and `keep_copy`.
- Credentials are only sent over HTTPS: the transport refuses plain HTTP URLs from the session resource or a redirect. The `jmap+http://` scheme allows plain HTTP for servers without TLS.
- API and upload URLs in the session resource may be relative to the session URL.
- The transport picks the identity matching the envelope sender, including wildcard identities, and deletes the imported message again if the server rejects the submission.
