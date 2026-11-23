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

    public function test_device_active_sample_get_and_set(): void
    {
        $getResponse = $this->getJson('/api/v1/device/active-sample');
        $getResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'active_sample' => ['sample_code', 'sample_id', 'name', 'base_ph', 'quality'],
            ]);

        $setResponse = $this->postJson('/api/v1/device/active-sample', [
            'sample_code' => 'S2',
        ]);
        $setResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('active_sample.sample_code', 'S2')
            ->assertJsonPath('active_sample.quality', 'OPTIMAL');

        $invalidResponse = $this->postJson('/api/v1/device/active-sample', [
            'sample_code' => 'INVALID',
        ]);
        $invalidResponse->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
