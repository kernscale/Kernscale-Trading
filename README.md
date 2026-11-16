# Kernscale Trading Hub

One Laravel 13 / React application for approved Kernscale members: shared paper portfolio, scheduled research receipts, private R1 research, human-reviewed outputs/strategies and community discussions. Native TradingAgents schedules and the Trading-CLI paper ledger remain on Agents2. This checkout is a local candidate, not a production release.

The approved portfolio scope is **one shared paper account and private watchlists**. Laravel stores projections and research/application state; it does not maintain another editable financial ledger.

## Local development

Requirements: PHP 8.4, Composer, Node 24+ and SQLite. Production uses external PostgreSQL and Redis.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
php artisan serve --host=127.0.0.1 --port=8007
npm run dev -- --host 127.0.0.1 --port 5177 --strictPort
```

Set `APP_URL=http://localhost:8007` and use **localhost** in the browser; passkeys reject a numeric loopback origin. Use separate passkeys for previews and the canonical HTTPS trading domain. Public registration is disabled. Configure protected email delivery and run `php artisan trading:bootstrap-owner owner@example.com` to send a single-use owner invitation. Do not use log/array mailers for production invitations. Enroll an authenticator app and a passkey before entering the hub.

The web app needs neither OMP nor provider keys in the browser. [The private bridge](bridge/README.md) runs separately on Agents2. Until it is configured and qualified, research runtime and native portfolio observations remain unavailable; saved records stay readable. `php artisan trading:sync` refreshes native projections and receipts; `php artisan trading:reconcile` reconciles existing run IDs without creating replacement work. The app scheduler runs these commands independently of the original research/tick timers.

## Boundaries

- Approved members read published research and the shared paper portfolio, participate in community discussions and manage their private watchlists.
- Researchers and owners use their own private research sessions, thesis and board. Agent workspace changes remain proposals until the session owner explicitly applies them.
- Owners invite/revoke members and review publication. Owner status does not grant access to another researcher's private session.
- Native receipts are immutable. Draft revisions and publication decisions are separate records. Backend outages do not become zero balances, fabricated history or a successful run.

## Validation and deployment

Run a specific test file with `php artisan test tests/Feature/ResearchLifecycleTest.php`, frontend types with `npm run types:check`, and only changed-file formatting/lint. Tests use `withoutVite()` and do not need a production asset build. Bridge checks and pinned native fixtures are documented in its README.

[Runtime packaging](docs/deployment/runtime.md), [legacy import](docs/deployment/legacy-import.md), [native mark capture](bridge/MARKS.md), and [.orchestration/progress.md](.orchestration/progress.md) record ownership and outstanding acceptance. Docker packaging does not itself admit the app to Ops. Release requires immutable image/source identity, real canonical-origin authentication, Linux isolation/provider acceptance, native state reconciliation, measured encrypted off-host recovery, privileged Ops admission and `ack release`.
