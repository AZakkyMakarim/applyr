<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_home_renders_the_dashboard_layout_without_logging_in(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeInOrder(['Applications', 'SearchProfiles', 'MasterProfile', 'Adapters']);
    }
}
