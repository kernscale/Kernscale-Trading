# Existing-member administration

`PATCH /admin/members/{user}` updates an existing account only. The route uses authenticated, verified, approved, security-enrolled membership plus recent password/passkey confirmation. Only a currently approved owner may perform it; the actor is checked again after acquiring the shared owner-role lock.

```json
{"role":"researcher","membership_status":"approved"}
```

Both fields are required. `role` is exactly `member`, `researcher`, or `owner`; `membership_status` is `approved` or `revoked`. The supplied role replaces the account's application roles. Invalid values produce normal Laravel validation errors (422 for JSON). Nonowners, self-revocation/self-demotion and removal of the final approved owner are denied with 403. An owner may update their own account only while retaining `owner` plus `approved`.

Role/status changes and one `member.updated` audit entry commit atomically. The entry contains actor/target IDs and the old/new role lists and membership states, without credentials or private research contents. An identical replay is a no-op and creates no additional audit entry. Owner-role and target-row locks serialize administrative updates, through the same transaction for PATCH and DELETE revocation.

Restoring a revoked account uses its existing ID with `membership_status: approved` and the desired role. Passwords, passkeys, confirmed MFA, user IDs and owned content are preserved; there is no reinvitation or credential reset. Restoration does not resurrect remembered login tokens or restart cancelled research. Owners gain no access to another researcher's private sessions or drafts through this operation.

Revocation clears the remember token. Revocation or a change to `member` also records cancellation intent in the same transaction: unclaimed `queued` runs become locally `cancelled`; dispatched/active or interrupted runs become `cancel_requested`. No provider/bridge request runs during the administrative update. The existing `trading:reconcile` worker issues cancellation requests and confirms their actual native results; a failed bridge call leaves cancellation pending and never claims a running process has stopped. Terminal completed/failed/cancelled runs remain unchanged. Continue running the existing reconciler for eventual cancellation acknowledgement.

PATCH success returns the existing form redirect with status `Member updated.` Existing `DELETE /admin/members/{user}` delegates to the same transaction, authorization, audit and cancellation logic and retains its redirect status `Membership revoked.` DELETE preserves every existing target role, including multiple roles, and only changes membership to `revoked`; repeating it creates no additional audit entry. No route or frontend change is required for existing revoke callers.
