<?php

declare(strict_types=1);

return [
    /*
    | Disk and folder of the generated XBRL instances and PDF/A documents.
    */
    'disk' => env('STATUTORY_FINANCIALS_DISK', 'local'),
    'folder' => 'statutory-financials',

    /*
    | Dimensional thresholds of the Italian schemes: a company may use a scheme when it does not exceed two of the three limits
    | (Art. 2435-bis and Art. 2435-ter c.c.). Statutory figures: check them against the law in force before the deposit.
    */
    'it_thresholds' => [
        'micro' => ['total_assets' => env('SFS_IT_MICRO_ASSETS', '220000'), 'revenue' => env('SFS_IT_MICRO_REVENUE', '440000'), 'average_employees' => env('SFS_IT_MICRO_EMPLOYEES', '5')],
        'abbreviated' => ['total_assets' => env('SFS_IT_ABB_ASSETS', '5500000'), 'revenue' => env('SFS_IT_ABB_REVENUE', '11000000'), 'average_employees' => env('SFS_IT_ABB_EMPLOYEES', '50')],
    ],

    /*
    | Swiss size thresholds above which the cash flow statement and the management report are mandatory (Art. 961 CO, two of three
    | criteria exceeded in two consecutive years).
    */
    'ch_thresholds' => ['total_assets' => env('SFS_CH_ASSETS', '20000000'), 'revenue' => env('SFS_CH_REVENUE', '40000000'), 'average_employees' => env('SFS_CH_EMPLOYEES', '250')],

    /*
    | XBRL taxonomies. The Italian deposit uses the XBRL Italia / InfoCamere (TEBENI) taxonomy: prefix, namespace and entry point of the
    | release in force are configurable because they change with every taxonomy release. The Swiss package is a structured statutory
    | package with its own namespace (no federal XBRL deposit exists for the annual accounts).
    */
    'taxonomy' => [
        'IT' => [
            'prefix' => env('SFS_XBRL_IT_PREFIX', 'itcc-ci'),
            'namespace' => env('SFS_XBRL_IT_NAMESPACE', 'http://www.xbrl.org/it/fr/gaap/ci/cor/2018-11-04'),
            'entry_point' => env('SFS_XBRL_IT_ENTRY_POINT', 'http://www.infocamere.it/itnn/fr/itcc/ci/ep/2018-11-04/itcc-ci-ese-2018-11-04.xsd'),
            'version' => env('SFS_XBRL_IT_VERSION', 'PCI_2018-11-04'),
            'entity_scheme' => 'http://www.registroimprese.it',
        ],
        'CH' => [
            'prefix' => 'ch-co',
            'namespace' => 'urn:fatturafacile:xbrl:ch-co:2024',
            'entry_point' => 'urn:fatturafacile:xbrl:ch-co:2024:entry-point.xsd',
            'version' => 'CH_CO_959_2024',
            'entity_scheme' => 'https://www.uid.admin.ch',
        ],
    ],

    /*
    | Crisis indicators: horizon of the DSCR in months and the share of the short-term financial debt that falls due within it.
    */
    'dscr_horizon_months' => 6,

    /*
    | Accounts (code prefixes) holding the compensation of directors and statutory auditors, for the automatic population of the notes.
    */
    'compensation_accounts' => [
        'directors' => ['IT' => ['5304'], 'CH' => ['5010']],
        'auditors' => ['IT' => ['5305'], 'CH' => ['6540']],
    ],

    /*
    | Fonts and ICC profile embedded in the PDF/A documents (shipped with dompdf, which is a dependency of the platform).
    */
    'pdf' => [
        'font_regular' => env('SFS_PDF_FONT_REGULAR', base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf')),
        'font_bold' => env('SFS_PDF_FONT_BOLD', base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')),
        'icc_profile' => env('SFS_PDF_ICC_PROFILE', base_path('vendor/dompdf/dompdf/lib/res/sRGB2014.icc')),
    ],

    /*
    | Tolerance (in units of the rounding mode) accepted by the cross-statement checks.
    */
    'tolerance_units' => 1,
];
