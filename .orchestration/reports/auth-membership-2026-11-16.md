# Local authentication and membership implementation

Local changes only; no invitation was issued to a real address, account created, production migration, or deployment performed.

- Public registration is disabled. Operator `php artisan trading:bootstrap-owner owner@example.com` creates and emails an expiring invitation for the first owner; it never prints a token/password. The command blocks when an owner or valid owner invitation exists and serializes bootstrap on the owner role row.
- Invitations expire after 24 hours, store only a SHA-256 token hash, bind acceptance to the invited email, and are consumed transactionally once. Owner-only invite/revoke endpoints require approved membership, complete enrollment and recent password/passkey confirmation.
- Approved membership is checked afresh from the database for every authenticated web request, including settings/Fortify endpoints. Revoked sessions are invalidated. Pending/revoked users cannot sign in with passwords or passkeys.
- Both a passkey and confirmed authenticator enrollment gate application pages. Enrollment/settings remain accessible before both are complete.
- Official Laravel passkey login does not pass through Fortify's TOTP pipeline. The app's passkey response therefore logs the user out and stages Fortify's authenticator/recovery challenge. The shared guard also challenges remembered/authenticated sessions without TOTP assurance. Successful TOTP confirmation/login establishes assurance for that user in the session.
- Required TOTP cannot be disabled and the final passkey cannot be removed. Recovery codes use Fortify's single-use replacement behavior. Self-deletion is disabled to preserve owned research. Owners cannot revoke themselves or remove the final approved owner.
- Spatie owner/researcher/member roles are created by migration; no real or demo user is seeded. Factory approval is test fixture data; database membership defaults to pending and is not mass assignable.

## Observed checks

- Initial focused membership checks failed before implementation (missing membership column/public registration gate).
- `php artisan test tests/Feature/Auth tests/Feature/DashboardTest.php`: exit 0, 44 passed, 134 assertions.
- `php artisan test tests/Feature/Settings`: exit 0, 11 passed, 66 assertions.
- Changed PHP files formatted with targeted `vendor/bin/pint`: exit 0.
- `npm run types:check`: exit 127, `tsc: command not found` before root installs frontend dependencies. Root owns subsequent frontend typecheck and browser validation.

## Remaining acceptance

Real delivery must use a configured private SMTP/API mail transport. Laravel's development log mailer writes the invitation email/token to the app log and must not be used for real invitations. No real email was sent here.

Canonical-origin browser passkey enrollment/login/confirmation and TOTP/recovery interaction still require browser acceptance with actual/virtual authenticators. No production build, broad test suite, release, production account, external mail change, or deployment was performed in this lane. Passkey removal and owner revocation serialize on the user/owner-role rows to preserve the final enrolled key and approved owner.
