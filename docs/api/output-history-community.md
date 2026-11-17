# Output history and community output links

These routes use the existing authenticated, verified, approved, security-enrolled member group.

## Saved output history

`GET /outputs/{output}/revisions` returns JSON only to the output's author. Other users receive 404, including owners reviewing or reading the current output. A downgraded author can still read their history but gains no mutation permissions.

```json
{
  "output_id": "output-uuid",
  "current_version": 3,
  "revisions": [
    {
      "version": 2,
      "snapshot": {"title": "Prior title", "content": "Prior evidence", "sources": [], "status": "draft"},
      "created_at": "2026-11-17 12:00:00"
    }
  ]
}
```

`revisions` contains only actual `output_revisions` snapshots, newest version first. The current output is not duplicated as a saved revision. An imported native report without saved revisions returns an empty array, regardless of its version number.

## Link a discussion to a published output

`POST /community` retains required `title` (maximum 200 characters) and `body`. Optionally send both `output_id` (UUID) and `output_version` (positive integer). The server requires that exact output version to be currently published and visible to the member when the topic is created. Missing pairs, stale versions and unpublished, private or nonexistent targets return validation errors. Unavailable targets share one generic error and reveal no content.

The topic payload in `GET /community` includes nullable `output_id`, `output_version`, `output_title`, and `output_kind`. Title and kind are canonical public metadata captured at creation; client-supplied metadata is ignored. These fields preserve the link identity and its publication label if the current title or status later changes. No output content or private research session relation is included. Opening the referenced output must still use its existing visibility checks.

`/community?about=TITLE` is presentation context only. It never resolves an output or creates an identity link; callers must explicitly submit the canonical ID and version. Existing unlinked topics and comment/owner-lock endpoints retain their contracts.

Apply `2026_11_17_000030_link_community_outputs.php` before using linked-topic writes. No frontend changes are included in this backend contract.
