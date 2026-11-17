# Workspace proposal confirmation window

Workspace proposal payloads include read-only `expires_at`, an ISO8601 timestamp computed from the original `created_at` plus seven days. Serializing a proposal does not change its creation timestamp. Replaying the same saved event retains the original proposal and expiry; it never renews the confirmation window.

`POST /research/proposals/{proposal}/decision` retains its existing `decision: apply|reject` contract. The server locks the current proposal and session, rechecks the current actor's approved membership, researcher/owner permission and private session ownership, and then handles the decision. An owner role does not grant access to another researcher's private session.

A pending proposal expires exactly when the server clock reaches `expires_at`. Applying or rejecting it from that moment returns 409 with `This proposal expired. Request a new proposal.` The proposal remains pending and neither workspace content nor timestamps change. Missing creation timestamps fail closed with the same conflict. Existing optimistic thesis/card version checks still apply to unexpired proposals.

An already applied/rejected proposal can replay the same decision after expiry without mutation. The actor/session checks still run first; an opposite decision still conflicts. Expiry does not add a database column, scheduler, new status or automatic deletion.
