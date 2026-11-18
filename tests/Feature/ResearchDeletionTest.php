<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\BoardCard;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResearchDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        Storage::fake('local');
        Role::findOrCreate('researcher');
        Role::findOrCreate('owner');
        $this->user = User::factory()->create();
        $this->user->assignRole('researcher');
    }

    public function test_empty_session_is_deleted_with_its_cards_and_private_files(): void
    {
        $session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Draft']);
        BoardCard::create(['research_session_id' => $session->id, 'title' => 'Card']);
        $this->actingAs($this->user)->post("/research/sessions/{$session->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('notes.md', "# Notes\n")])->assertCreated();
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->actingAs($this->user)->delete("/research/sessions/{$session->id}")->assertRedirect('/research');

        $this->assertModelMissing($session);
        $this->assertDatabaseCount('board_cards', 0);
        $this->assertDatabaseCount('research_attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_sessions_with_research_history_and_foreign_sessions_are_kept(): void
    {
        $session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'History']);
        ResearchRun::create(['user_id' => $this->user->id, 'research_session_id' => $session->id, 'idempotency_key' => 'k', 'prompt' => 'Research', 'status' => 'completed']);
        $this->actingAs($this->user)->delete("/research/sessions/{$session->id}")->assertStatus(409);

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $foreign = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Private']);
        $this->actingAs($owner)->delete("/research/sessions/{$foreign->id}")->assertNotFound();
        $this->assertModelExists($session);
        $this->assertModelExists($foreign);
    }

    public function test_card_deletion_requires_the_current_version(): void
    {
        $session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Board']);
        $card = BoardCard::create(['research_session_id' => $session->id, 'title' => 'Card'])->fresh();

        $this->actingAs($this->user)->delete("/research/sessions/{$session->id}/board/{$card->id}", ['version' => $card->version + 1])->assertStatus(409);
        $this->actingAs($this->user)->delete("/research/sessions/{$session->id}/board/{$card->id}", ['version' => $card->version])->assertRedirect();
        $this->assertModelMissing($card);
    }
}
