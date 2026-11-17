<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Recruiting & ATS (HR Mod 070)
|--------------------------------------------------------------------------
|
| Drivers for the seams that stand in for modules not available yet (AI CV parsing, e-signature),
| the storage disk of CVs, the public careers page base URL and the GDPR / nLPD retention defaults.
|
*/

return [
    'cv_parser' => env('RECRUITING_CV_PARSER', 'naive'),

    'signature_provider' => env('RECRUITING_SIGNATURE_PROVIDER', 'manual'),

    'cv_disk' => env('RECRUITING_CV_DISK', 'private'),

    'cv_max_kilobytes' => 5120,

    /*
     * Base URL of the frontend careers pages; job links are `{base}/careers/{slug}/jobs/{id}`.
     */
    'public_careers_base_url' => env('RECRUITING_PUBLIC_CAREERS_BASE_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost'))),

    'retention' => [
        'default_months' => 6,
        'allowed_months' => [6, 12],
    ],

    /*
     * Optional `pdftotext` binary used by the naive parser when it is installed on the host.
     */
    'pdftotext_binary' => env('RECRUITING_PDFTOTEXT_BINARY', '/usr/bin/pdftotext'),

    /*
     * Keyword dictionary of the naive CV parser. Matching is case insensitive on word boundaries.
     */
    'skills_dictionary' => [
        'PHP', 'Laravel', 'Symfony', 'JavaScript', 'TypeScript', 'Angular', 'React', 'Vue', 'Node.js', 'Python',
        'Java', 'Kotlin', 'C#', '.NET', 'Golang', 'Rust', 'SQL', 'PostgreSQL', 'MySQL', 'Docker', 'Kubernetes', 'AWS',
        'Azure', 'Git', 'Linux', 'REST API', 'GraphQL', 'HTML', 'CSS', 'Excel', 'SAP', 'Accounting', 'Payroll',
        'Project Management', 'Scrum', 'Agile', 'Sales', 'Marketing', 'Customer Service', 'AutoCAD', 'CNC',
        'Welding', 'Logistics', 'Forklift', 'HACCP', 'Bookkeeping', 'Figma', 'Photoshop',
    ],

    /*
     * Languages recognised in CVs, keyed by the label reported in the extracted profile.
     *
     * @var array<string, list<string>>
     */
    'languages_dictionary' => [
        'Italian' => ['italian', 'italiano', 'italienisch', 'italien'],
        'German' => ['german', 'tedesco', 'deutsch', 'allemand'],
        'French' => ['french', 'francese', 'französisch', 'franzosisch', 'français', 'francais'],
        'English' => ['english', 'inglese', 'englisch', 'anglais'],
        'Spanish' => ['spanish', 'spagnolo', 'spanisch', 'espagnol', 'español'],
        'Romansh' => ['romansh', 'romancio', 'rätoromanisch', 'romanche'],
    ],
];
