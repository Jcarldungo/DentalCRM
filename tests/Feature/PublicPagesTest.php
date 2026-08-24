<?php
// tests/Feature/PublicPagesTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_reachable_by_a_guest(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
