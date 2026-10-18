<?php

namespace Tests\Feature;

use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SoilSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        User::updateOrCreate(
            ['email' => 'juan@example.edu.ph'],
            [
                'name' => 'Juan Dela Cruz',
                'role' => 'Agricultural Researcher',
                'organization' => 'University Research Farm',
                'region' => 'CALABARZON',
                'phone' => '+63 917 123 4567',
                'password' => Hash::make('password123'),
            ]
        );

        IotDevice::updateOrCreate(
            ['device_uid' => 'ESP32-01'],
            [
                'name' => 'ESP32 Soil Node 01',
                'status' => 'online',
                'api_token_hash' => hash('sha256', 'test-token'),
            ]
        );
    }

    public function test_settings_page_renders_with_user_profile(): void
    {
        $response = $this->get('/settings');

        $response->assertStatus(200);
        $response->assertSee('User profile');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Agricultural Researcher');
        $response->assertSee('University Research Farm');
    }

    public function test_user_can_update_profile_successfully(): void
    {
        $response = $this->post('/settings/profile', [
            'name' => 'Dr. Maria Santos',
            'role' => 'Agronomist',
            'organization' => 'National Agricultural Institute',
            'region' => 'Central Luzon',
            'email' => 'maria.santos@agri.gov.ph',
            'phone' => '+63 918 765 4321',
            'avatar_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2',
        ]);

        $response->assertRedirect('/settings?tab=profile');
        $response->assertSessionHas('success', 'User profile preferences updated successfully.');

        $this->assertDatabaseHas('users', [
            'name' => 'Dr. Maria Santos',
            'role' => 'Agronomist',
            'organization' => 'National Agricultural Institute',
            'region' => 'Central Luzon',
            'email' => 'maria.santos@agri.gov.ph',
            'phone' => '+63 918 765 4321',
        ]);

        // Verify initials accessor
        $user = User::first();
        $this->assertEquals('DS', $user->initials);
    }

    public function test_profile_update_validation_rejects_empty_required_fields(): void
    {
        $response = $this->post('/settings/profile', [
            'name' => '',
            'role' => '',
            'region' => '',
            'email' => 'invalid-email',
        ]);

        $response->assertSessionHasErrors(['name', 'role', 'region', 'email']);
    }

    public function test_user_can_update_password_with_valid_current_password(): void
    {
        $response = $this->post('/settings/security', [
            'current_password' => 'password123',
            'new_password' => 'newSecretPass2026',
            'new_password_confirmation' => 'newSecretPass2026',
        ]);

        $response->assertRedirect('/settings?tab=profile');
        $response->assertSessionHas('success', 'Account password updated successfully.');

        $user = User::first();
        $this->assertTrue(Hash::check('newSecretPass2026', $user->password));
    }

    public function test_password_update_rejects_incorrect_current_password(): void
    {
        $response = $this->post('/settings/security', [
            'current_password' => 'wrongPassword',
            'new_password' => 'newSecretPass2026',
            'new_password_confirmation' => 'newSecretPass2026',
        ]);

        $response->assertSessionHasErrors(['current_password']);
    }

    public function test_user_can_update_measurement_and_metadata_preferences(): void
    {
        $response = $this->post('/settings/preferences', [
            'tab' => 'units',
            'temp_unit' => 'F',
            'moisture_unit' => 'raw index',
            'ec_unit' => 'uS/cm',
            'ph_precision' => '2',
        ]);

        $response->assertRedirect('/settings?tab=units');
        $response->assertSessionHas('success', 'Configuration preferences saved successfully.');

        $user = User::first();
        $this->assertEquals('F', $user->getPreference('temp_unit'));
        $this->assertEquals('raw index', $user->getPreference('moisture_unit'));
        $this->assertEquals('uS/cm', $user->getPreference('ec_unit'));
        $this->assertEquals('2', $user->getPreference('ph_precision'));
    }

    public function test_export_samples_csv_streams_valid_content(): void
    {
        $sample = SoilSample::create([
            'sample_code' => 'SS-TEST-001',
            'name' => 'Export Sample Test',
            'location' => 'Laguna Test Plot',
            'collected_at' => now(),
            'testing_method' => 'Sensor Probe + DA Lab',
            'source_type' => 'live',
            'assessment_status' => 'preliminary',
        ]);

        $response = $this->get('/settings/export/samples');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('Sample Code', $content);
        $this->assertStringContainsString('Sample Name', $content);
        $this->assertStringContainsString('SS-TEST-001', $content);
        $this->assertStringContainsString('Export Sample Test', $content);
    }

    public function test_export_crops_csv_streams_valid_content(): void
    {
        $response = $this->get('/settings/export/crops');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('Crop Name', $content);
        $this->assertStringContainsString('Scientific Name', $content);
        $this->assertStringContainsString('Tomato', $content);
        $this->assertStringContainsString('Solanum lycopersicum', $content);
    }
}
