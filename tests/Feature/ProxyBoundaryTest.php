<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyBoundaryTest extends TestCase
{
    public function test_only_admitted_proxy_addresses_can_supply_the_https_origin(): void
    {
        Route::get('/_test/proxy', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeader('X-Forwarded-Proto', 'https')->get('http://localhost/_test/proxy')->assertJsonPath('secure', true);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeader('X-Forwarded-Proto', 'https')->get('http://localhost/_test/proxy')->assertJsonPath('secure', false);
    }
}
