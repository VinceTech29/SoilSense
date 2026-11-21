<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ML Fertilizer Classification Service
    |--------------------------------------------------------------------------
    */
    'ml_service' => [
        'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:8001'),
        'api_key' => env('ML_SERVICE_API_KEY', ''),
        'timeout_seconds' => (int) env('ML_SERVICE_TIMEOUT', 5),
        'retry_times' => (int) env('ML_SERVICE_RETRIES', 1),
        'low_confidence_threshold' => (float) env('ML_LOW_CONFIDENCE_THRESHOLD', 0.55),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rain Guard Weather Advisory (Open-Meteo)
    |--------------------------------------------------------------------------
    */
    'rain_guard' => [
        'open_meteo_url' => env('OPEN_METEO_URL', 'https://api.open-meteo.com/v1/forecast'),
        'timeout_seconds' => (int) env('OPEN_METEO_TIMEOUT', 5),
        'cache_ttl_seconds' => (int) env('RAIN_GUARD_CACHE_TTL', 3600),
        'rain_threshold_mm' => (float) env('RAIN_GUARD_THRESHOLD_MM', 10.0),
        'rain_probability_threshold' => (int) env('RAIN_GUARD_PROBABILITY_THRESHOLD', 60),
        'default_latitude' => (float) env('DEFAULT_FIELD_LATITUDE', 14.1699),
        'default_longitude' => (float) env('DEFAULT_FIELD_LONGITUDE', 121.2441),
        'default_location_label' => 'Laguna, PH (Default)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Split Application Rules
    |--------------------------------------------------------------------------
    */
    'max_single_application_kg_ha' => (float) env('FERTILIZER_MAX_SINGLE_APP_KG_HA', 150.0),
    'split_intervals_days' => [0, 21, 42],

    /*
    |--------------------------------------------------------------------------
    | Fertilizer Grade Specifications (All 7 ML Output Classes)
    |--------------------------------------------------------------------------
    */
    'grades' => [
        'Urea' => [
            'label' => 'Urea (46-0-0)',
            'primary_nutrient' => 'n',
            'fractions' => [
                'n' => 0.46,
                'p2o5' => 0.0,
                'k2o' => 0.0,
            ],
            'base_rate_kg_ha' => null,
        ],
        'DAP' => [
            'label' => 'Diammonium Phosphate (18-46-0)',
            'primary_nutrient' => 'p2o5',
            'fractions' => [
                'n' => 0.18,
                'p2o5' => 0.46,
                'k2o' => 0.0,
            ],
            'base_rate_kg_ha' => null,
        ],
        'MOP' => [
            'label' => 'Muriate of Potash (0-0-60)',
            'primary_nutrient' => 'k2o',
            'fractions' => [
                'n' => 0.0,
                'p2o5' => 0.0,
                'k2o' => 0.60,
            ],
            'base_rate_kg_ha' => null,
        ],
        'SSP' => [
            'label' => 'Single Superphosphate (0-16-0)',
            'primary_nutrient' => 'p2o5',
            'fractions' => [
                'n' => 0.0,
                'p2o5' => 0.16,
                'k2o' => 0.0,
            ],
            'base_rate_kg_ha' => null,
        ],
        'NPK' => [
            'label' => 'Complete NPK (14-14-14)',
            'primary_nutrient' => 'n',
            'fractions' => [
                'n' => 0.14,
                'p2o5' => 0.14,
                'k2o' => 0.14,
            ],
            'base_rate_kg_ha' => 150.0,
        ],
        'Compost' => [
            'label' => 'Organic Compost Amendment',
            'primary_nutrient' => 'amendment',
            'fractions' => [
                'n' => 0.015,
                'p2o5' => 0.01,
                'k2o' => 0.015,
            ],
            'base_rate_kg_ha' => 1000.0,
        ],
        'Zinc Sulphate' => [
            'label' => 'Zinc Sulphate (ZnSO4)',
            'primary_nutrient' => 'micronutrient',
            'fractions' => [
                'zn' => 0.21,
            ],
            'base_rate_kg_ha' => 20.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Crop Nutrient Demand Targets (kg/ha) — All 16 Seeded SoilSense Crops + Default
    |--------------------------------------------------------------------------
    */
    'crop_targets' => [
        'Tomato' => ['n' => 120.0, 'p' => 80.0, 'k' => 120.0],
        'Pechay' => ['n' => 90.0, 'p' => 45.0, 'k' => 45.0],
        'Eggplant' => ['n' => 120.0, 'p' => 60.0, 'k' => 100.0],
        'Okra' => ['n' => 80.0, 'p' => 50.0, 'k' => 60.0],
        'Cucumber' => ['n' => 100.0, 'p' => 60.0, 'k' => 100.0],
        'Lettuce' => ['n' => 90.0, 'p' => 50.0, 'k' => 60.0],
        'Hot Pepper' => ['n' => 100.0, 'p' => 60.0, 'k' => 90.0],
        'String Beans' => ['n' => 30.0, 'p' => 60.0, 'k' => 60.0],
        'Bitter Gourd' => ['n' => 100.0, 'p' => 60.0, 'k' => 90.0],
        'Squash' => ['n' => 90.0, 'p' => 60.0, 'k' => 80.0],
        'Potato' => ['n' => 140.0, 'p' => 90.0, 'k' => 150.0],
        'Sweet Corn' => ['n' => 120.0, 'p' => 60.0, 'k' => 60.0],
        'Cabbage' => ['n' => 140.0, 'p' => 70.0, 'k' => 100.0],
        'Bell Pepper' => ['n' => 110.0, 'p' => 65.0, 'k' => 100.0],
        'Carrot' => ['n' => 80.0, 'p' => 60.0, 'k' => 100.0],
        'Radish' => ['n' => 70.0, 'p' => 45.0, 'k' => 60.0],
        'default' => ['n' => 100.0, 'p' => 60.0, 'k' => 80.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Crop Name Aliases for ML Model Input Mapping
    |--------------------------------------------------------------------------
    */
    'crop_model_aliases' => [
        'Sweet Corn' => 'Maize',
        'Corn' => 'Maize',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic 4-Sensor Agronomic Fallback Rules (when ML Service is unavailable)
    |--------------------------------------------------------------------------
    | pH < ph_acidic -> Compost
    | pH > ph_alkaline -> Zinc Sulphate
    | EC > ec_high -> Compost (avoid salt stress)
    | Default -> NPK (14-14-14)
    */
    'fallback_rules' => [
        'ph_acidic' => 5.5,
        'ph_alkaline' => 7.8,
        'ec_high' => 2.5,
    ],
];
