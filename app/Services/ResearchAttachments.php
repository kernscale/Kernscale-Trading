<?php

namespace App\Services;

use App\Models\ResearchAttachment;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class ResearchAttachments
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_TEXT_BYTES = 2 * 1024 * 1024;

    public function authorize(User $user, ResearchSession $session): void
    {
        app(ResearchCoordinator::class)->authorize($user, $session);
    }

    /** @return array<int, array<string, mixed>> */
    public function receipts(User $user, ResearchSession $session): array
    {
        $this->authorize($user, $session);

        return ResearchAttachment::where('research_session_id', $session->id)->where('user_id', $user->id)->oldest()->get()->map->receipt()->all();
    }

    public function upload(User $user, ResearchSession $session, UploadedFile $file): ResearchAttachment
    {
        $this->authorize($user, $session);
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_FILE_BYTES) {
            $this->invalid('Choose a nonempty file no larger than 10 MiB.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $types = ['pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv'];
        if (! isset($types[$extension])) {
            $this->invalid('Supported files are PDF, TXT, Markdown and CSV.');
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            $this->invalid('The file could not be read.');
        }
        if ($extension === 'pdf') {
            if (! str_starts_with($content, '%PDF-') || $file->getMimeType() !== 'application/pdf') {
                $this->invalid('This file is not a valid PDF.');
            }
            $text = $this->pdfText($file->getPathname());
        } else {
            if (! in_array($file->getMimeType(), ['text/plain', 'text/csv', 'text/markdown', 'text/x-markdown', 'application/csv', 'application/octet-stream'], true)) {
                $this->invalid('This file is not an admitted text document.');
            }
            $text = $content;
        }
        $this->validateText($text);
        $name = str_replace(['\\', '/'], '_', $file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if ($name === '' || mb_strlen($name) > 255) {
            $this->invalid('Choose a filename of at most 255 characters.');
        }
        $key = 'research-attachments/'.$session->id.'/'.Str::uuid();
        try {
            $stored = Storage::disk('local')->put($key, $content, ['visibility' => 'private']);
        } catch (\Throwable) {
            abort(503, 'Private attachment storage is unavailable.');
        }
        abort_unless($stored === true, 503, 'Private attachment storage is unavailable.');
        try {
            return ResearchAttachment::create([
                'research_session_id' => $session->id, 'user_id' => $user->id,
                'name' => $name, 'media_type' => $types[$extension], 'size' => strlen($content),
                'sha256' => hash('sha256', $content), 'storage_key' => $key, 'text' => $text,
            ]);
        } catch (\Throwable) {
            try {
                Storage::disk('local')->delete($key);
            } catch (\Throwable) {
                // Preserve the generic failure if private storage is also unavailable.
            }
            abort(503, 'Private attachment storage is unavailable.');
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function snapshot(User $user, ResearchSession $session, array $ids): array
    {
        $this->authorize($user, $session);
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['attachment_ids' => 'Attachment IDs must be distinct.']);
        }
        sort($ids);
        $attachments = ResearchAttachment::where('research_session_id', $session->id)->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($attachments->count() === count($ids), 404);
        $snapshot = [];
        $bytes = 0;
        foreach ($ids as $id) {
            $attachment = $attachments[$id];
            $bytes += strlen($attachment->text);
            if ($bytes > self::MAX_TEXT_BYTES) {
                throw ValidationException::withMessages(['attachment_ids' => 'Combined extracted attachment text exceeds the 2 MiB transport limit.']);
            }
            $snapshot[] = [...$attachment->receipt(), 'text' => $attachment->text];
        }

        return $snapshot;
    }

    private function pdfText(string $path): string
    {
        $process = new Process([(string) config('trading.pdftotext_binary', 'pdftotext'), '-layout', '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
        $process->setTimeout(30);
        $text = '';
        $exceeded = false;
        try {
            $process->run(function (string $type, string $chunk) use (&$text, &$exceeded, $process) {
                if ($type === Process::OUT) {
                    if (strlen($text) + strlen($chunk) > self::MAX_TEXT_BYTES) {
                        $exceeded = true;
                        $process->stop(0);
                    } else {
                        $text .= $chunk;
                    }
                }
                // Do not retain parser stderr, which may contain document content.
                $process->clearOutput();
                $process->clearErrorOutput();
            });
        } catch (ProcessTimedOutException) {
            $this->invalid('PDF extraction timed out.');
        } catch (RuntimeException) {
            abort(503, 'PDF extraction is unavailable.');
        }
        if ($exceeded) {
            $this->invalid('Extracted text exceeds the 2 MiB transport limit.');
        }
        if (in_array($process->getExitCode(), [126, 127], true)) {
            abort(503, 'PDF extraction is unavailable.');
        }
        if (! $process->isSuccessful()) {
            $this->invalid('PDF text could not be extracted. Password-protected or damaged PDFs may be unsupported.');
        }

        return $text;
    }

    private function validateText(string $text): void
    {
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            $this->invalid('Extracted text exceeds the 2 MiB transport limit.');
        }
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || trim($text) === '') {
            $this->invalid('The document must contain readable UTF-8 text. Image-only PDFs require OCR before upload.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
