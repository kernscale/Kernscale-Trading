<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioToolsController extends Controller
{
    public function add(Request $request): RedirectResponse
    {
        $request->merge(['symbol' => strtoupper(trim((string) $request->input('symbol')))]);
        $data = $request->validate(['symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9.=-]*$/']]);
        DB::table('watchlist_items')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'symbol' => $data['symbol'], 'created_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function remove(Request $request, string $id): RedirectResponse
    {
        abort_unless(DB::table('watchlist_items')->where('user_id', $request->user()->id)->where('id', $id)->delete() === 1, 404);

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['format' => 'sometimes|in:json,csv']);
        $snapshot = IntegrationSnapshot::where('kind', 'portfolio')->first();
        abort_unless($snapshot !== null, 503, 'No verified account observation is available.');
        if (($data['format'] ?? 'json') === 'json') {
            return response()->streamDownload(fn () => print (json_encode($snapshot->payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)), 'kernscale-paper-snapshot.json', ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($snapshot) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }
            $activities = $snapshot->payload['activities'] ?? [];
            $rows = is_array($activities) ? array_filter($activities, 'is_array') : [];
            $headers = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $key) {
                    $headers[$key] = $key;
                }
            }
            $headers = array_values($headers);
            fputcsv($out, $headers, ',', '"', '');
            foreach ($rows as $row) {
                $values = array_map(function ($key) use ($row) {
                    $value = $row[$key] ?? '';
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_THROW_ON_ERROR);
                    } if (is_string($value) && preg_match('/^[=+@\\-\\t\\r]/', $value)) {
                        $value = "'".$value;
                    }

                    return $value;
                }, $headers);
                fputcsv($out, $values, ',', '"', '');
            } fclose($out);
        }, 'kernscale-paper-activities.csv', ['Content-Type' => 'text/csv']);
    }
}
