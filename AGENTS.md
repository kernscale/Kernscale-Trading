# Kernscale Trading Hub

Apply the global Codex contract. This repository owns the Laravel member application and its private research bridge; it does not own the native paper ledger or scheduled TradingAgents configuration.

- Preserve one authoritative paper ledger on Agents2. Never fabricate financial history, research progress or performance.
- All app routes require approved membership and completed security enrollment. Research sessions are private to their researcher; an owner role does not grant access.
- Use Fortify/passkeys and Spatie roles. Keep provider credentials server-side and Tauric cloud session synchronization disabled.
- Keep authored source files below 500 lines; preserve unchanged upstream registry components. Keep implementation state and detailed check receipts in `.orchestration/`.
- Existing vendored UI primitives may receive narrow compatibility/accessibility fixes without restructuring their upstream code.
- Run affected feature tests directly with `php artisan test <path>`, frontend `npm run types:check`, changed-file formatting and one targeted browser flow. Do not invoke broad composer test/build scripts unless requested.
- Production deployment/cutover requires `ack release`; local auth and isolation implementation has `ack execute`.
