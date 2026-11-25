# A JMAP transport for Symfony Mailer

This package contains a transport class for [Symfony Mailer](https://symfony.com/doc/current/mailer.html) to send transactional mails through a [JMAP](https://jmap.io/) server.

## Development

Run the checks before committing:

```console
composer ci
```

It runs the [Mago](https://mago.carthage.software/) formatter and linter (`composer lint`), the [PHPUnit](https://phpunit.de/) test suite with a coverage analysis (`composer coverage`), and [Psalm](https://psalm.dev/) (`composer types`).

### Mutation testing

Mutation testing with [Infection](https://infection.github.io/) is not part of `composer ci`, but the CI workflow runs it after the coverage step and enforces the minimum mutation score from `infection.json5.dist`. Pushes only mutate the changed lines; a weekly scheduled run covers the whole codebase. Run it locally with:

```console
composer mutation
```

Reports are written to `.infection/`.

## License

This project is licensed under the [MIT license](LICENSE.md).
