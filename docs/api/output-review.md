# Output revision and review contract

All endpoints retain authenticated, verified, approved and security-enrolled membership. Creating, revising and submitting an authored output require the current `researcher` or `owner` role; editing/submission still require authorship. An owner role does not allow editing someone else's private draft. Review decisions require `owner`.

## Review request: required version

`POST /outputs/{output}/review`

```json
{"decision":"publish","comment":"Reviewed evidence","version":2}
```

`decision` is `publish` or `changes_requested`; `comment` is optional/nullable. `version` is required and must be the positive integer version of the output actually reviewed. Numeric form strings such as `"2"` are accepted and normalized after validation. The output must still be `in_review` at that version when locked in the transaction. Otherwise the decision conflicts and no publication, review metadata or audit entry is written. Review decisions and their audit entry commit together.

There is deliberately no fallback to the server's current version. JSON validation failures return 422 with a `version` field error; ordinary form/Inertia submissions use Laravel validation errors. Stale status/version yields 409 for JSON/ordinary requests; the existing Inertia conflict handler redirects with its conflict error. Refresh and explicitly review the current output before retrying.

**Frontend handoff:** both committed `pages/hub/outputs.tsx` and the current Claude-owned `components/hub/output-detail.tsx` initialize review data with only `decision`/`comment`. Their review request must include the selected output's reviewed `version`. Bind it when that revision is selected/reviewed; silently fetching/substituting a newer version at submission would defeat the protection. Codex has not changed either frontend file.

## Authored edits and submission

`PATCH /outputs/{output}` keeps `{title,content,version}`. The validated version is normalized once, so valid URL-encoded numeric strings work. Existing editable-state checks, immutable external-output protection, revision snapshots and optimistic conflict handling remain.

`POST /outputs/{output}/submit` keeps its existing body and transition to `in_review`. After downgrade to `member`, an author receives 403 for both editing and submission; membership alone no longer preserves researcher mutation permissions. Reading still follows existing output visibility.
