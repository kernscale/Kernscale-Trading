<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\ResearchAttachments;
use App\Services\ResearchCoordinator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class ResearchAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ResearchSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->user = User::factory()->create(['membership_status' => 'approved']);
        $this->user->assignRole('researcher');
        $this->session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Private evidence']);
    }

    private function upload(string $name = 'evidence.md', string $text = 'Private evidence')
    {
        return app(ResearchAttachments::class)->upload($this->user, $this->session, UploadedFile::fake()->createWithContent($name, $text));
    }

    public function test_upload_stores_private_original_and_only_returns_receipt_metadata(): void
    {
        $file = $this->upload();
        $this->assertSame(hash('sha256', 'Private evidence'), $file->sha256);
        $this->assertSame('Private evidence', $file->text);
        $this->assertSame('Private evidence', Storage::disk('local')->get($file->storage_key));
        $this->assertSame(['id', 'name', 'media_type', 'size', 'sha256'], array_keys($file->receipt()));
        $this->assertArrayNotHasKey('text', $file->toArray());
        $this->assertArrayNotHasKey('storage_key', $file->toArray());
        $this->assertSame([$file->receipt()], app(ResearchAttachments::class)->receipts($this->user, $this->session));
    }

    public function test_text_formats_preserve_exact_content_and_invalid_documents_leave_no_storage(): void
    {
        foreach (['txt', 'md', 'csv'] as $extension) {
            $file = $this->upload('evidence.'.$extension, "ticker,value\nA,1\n");
            $this->assertSame("ticker,value\nA,1\n", $file->text);
        }
        foreach ([['a.exe', 'text'], ['a.txt', "bad\0text"], ['a.md', "\xff"], ['a.txt', '   '],
            ['a.pdf', 'Pretend PDF'], ['a.txt', str_repeat('x', ResearchAttachments::MAX_TEXT_BYTES + 1)]] as [$name, $content]) {
            try {
                $this->upload($name, $content);
                $this->fail('Invalid file accepted: '.$name);
            } catch (ValidationException) {
                $this->assertCount(3, Storage::disk('local')->allFiles());
            }
        }
    }

    public function test_snapshot_is_session_scoped_and_idempotent_runs_keep_immutable_evidence(): void
    {
        $file = $this->upload();
        $service = app(ResearchCoordinator::class);
        $run = $service->start($this->user, $this->session, 'Read attached evidence', 'attachment-run', [$file->id]);
        $this->assertSame($file->id, $run->attachments[0]['id']);
        $this->assertSame('Private evidence', $run->attachments[0]['text']);
        $this->assertSame($run->id, $service->start($this->user, $this->session, 'Read attached evidence', 'attachment-run', [$file->id])->id);
        $file->update(['text' => 'Changed after admission']);
        $this->assertSame('Private evidence', $run->fresh()->attachments[0]['text']);
        try {
            $service->start($this->user, $this->session, 'Read attached evidence', 'attachment-run', [$file->id]);
            $this->fail('Changed snapshot must conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $otherSession = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Other']);
        try {
            app(ResearchAttachments::class)->snapshot($this->user, $otherSession, [$file->id]);
            $this->fail('Other session attachment must be denied');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $owner = User::factory()->create(['membership_status' => 'approved']);
        $owner->assignRole('owner');
        try {
            app(ResearchAttachments::class)->receipts($owner, $this->session);
            $this->fail('Owner must not bypass private session ownership');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_combined_transport_limit_and_changed_ids_are_rejected(): void
    {
        $one = $this->upload('one.txt', str_repeat('x', ResearchAttachments::MAX_TEXT_BYTES / 2 + 1));
        $two = $this->upload('two.txt', str_repeat('x', ResearchAttachments::MAX_TEXT_BYTES / 2 + 1));
        try {
            app(ResearchAttachments::class)->snapshot($this->user, $this->session, [$one->id, $two->id]);
            $this->fail('Combined text must fit the transport bound');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('attachment_ids', $error->errors());
        }
        $service = app(ResearchCoordinator::class);
        $service->start($this->user, $this->session, 'Read', 'same-key', [$one->id]);
        $this->expectException(HttpException::class);
        $service->start($this->user, $this->session, 'Read', 'same-key', [$two->id]);
    }

    public function test_dispatch_transports_only_admitted_snapshot_and_not_storage_paths(): void
    {
        $file = $this->upload();
        config(['trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => 'private-token']);
        Http::fake(['bridge.test/*' => Http::response(['status' => 'running'])]);
        $service = app(ResearchCoordinator::class);
        $run = $service->start($this->user, $this->session, 'Read', 'dispatch-attachments', [$file->id]);
        $service->dispatch($run);
        Http::assertSent(fn ($request) => $request['attachments'] === [[...$file->receipt(), 'text' => 'Private evidence']]
            && ! str_contains($request->body(), $file->storage_key));
    }

    public function test_http_upload_listing_download_and_cross_session_denial(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $path = '/research/sessions/'.$this->session->id.'/attachments';
        $response = $this->actingAs($this->user)->postJson($path, ['file' => UploadedFile::fake()->createWithContent('evidence.csv', "ticker,value\nA,1")]);
        $response->assertCreated()->assertJsonStructure(['id', 'name', 'media_type', 'size', 'sha256'])->assertJsonMissingPath('text')->assertJsonMissingPath('storage_key');
        $id = $response->json('id');
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'attachments');
        $this->get($path.'/'.$id)->assertOk()->assertDownload('evidence.csv')->assertHeader('X-Content-Type-Options', 'nosniff');
        $otherSession = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Other']);
        $this->get('/research/sessions/'.$otherSession->id.'/attachments/'.$id)->assertNotFound();
        $owner = User::factory()->create(['membership_status' => 'approved']);
        $owner->assignRole('owner');
        $this->actingAs($owner)->getJson($path)->assertNotFound();
        $this->postJson($path, ['file' => UploadedFile::fake()->createWithContent('bad.txt', 'bad')])->assertNotFound();
        $this->get($path.'/'.$id)->assertNotFound();
        $this->actingAs($this->user)->postJson('/research/sessions/'.$this->session->id.'/runs', [
            'prompt' => 'Research attached evidence', 'idempotency_key' => 'http-upload-run', 'attachment_ids' => [$id],
        ])->assertRedirect();
        $this->assertSame($id, $this->session->runs()->firstOrFail()->attachments[0]['id']);
    }

    public function test_real_pdf_text_extraction_when_native_parser_is_available(): void
    {
        $binary = config('trading.pdftotext_binary', 'pdftotext');
        if ($binary === 'pdftotext') {
            $binary = (new ExecutableFinder)->find('pdftotext') ?? base_path('.orchestration/tools/pdftotext');
        }
        if (! is_executable($binary)) {
            $this->markTestSkipped('Native PDF parser is unavailable.');
        }
        config(['trading.pdftotext_binary' => $binary]);
        $attachment = $this->upload('evidence.pdf', file_get_contents(base_path('tests/Fixtures/research-evidence.pdf')));
        $this->assertStringContainsString('Attachment fixture evidence', $attachment->text);
        $this->assertSame('application/pdf', $attachment->media_type);
    }

    public function test_storage_failures_return_generic_errors_without_private_paths(): void
    {
        $file = $this->upload();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('private/path/document-content'));
        $disk->shouldReceive('exists')->andThrow(new \RuntimeException('private/path/document-content'));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $this->upload();
            $this->fail('Unavailable private storage must reject admission');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('Private attachment storage is unavailable.', $error->getMessage());
        }
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->actingAs($this->user)->getJson('/research/sessions/'.$this->session->id.'/attachments/'.$file->id)
            ->assertStatus(503)->assertJsonPath('message', 'Private attachment storage is unavailable.');
    }

    public function test_pdf_parser_success_failure_unavailability_timeout_and_overflow(): void
    {
        $directory = storage_path('framework/testing/pdf-parser');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $binary = $directory.'/parser';
        config(['trading.pdftotext_binary' => $binary]);
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        try {
            file_put_contents($binary, "#!/bin/sh\nprintf 'Extracted evidence'\n");
            chmod($binary, 0700);
            $this->assertSame('Extracted evidence', $this->upload('evidence.pdf', $pdf)->text);
            foreach (["#!/bin/sh\nexit 1\n", "#!/bin/sh\nhead -c 2097153 /dev/zero | tr '\\000' x\n", "#!/bin/sh\nexec sleep 31\n"] as $script) {
                file_put_contents($binary, $script);
                try {
                    $this->upload('evidence.pdf', $pdf);
                    $this->fail('Parser failure or overflow must reject upload');
                } catch (ValidationException) {
                    $this->assertCount(1, Storage::disk('local')->allFiles());
                }
            }
            config(['trading.pdftotext_binary' => $directory.'/missing']);
            try {
                $this->upload('evidence.pdf', $pdf);
                $this->fail('Missing parser must report unavailable');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
            }
        } finally {
            @unlink($binary);
            @rmdir($directory);
        }
    }
}
