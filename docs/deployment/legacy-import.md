# Legacy trading import

This local operator importer moves explicitly mapped community text and saved outputs into the Laravel hub. It does not migrate authentication or modify the authoritative paper ledger. No real legacy export was imported during implementation.

Run against an independently backed-up, reviewed target only within the applicable execution/release authorization. Prepare the manifest outside public storage, protect it as private account/community data, and review its SHA-256 through a separate operator step:

```sh
php artisan trading:import-legacy /protected/export/manifest.json --sha256=REVIEWED_64_CHARACTER_SHA256 --owner=EXISTING_APPROVED_OWNER_ID
```

The command requires an approved existing Laravel owner. It never creates users, invitations, passwords, passkeys, MFA factors, memberships or roles. Every legacy author must map explicitly to an existing local integer user ID; email matching and imported administrator flags cannot grant access.

## Manifest format

The JSON root admits exactly `schema_version`, `source_id`, `identities`, `topics`, `comments`, `outputs`, and `archives`. Use a stable source ID for a particular legacy installation; do not change it to bypass an import conflict. Empty collections must be present. Files are limited to 20 MB; split a larger export into reviewed batches retaining the same source ID and immutable record IDs.

```json
{
  "schema_version": 1,
  "source_id": "discourse-original",
  "identities": {"discourse-user-17": 42},
  "topics": [{
    "id": "topic-110", "user_id": "discourse-user-17",
    "title": "Original discussion", "body": "Original first-post raw text",
    "created_at": "2026-11-01T10:00:00Z", "visibility": "all_members"
  }],
  "comments": [{
    "id": "post-301", "topic_id": "topic-110", "user_id": "discourse-user-17",
    "body": "Original reply raw text", "created_at": "2026-11-01T10:01:00Z",
    "visibility": "all_members"
  }],
  "outputs": [{
    "id": "report-50", "user_id": "discourse-user-17",
    "title": "Original saved research", "content": "Original report text",
    "created_at": "2026-11-01T11:00:00Z", "visibility": "all_members",
    "sources": [{"title": "Original source", "url": "https://example.org/source"}]
  }],
  "archives": []
}
```

The IDs in this example are synthetic. Identity values must be actual existing local integer IDs, never objects containing roles or emails. Topic/comment/output IDs and author references are explicit strings. Topics may include `locked: true`; `archetype` may only be `regular`, `read_restricted` may only be false, and `uploads` may only be empty. Additional source fields are rejected to avoid silently discarding security-relevant metadata. Convert source dates to explicit UTC/offset timestamps and preserve original text rather than executable cooked HTML.

`visibility: all_members` is an explicit operator audience decision. It must be backed by source category/topic/post permission evidence. Never normalize a private category or private message to this value merely to make import succeed. Current community models expose imported topics to all approved hub members; they have no category-specific permissions, private channels or per-post ACLs.

Imports reject unknown/private audiences, private-message archetypes, restricted categories, nonempty uploads and Discourse `upload://`, `/uploads/` or `secure-uploads` references in text. Attachments, secure uploads, ACLs and deleted/moderated content require an accepted storage/visibility/retention policy before their migration. Preserve their original private backup and inventory; this command cannot safely import them. Plain external links remain text and are not fetched.

Outputs always enter `in_review`, with no publication date or legacy review authority. Legacy publication state cannot publish into the hub. Their original content checksum and namespaced external ID remain separate from subsequent review/publication state.

## Repeatability and conflicts

Each imported record receives a unique receipt keyed by `(source_id, record_type, legacy_id)`, with the manifest SHA-256, record checksum, mapped author, destination type/ID and importing owner. The checksum covers the entire normalized source record plus mapped author; keep its JSON field ordering stable across exports.

An identical replay skips the existing destination. It does not overwrite edited text, publication state, review state, or dates. Changed source text or a changed author mapping fails with a checksum conflict. Missing destinations are not silently resurrected. Topic references in comments must resolve to receipts from the same source. All records in one manifest are committed transactionally: a late conflict/unmapped reference rolls back that batch's new records.

Resolve a genuine changed legacy record through a separately reviewed revision/reconciliation workflow; do not delete receipts, change source IDs or overwrite published content to force import. Keep original protected exports and their checksums for recovery and forensic comparison.

## Ghostfolio financial archives

Ghostfolio's cached `ExportResponse` and export service produce `accounts`, `activities`, `assetProfiles`, `meta`, `platforms`, `tags` and `user.settings` (currency/performance calculation type). The importer can preserve that export JSON as an immutable archive receipt:

```json
{
  "id": "portfolio-export-2026-11-16",
  "kind": "ghostfolio",
  "payload": {
    "accounts": [], "activities": [], "assetProfiles": [],
    "meta": {"date": "2026-11-16T10:00:00Z", "version": "source-version"},
    "platforms": [], "tags": [],
    "user": {"settings": {"currency": "USD"}}
  }
}
```

Place this record in `archives`. It creates no app-editable financial activities, ledger accounts, transactions, integrations snapshot or orders. The authoritative native paper engine remains the sole financial writer. The archive preserves export structure; keep the original export bytes and their independently recorded checksum alongside the manifest because JSON normalization may change serialization/number representation. Archive access/administration UI is not implemented by this importer.

## Source findings and remaining acceptance

The inspected cached Ghostfolio interface excludes authentication credentials and user IDs from its financial export; its `user` object contains settings. The inspected Discourse category model has `read_restricted` and group permissions, so text-only topic exports cannot establish audience on their own. The existing Hosting `create-owner.rb` creates a Discourse administrator and adds member/reviewer groups; those roles are intentionally not imported as Laravel authority.

Sources inspected locally: Kernscale-Infrastructure `.orchestration/cache/trading-community-build-20261112/open-source/ghostfolio/libs/common/src/lib/interfaces/responses/export-response.interface.ts`, Ghostfolio `apps/api/src/app/endpoints/export/export.service.ts`, cached `discourse-category.rb`, and Kernscale-Hosting `server/production/deployment/kernscale-trading/create-owner.rb`. These establish field/permission requirements, not a current live export inventory.

The command consumes the reviewed normalized manifest; it does not directly parse a Discourse PostgreSQL dump or discover users by email. Source extraction, completeness reconciliation, protected attachment preservation, private-channel policy, archive UI, live native financial reconciliation and browser acceptance remain separate work. No full Ghostfolio or Discourse parity claim follows from this importer.

Targeted local validation: `php artisan test tests/Feature/LegacyTradingImportTest.php` passed 8 tests/32 assertions; changed PHP files passed targeted Pint. Tests use synthetic data and cover replay, later publication retention, content/remapping conflicts, private/upload denial, owner/checksum authorization, unknown references with rollback and financial archive isolation.
