# Private research attachments

All routes require the standard approved membership and security enrollment middleware. The researcher must own the session; an owner role does not bypass privacy.

| Method | Route | Result |
| --- | --- | --- |
| POST | `/research/sessions/{session}/attachments` | Multipart `file`; 201 upload receipt |
| GET | `/research/sessions/{session}/attachments` | `{attachments: [receipt, ...]}` |
| GET | `/research/sessions/{session}/attachments/{attachment}` | Private original download; `Content-Disposition: attachment`, `nosniff`, private/no-store |

A receipt contains `id` (UUID), `name`, `media_type`, `size` (original bytes), and `sha256` (original bytes). Storage keys and admitted text are never included in a receipt. Downloads resolve the UUID within the authorized session and never accept a path or URL.

Run creation accepts optional `attachment_ids: UUID[]`. IDs must be distinct and belong to the same private session. The server captures receipts and complete admitted text into the run before dispatch. Reusing an idempotency key with different attachments or changed admitted content returns 409. Existing runs keep their original snapshot. No browser-provided text, filename, digest, path, or URL can replace this snapshot.

Supported input is PDF, UTF-8 TXT, Markdown (`.md`), and CSV. Text and CSV contents remain exact, including CSV headers and delimiters. Binary/image passthrough and OCR are unavailable. Invalid UTF-8, NUL bytes, empty documents, password-required/damaged PDFs that the parser cannot extract, and PDFs without extractable text are rejected. Readable passwordless PDFs may be admitted by Poppler even when encrypted; the local macOS PDFKit development adapter rejects encrypted PDFs. PDFs are checked by signature and MIME, then extracted locally using `pdftotext -layout -enc UTF-8 -nopgbrk <temporary-server-file> -`. No shell interpolation or external parser service is used.

Technical admission bounds protect parsing and transport: original file at most 10 MiB, decoded UTF-8 text at most 2 MiB per file and 2 MiB combined per run, parser timeout 30 seconds. Oversized extraction stops the process and rejects the upload; content is never silently truncated. These are transport bounds, not research usage quotas. Originals are saved to Laravel's private local disk only after successful admission. Parser stderr and document content are not logged by the attachment service; storage admission failures return a generic error.

`trading.pdftotext_binary` selects the trusted executable, defaulting to `pdftotext`. Linux runtime requires `poppler-utils`. Missing/unexecutable extraction returns 503. Invalid documents return validation errors. There is no fallback that accepts a PDF without successful extraction.

The private bridge receives a typed `attachments` array in the immutable run request. It validates receipt types, unique UUIDs, and text bounds. `attachment_list({})` returns metadata; `attachment_read({id})` reads only the admitted in-memory snapshot and emits an `attachment_reference` receipt. Tools cannot access filesystem paths or arbitrary URLs. The worker treats attachment text as untrusted evidence and references its actual UUID, filename, and SHA-256, without fabricating public URLs. Empty attachment arrays are omitted before bridge request hashing so previously admitted no-attachment runs remain replayable.
