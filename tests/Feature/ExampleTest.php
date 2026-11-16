<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_into_the_authenticated_workspace()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('dashboard'));
    }
}
