<?php

namespace App\Http\Controllers;

use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SoilSample;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    /**
     * Display the settings dashboard with active tab and user preferences
     */
    public function index(Request $request)
    {
        $user = User::first() ?? User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.edu.ph',
            'role' => 'Agricultural Researcher',
            'organization' => 'University Research Farm',
            'region' => 'CALABARZON',
            'phone' => '+63 917 123 4567',
            'password' => Hash::make('password123'),
        ]);

        $device = IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first();
        $tab = $request->query('tab', 'profile');

        return view('settings', [
            'title' => 'Settings',
            'user' => $user,
            'device' => $device,
            'activeTab' => $tab,
        ]);
    }

    /**
     * Update user profile information
     */
    public function updateProfile(Request $request)
    {
        $user = User::firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email,' . $user->id,
            'role' => 'required|string|max:80',
            'organization' => 'nullable|string|max:120',
            'region' => 'required|string|max:80',
            'phone' => 'nullable|string|max:30',
            'avatar_url' => 'nullable|url|max:500',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar_url'] = Storage::url($path);
        }

        unset($validated['avatar']);

        $user->update($validated);

        return redirect()->route('settings', ['tab' => 'profile'])
            ->with('success', 'User profile preferences updated successfully.');
    }

    /**
     * Update account password and security credentials
     */
    public function updateSecurity(Request $request)
    {
        $user = User::firstOrFail();

        $request->validate([
            'current_password' => [
                'required',
                function ($attribute, $value, $fail) use ($user) {
                    if (!Hash::check($value, $user->password)) {
                        $fail('The current password provided is incorrect.');
                    }
                },
            ],
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return redirect()->route('settings', ['tab' => 'profile'])
            ->with('success', 'Account password updated successfully.');
    }

    /**
     * Update application preferences (units, sample metadata, crop rules, device sync)
     */
    public function updatePreferences(Request $request)
    {
        $user = User::firstOrFail();

        $tab = $request->input('tab', 'units');

        $validated = $request->validate([
            'temp_unit' => 'nullable|in:C,F',
            'moisture_unit' => 'nullable|in:% VWC,raw index',
            'ec_unit' => 'nullable|in:mS/cm,uS/cm',
            'ph_precision' => 'nullable|in:1,2',
            'default_location' => 'nullable|string|max:120',
            'default_testing_method' => 'nullable|string|max:80',
            'sample_prefix' => 'nullable|string|max:10',
            'auto_timestamp' => 'nullable|boolean',
            'recommendation_tier' => 'nullable|in:balanced,strict,permissive',
            'missing_data_policy' => 'nullable|in:flag_uncalibrated,require_all,allow_partial',
            'sync_interval_seconds' => 'nullable|integer|min:5|max:3600',
        ]);

        $preferences = $user->preferences ?? [];

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $preferences[$key] = $value;
            }
        }

        // Handle boolean toggle for auto_timestamp if not present in request body
        if ($request->has('sample_metadata_submitted')) {
            $preferences['auto_timestamp'] = $request->boolean('auto_timestamp');
        }

        $user->preferences = $preferences;
        $user->save();

        return redirect()->route('settings', ['tab' => $tab])
            ->with('success', 'Configuration preferences saved successfully.');
    }

    /**
     * Export all Soil Samples and recorded telemetry as a CSV file
     */
    public function exportSamples(): StreamedResponse
    {
        $samples = SoilSample::with(['latestReading.latestFertilizerRecommendation', 'device', 'latestAssessment'])
            ->orderBy('collected_at', 'desc')
            ->get();

        $fileName = 'soilsense_samples_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($samples) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            fputcsv($handle, [
                'Sample Code',
                'Sample Name',
                'Location',
                'Collection Date',
                'Testing Method',
                'Data Source',
                'Device ID',
                'pH',
                'Moisture',
                'Moisture Unit',
                'Temperature (C)',
                'EC',
                'EC Unit',
                'Assessment Status',
                'Operator Name',
                'Notes',
                'Nitrogen (mg/kg)',
                'Phosphorus (mg/kg)',
                'Potassium (mg/kg)',
                'NPK Source',
                'Recommended Fertilizer',
                'Fertilizer Dose (kg/ha)',
                'Rain Advisory',
            ]);

            foreach ($samples as $sample) {
                $r = $sample->latestReading;
                $fert = $r?->latestFertilizerRecommendation;
                $rainStatus = is_array($fert?->rain_advisory) ? ($fert->rain_advisory['status'] ?? '') : '';

                fputcsv($handle, [
                    $sample->sample_code,
                    $sample->name,
                    $sample->location,
                    $sample->collected_at ? $sample->collected_at->format('Y-m-d H:i:s') : '',
                    $sample->testing_method,
                    $sample->source_type,
                    $sample->device?->device_uid ?? 'N/A',
                    $r && $r->ph !== null ? $r->ph : '',
                    $r && $r->moisture !== null ? $r->moisture : '',
                    $r?->moisture_unit ?? '',
                    $r && $r->temperature !== null ? $r->temperature : '',
                    $r && $r->ec !== null ? $r->ec : '',
                    $r?->ec_unit ?? '',
                    $sample->assessment_status,
                    $sample->operator_name,
                    $sample->notes ?? '',
                    $r && $r->nitrogen !== null ? $r->nitrogen : '',
                    $r && $r->phosphorus !== null ? $r->phosphorus : '',
                    $r && $r->potassium !== null ? $r->potassium : '',
                    $r?->npk_source ?? '',
                    $fert?->predicted_fertilizer ?? '',
                    $fert && $fert->dose_kg_ha !== null ? $fert->dose_kg_ha : '',
                    $rainStatus,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export Crop Profiles and documented agronomic thresholds as a CSV file
     */
    public function exportCrops(): StreamedResponse
    {
        $crops = CropProfile::with('requirements')->orderBy('name')->get();
        $fileName = 'soilsense_crops_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($crops) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            fputcsv($handle, [
                'Crop Name',
                'Scientific Name',
                'Category',
                'Min pH',
                'Max pH',
                'Optimal pH Min',
                'Optimal pH Max',
                'Min Moisture (%)',
                'Max Moisture (%)',
                'Min Temp (C)',
                'Max Temp (C)',
                'Max EC (mS/cm)',
                'Reference Citation',
                'Reference URL',
                'Applicability Notes',
                'Limitations Notes',
            ]);

            foreach ($crops as $crop) {
                $req = $crop->requirements;
                fputcsv($handle, [
                    $crop->name,
                    $crop->scientific_name ?? '',
                    $crop->category ?? '',
                    $req?->min_ph ?? '',
                    $req?->max_ph ?? '',
                    $req?->optimal_ph_min ?? '',
                    $req?->optimal_ph_max ?? '',
                    $req?->min_moisture ?? '',
                    $req?->max_moisture ?? '',
                    $req?->min_temperature ?? '',
                    $req?->max_temperature ?? '',
                    $req?->max_ec ?? '',
                    $req?->source_citation ?? '',
                    $req?->source_url ?? '',
                    $req?->applicability_notes ?? '',
                    $req?->limitations_notes ?? '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
