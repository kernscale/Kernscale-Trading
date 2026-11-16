<?php

namespace Tests\Feature;

use App\Models\CommunityTopic;
use App\Models\ResearchOutput;
use App\Models\User;
use App\Services\LegacyTradingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyTradingImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $owner;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = base_path('.orchestration/cache/legacy-import-tests-'.bin2hex(random_bytes(6)));
        File::makeDirectory($this->directory, 0700, true);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
        $this->author = User::factory()->create();
        $this->author->assignRole('member');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function manifest(): array
    {
        return ['schema_version' => 1, 'source_id' => 'legacy-community', 'identities' => ['u-1' => $this->author->id],
            'topics' => [['id' => 't-1', 'user_id' => 'u-1', 'title' => 'Old topic', 'body' => 'Original topic', 'created_at' => '2026-11-01T10:00:00Z', 'visibility' => 'all_members']],
            'comments' => [['id' => 'p-2', 'topic_id' => 't-1', 'user_id' => 'u-1', 'body' => 'Original reply', 'created_at' => '2026-11-01T10:01:00Z', 'visibility' => 'all_members']],
            'outputs' => [['id' => 'r-1', 'user_id' => 'u-1', 'title' => 'Old research', 'content' => 'Original research', 'created_at' => '2026-11-01T11:00:00Z', 'visibility' => 'all_members']], 'archives' => []];
    }

    private function runImport(array $manifest): array
    {
        $path = $this->directory.'/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return app(LegacyTradingImport::class)->import($path, hash_file('sha256', $path), $this->owner->id);
    }

    public function test_repeated_import_has_no_duplicates_and_preserves_later_publication(): void
    {
        $manifest = $this->manifest();
        $this->assertSame(3, $this->runImport($manifest)['created']);
        $output = ResearchOutput::firstOrFail();
        $this->assertSame('in_review', $output->status);
        $output->update(['status' => 'published', 'content' => 'Reviewed content', 'published_at' => now()]);
        $this->assertSame(3, $this->runImport($manifest)['replayed']);
        $this->assertDatabaseCount('community_topics', 1);
        $this->assertDatabaseCount('community_comments', 1);
        $this->assertDatabaseCount('research_outputs', 1);
        $this->assertSame('published', $output->fresh()->status);
        $this->assertSame('Reviewed content', $output->fresh()->content);
    }

    public function test_changed_record_conflicts_and_rolls_back_without_overwriting_content(): void
    {
        $manifest = $this->manifest();
        $this->runImport($manifest);
        $manifest['topics'][0]['body'] = 'Changed source';
        try {
            $this->runImport($manifest);
            $this->fail('Changed legacy record should conflict');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('conflict', $error->getMessage());
        }
        $this->assertSame('Original topic', CommunityTopic::firstOrFail()->body);
    }

    public function test_private_content_and_uploads_are_rejected_before_any_import(): void
    {
        foreach (['visibility' => 'private', 'archetype' => 'private_message', 'read_restricted' => true, 'uploads' => ['upload://private.png']] as $key => $value) {
            $manifest = $this->manifest();
            $manifest['topics'][0][$key] = $value;
            try {
                $this->runImport($manifest);
                $this->fail('Unsafe legacy content should be rejected');
            } catch (\InvalidArgumentException|ValidationException $error) {
                $this->assertDatabaseCount('community_topics', 0);
            }
        }
    }

    public function test_identity_mapping_cannot_grant_roles_or_use_unknown_users(): void
    {
        $manifest = $this->manifest();
        $manifest['identities']['u-1'] = ['user_id' => $this->author->id, 'role' => 'owner'];
        try {
            $this->runImport($manifest);
            $this->fail('Role mapping must be rejected');
        } catch (\InvalidArgumentException|ValidationException $error) {
            $this->assertFalse($this->author->fresh()->hasRole('owner'));
            $this->assertDatabaseCount('community_topics', 0);
        }
    }

    public function test_only_an_approved_existing_owner_can_import_and_checksum_must_match(): void
    {
        $path = $this->directory.'/manifest.json';
        file_put_contents($path, json_encode($this->manifest(), JSON_THROW_ON_ERROR));
        $this->artisan('trading:import-legacy', ['manifest' => $path, '--sha256' => str_repeat('0', 64), '--owner' => $this->owner->id])->assertFailed();
        $this->artisan('trading:import-legacy', ['manifest' => $path, '--sha256' => hash_file('sha256', $path), '--owner' => $this->author->id])->assertFailed();
        $this->assertDatabaseCount('community_topics', 0);
    }

    public function test_ghostfolio_export_is_archived_once_without_creating_a_second_ledger(): void
    {
        $manifest = $this->manifest();
        $manifest['archives'] = [['id' => 'portfolio-export-1', 'kind' => 'ghostfolio', 'payload' => [
            'accounts' => [['id' => 'paper-wallet']], 'activities' => [['id' => 'fill-1', 'type' => 'BUY', 'quantity' => 2]],
            'assetProfiles' => [], 'meta' => ['date' => '2026-11-01', 'version' => 'legacy'],
            'user' => ['settings' => ['currency' => 'USD']],
        ]]];
        $this->assertSame(4, $this->runImport($manifest)['created']);
        $this->assertSame(4, $this->runImport($manifest)['replayed']);
        $this->assertDatabaseCount('integration_snapshots', 0);
        $this->assertDatabaseCount('users', 2);
        $archive = DB::table('legacy_import_receipts')->where('record_type', 'archives')->first();
        $this->assertSame($manifest['archives'][0]['payload'], json_decode($archive->archive_payload, true));
        $this->assertNull($archive->target_id);
        $this->assertFalse($this->author->fresh()->hasRole('owner'));
    }

    public function test_remapping_an_imported_author_conflicts_instead_of_reassigning_content(): void
    {
        $manifest = $this->manifest();
        $this->runImport($manifest);
        $manifest['identities']['u-1'] = $this->owner->id;
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->runImport($manifest);
        } finally {
            $this->assertSame($this->author->id, CommunityTopic::firstOrFail()->user_id);
        }
    }

    public function test_unknown_identity_and_unmapped_parent_roll_back_all_content(): void
    {
        foreach (['unknown-user', 'unknown-topic'] as $scenario) {
            $manifest = $this->manifest();
            if ($scenario === 'unknown-user') {
                $manifest['identities']['u-1'] = 999999;
            } else {
                $manifest['comments'][0]['topic_id'] = 'missing-topic';
            }
            try {
                $this->runImport($manifest);
                $this->fail('Unmapped legacy references must be rejected');
            } catch (\InvalidArgumentException $error) {
                $this->assertDatabaseCount('community_topics', 0);
                $this->assertDatabaseCount('legacy_import_receipts', 0);
            }
        }
    }
}
