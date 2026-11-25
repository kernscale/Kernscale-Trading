# Contributing

Contributions to the application, tests, documentation, accessibility and research
tooling are welcome.

## Propose a change

1. Open an issue describing the problem or improvement. Discuss larger changes
   with a maintainer before implementing them.
2. Fork the repository and create a branch for one focused change.
3. Follow the [local setup](README.md#local-development), using synthetic data and
   your own development environment. Production membership, provider credentials
   and access to Kernscale infrastructure are not needed to contribute.
4. Add or update a focused regression check when behavior changes, then open a
   pull request explaining the problem, resulting behavior and checks performed.

Maintainers review and merge contributions. Contributors do not need write or
admin access to this repository. Pull requests require a code-owner review,
passing required checks and resolved review discussions. New reviewable changes
dismiss approvals. A different maintainer must review a maintainer's own pull request.

## Local checks

Run the checks relevant to your change:

```sh
# One affected PHP feature test; substitute the relevant existing test file.
php artisan test tests/Feature/Auth/AuthenticationTest.php

# Frontend types when changing frontend code.
npm run types:check

# Formatting for changed PHP files only.
vendor/bin/pint --test path/to/changed-file.php
```

For bridge work, follow [bridge setup and checks](bridge/README.md). Use the
existing focused checks for the module you change. Report unavailable checks in
the pull request. CI runs the selected source-validation checks.

## Protect private data and execution boundaries

- Commit source, synthetic fixtures and public documentation. Keep `.env` files,
  API credentials, database copies, research sessions and operational reports out
  of Git. `.orchestration/` is local working state.
- Preserve approved membership, security enrollment and researcher isolation.
  Owner status must not expose another researcher's private sessions.
- Preserve the authoritative native paper ledger. Do not invent balances,
  performance, receipts or successful research runs.
- Contributor CI uses disposable GitHub-hosted runners and a read-only token.
  It must not connect to production, use private provider accounts or publish
  private images. Workflow changes receive the same review as application code.
- Report vulnerabilities privately using [SECURITY.md](SECURITY.md).

## License and conduct

By submitting a contribution, you agree that your contribution is provided under
the [MIT license](LICENSE). Include only work you are entitled to contribute and
preserve third-party copyright and license notices. Dependencies retain their own
licenses; reference material is not evidence of permission to redistribute it.

Follow the [Code of Conduct](CODE_OF_CONDUCT.md). Respectful, reproducible reports
and small, understandable pull requests make review easier.
