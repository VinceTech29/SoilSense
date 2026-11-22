<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_landing_page_renders_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('SoilSense');
        $response->assertSee('Know Your Soil');
    }

    public function test_dashboard_renders_successfully(): void
    {
        $response = $this->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('Soil Overview');
        $response->assertSee('ESP32-01');
    }

    public function test_analysis_page_renders_successfully(): void
    {
        $response = $this->get('/analysis');
        $response->assertStatus(200);
        $response->assertSee('Soil Analysis');
        $response->assertSee('Live IoT test');
    }

    public function test_crops_catalog_renders_successfully(): void
    {
        $response = $this->get('/crops');
        $response->assertStatus(200);
        $response->assertSee('Crop Recommendations');
        $response->assertSee('Tomato');
    }

    public function test_crop_detail_renders_successfully(): void
    {
        $response = $this->get('/crops/tomato');
        $response->assertStatus(200);
        $response->assertSee('Tomato');
        $response->assertSee('Solanum lycopersicum');
    }

    public function test_report_renders_successfully(): void
    {
        $response = $this->get('/report');
        $response->assertStatus(200);
        $response->assertSee('Soil assessment report');
        $response->assertSee('SS-2025-018');
    }

    public function test_history_renders_successfully(): void
    {
        $response = $this->get('/history');
        $response->assertStatus(200);
        $response->assertSee('Sample History');
    }

    public function test_reports_overview_renders_successfully(): void
    {
        $response = $this->get('/reports');
        $response->assertStatus(200);
        $response->assertSee('Reports & Exports');
    }

    public function test_device_monitoring_renders_successfully(): void
    {
        $response = $this->get('/device');
        $response->assertStatus(200);
        $response->assertSee('Device Monitoring');
        $response->assertSee('ESP32-01');
    }

    public function test_settings_renders_successfully(): void
    {
        $response = $this->get('/settings');
        $response->assertStatus(200);
        $response->assertSee('Settings');
        $response->assertSee('User profile');
    }

    public function test_ui_revamp_interactive_and_accessibility_elements_render_cleanly(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('id="publicNavDrawer"', false)
            ->assertSee('16');

        $this->get('/dashboard')
            ->assertStatus(200)
            ->assertSee('id="dashboardPhChart"', false)
            ->assertSee('id="globalToastContainer"', false);

        $this->get('/analysis')
            ->assertStatus(200)
            ->assertSee('Step 3 of 3')
            ->assertSee('id="manualMoistureUnit"', false)
            ->assertSee('role="tablist"', false);

        $this->get('/crops')
            ->assertStatus(200)
            ->assertSee('id="cropCompareModal"', false)
            ->assertSee('id="scoringMethodology"', false)
            ->assertDontSee('alert(');

        $this->get('/history')
            ->assertStatus(200)
            ->assertSee('id="sampleCompareModal"', false)
            ->assertDontSee('alert(');

        $this->get('/reports?days=30')
            ->assertStatus(200)
            ->assertSee('Export CSV')
            ->assertDontSee('alert(');

        $this->get('/report/SS-2025-016')
            ->assertStatus(200)
            ->assertSee('Print / Save PDF')
            ->assertDontSee('alert(');
    }
}
