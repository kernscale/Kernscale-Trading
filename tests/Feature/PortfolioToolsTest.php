<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\IntegrationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortfolioToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchlists_are_private_and_symbols_cannot_be_arbitrary_urls(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->post('/portfolio/watchlists', ['symbol' => 'BTC-USD'])->assertRedirect();
        $this->post('/portfolio/watchlists', ['symbol' => 'BTC-USD'])->assertRedirect();
        $this->assertDatabaseCount('watchlist_items', 1);
        $id = DB::table('watchlist_items')->value('id');
        $this->actingAs($other)->delete('/portfolio/watchlists/'.$id)->assertNotFound();
        $this->actingAs($user)->post('/portfolio/watchlists', ['symbol' => 'http://127.0.0.1/private'])->assertSessionHasErrors('symbol');
        $this->delete('/portfolio/watchlists/'.$id)->assertRedirect();
        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_export_does_not_fabricate_a_missing_account(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->actingAs(User::factory()->create())->get('/portfolio/export?format=json')->assertStatus(503);
    }

    public function test_csv_export_preserves_values_and_neutralizes_spreadsheet_formulas(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $payload = ['source' => 'native-paper-ledger', 'activities' => [['symbol' => '=2+3', 'quantity' => 2, 'price' => -3.5]]];
        IntegrationSnapshot::create(['kind' => 'portfolio', 'observed_at' => now(), 'payload' => $payload]);
        $response = $this->actingAs(User::factory()->create())->get('/portfolio/export?format=csv')->assertDownload('kernscale-paper-activities.csv');
        $lines = explode("\n", trim($response->streamedContent()));
        $this->assertSame(["'=2+3", '2', '-3.5'], str_getcsv($lines[1], ',', '"', ''));
        $this->assertSame($payload, IntegrationSnapshot::first()->payload);
    }
}
