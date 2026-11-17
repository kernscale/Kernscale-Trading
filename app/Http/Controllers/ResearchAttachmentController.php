<?php

namespace App\Http\Controllers;

use App\Models\ResearchAttachment;
use App\Models\ResearchSession;
use App\Services\ResearchAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResearchAttachmentController extends Controller
{
    public function index(Request $request, ResearchSession $session, ResearchAttachments $service): JsonResponse
    {
        return response()->json(['attachments' => $service->receipts($request->user(), $session)]);
    }

    public function store(Request $request, ResearchSession $session, ResearchAttachments $service): JsonResponse
    {
        $service->authorize($request->user(), $session);
        $request->validate(['file' => 'required|file|max:10240']);

        return response()->json($service->upload($request->user(), $session, $request->file('file'))->receipt(), 201);
    }

    public function download(Request $request, ResearchSession $session, ResearchAttachment $attachment, ResearchAttachments $service): StreamedResponse
    {
        $service->authorize($request->user(), $session);
        abort_unless($attachment->research_session_id === $session->id && $attachment->user_id === $request->user()->id, 404);
        try {
            $exists = Storage::disk('local')->exists($attachment->storage_key);
        } catch (\Throwable) {
            abort(503, 'Private attachment storage is unavailable.');
        }
        abort_unless($exists, 404);
        try {
            $stream = Storage::disk('local')->readStream($attachment->storage_key);
        } catch (\Throwable) {
            abort(503, 'Private attachment storage is unavailable.');
        }
        abort_unless(is_resource($stream), 503, 'Private attachment storage is unavailable.');

        return response()->streamDownload(static function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $attachment->name, [
            'Content-Type' => $attachment->media_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
