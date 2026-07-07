<?php

return [
    'keys' => [
        'private' => storage_path('keys/license_private.pem'),
        'public'  => storage_path('keys/license_public.pem'),
    ],
    'algorithm' => 'RS256',
    'issuer'    => env('LICENSE_ISSUER', 'thirdline-grc-licensing'),
    'ttl'       => 365,
    'grace_period_days' => 7,
    'heartbeat_interval_hours' => 48,
    'max_clock_drift_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Feature catalog (canonical module list)
    |--------------------------------------------------------------------------
    | The full set of licensable modules, key => display label. This is the
    | single source of truth for the "Issue New License" feature checkboxes and
    | for validating the posted features. Must stay in sync with the consumer's
    | config('licensing.available_features') and with the per-plan feature maps.
    */
    'available_features' => [
        'audit'        => 'Audit Management',
        'risk'         => 'Risk Management',
        'compliance'   => 'Compliance Management',
        'swift_cscf'   => 'SWIFT CSCF Module',
        'iso_27001'    => 'ISO 27001 ISMS',
        'iso_22301'    => 'ISO 22301 BCMS',
        'iso_20000'    => 'ISO 20000 ITSMS',
        'iso_45001'    => 'ISO 45001 OHSMS',
        'iso_20022'    => 'ISO 20022 FMS',
        'pci_dss'      => 'PCI DSS v4.0',
        'ndpa'         => 'NDPA GAID',
        'icfr'         => 'ICFR/SOX Compliance',
        'ai_assistant' => 'AI Assistant',
        'analytics'    => 'Advanced Analytics',
        'reporting'    => 'Custom Reporting',
        'committee'    => 'Audit Committee Portal',
        'evidence_repo' => 'Evidence Repository',
        'performance'  => 'Performance Management',
    ],

    /*
    |--------------------------------------------------------------------------
    | License types
    |--------------------------------------------------------------------------
    | Orthogonal to "plan" (which is the entitlement tier). "type" is the
    | issuance intent of a license. Each type carries a default duration used
    | when an explicit duration_days is not supplied at issue time, and a
    | "trial" flag the consumer can surface in its UI (e.g. a countdown banner).
    */
    'default_type' => 'full',
    'types' => [
        'full'  => ['label' => 'Full License',     'default_duration_days' => 365, 'trial' => false],
        'trial' => ['label' => 'Trial',            'default_duration_days' => 14,  'trial' => true],
        'demo'  => ['label' => 'Demo',             'default_duration_days' => 7,   'trial' => true],
        'poc'   => ['label' => 'Proof of Concept', 'default_duration_days' => 30,  'trial' => true],
        'grace' => ['label' => 'Grace Period',     'default_duration_days' => 14,  'trial' => false],
    ],

    'plans' => [
        'starter' => [
            'max_users' => 5,
            'max_activations' => 1,
            'grace_days' => 3,
            'features' => [
                'audit' => true,
                'risk' => false,
                'compliance' => false,
                'swift_cscf' => false,
                'iso_27001' => false,
                'iso_22301' => false,
                'iso_20000' => false,
                'iso_45001' => false,
                'iso_20022' => false,
                'pci_dss' => false,
                'ndpa' => false,
                'icfr' => false,
                'ai_assistant' => false,
                'analytics' => false,
                'reporting' => false,
                'committee' => false,
                'evidence_repo' => false,
                'performance' => false,
            ],
        ],
        'professional' => [
            'max_users' => 25,
            'max_activations' => 2,
            'grace_days' => 7,
            'features' => [
                'audit' => true,
                'risk' => true,
                'compliance' => true,
                'swift_cscf' => false,
                'iso_27001' => false,
                'iso_22301' => false,
                'iso_20000' => false,
                'iso_45001' => false,
                'iso_20022' => false,
                'pci_dss' => false,
                'ndpa' => false,
                'icfr' => false,
                'ai_assistant' => false,
                'analytics' => true,
                'reporting' => true,
                'committee' => false,
                'evidence_repo' => false,
                'performance' => false,
            ],
        ],
        'enterprise' => [
            'max_users' => 100,
            'max_activations' => 5,
            'grace_days' => 14,
            'features' => [
                'audit' => true,
                'risk' => true,
                'compliance' => true,
                'swift_cscf' => true,
                'iso_27001' => true,
                'iso_22301' => true,
                'iso_20000' => true,
                'iso_45001' => true,
                'iso_20022' => true,
                'pci_dss' => true,
                'ndpa' => true,
                'icfr' => true,
                'ai_assistant' => true,
                'analytics' => true,
                'reporting' => true,
                'committee' => true,
                'evidence_repo' => true,
                'performance' => true,
            ],
        ],
    ],
];
