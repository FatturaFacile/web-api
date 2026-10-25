<?php

use L5Swagger\CustomBuilderInterface;
use L5Swagger\CustomGeneratorInterface;
use L5Swagger\Generator;
use OpenApi\scan;

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'FatturaFacile API Documentation',
            ],

            'routes' => [
                /*
                 * Route for accessing api documentation interface
                 */
                'api' => 'api/documentation',
            ],
            'paths' => [
                /*
                 * Edit to include full URL in ui for assets
                 */
                'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),

                /*
                 * Edit to set path where swagger ui assets should be stored
                 */
                'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),

                /*
                 * File name of the generated json documentation file
                 */
                'docs_json' => 'api-docs.json',

                /*
                 * File name of the generated YAML documentation file
                 */
                'docs_yaml' => 'api-docs.yaml',

                /*
                 * Set this to `json` or `yaml` to determine which documentation file to use in UI
                 */
                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),

                /*
                 * Absolute paths to directory containing the swagger annotations are stored.
                 */
                'annotations' => [
                    base_path('app'),
                ],
            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            /*
             * Route for accessing parsed swagger annotations.
             */
            'docs' => 'docs',

            /*
             * Route for Oauth2 authentication callback.
             */
            'oauth2_callback' => 'api/oauth2-callback',

            /*
             * Publicly accessible routes (empty middleware arrays)
             */
            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],

            /*
             * Route Group options
             */
            'group_options' => [],
        ],

        'paths' => [
            /*
             * Absolute path to location where parsed annotations will be stored
             */
            'docs' => storage_path('api-docs'),

            /*
             * Absolute path to directory where to export views
             */
            'views' => base_path('resources/views/vendor/l5-swagger'),

            /*
             * Edit to set the api's base path
             */
            'base' => env('L5_SWAGGER_BASE_PATH', null),

            /*
             * Absolute path to directories that should be excluded from scanning
             * @deprecated Please use `scanOptions.exclude`
             * `scanOptions.exclude` overwrites this
             */
            'excludes' => [],
        ],

        'scanOptions' => [
            /**
             * Optional CustomGeneratorInterface implementation that creates an OpenApi\Generator instance.
             */
            'generator_factory' => null,

            /**
             * Optional CustomBuilderInterface implementation that creates an OpenApi\Builder instance.
             */
            'builder_factory' => null,

            /**
             * Configuration for default processors.
             */
            'default_processors_configuration' => [],

            /**
             * analyser: defaults to \OpenApi\StaticAnalyser .
             */
            'analyser' => null,

            /**
             * analysis: defaults to a new \OpenApi\Analysis .
             */
            'analysis' => null,

            /**
             * Custom processors.
             */
            'processors' => [],

            /**
             * pattern: string File pattern(s) to scan (default: *.php) .
             */
            'pattern' => null,

            /*
             * Absolute path to directories that should be excluded from scanning
             */
            'exclude' => [],

            /*
             * Allows to generate specs either for OpenAPI 3.0.0 or OpenAPI 3.1.0.
             */
            'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', Generator::OPEN_API_DEFAULT_SPEC_VERSION),
        ],

        /*
         * API security definitions.
         */
        'securityDefinitions' => [
            'securitySchemes' => [],
            'security' => [],
        ],

        /*
         * Set this to `true` in development mode so that docs would be regenerated on each request
         */
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

        /*
         * Set this to `true` to generate a copy of documentation in yaml format
         */
        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

        /*
         * Edit to trust the proxy's ip address
         */
        'proxy' => false,

        /*
         * External configs plugin
         */
        'additional_config_url' => null,

        /*
         * Operations sort order
         */
        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', null),

        /*
         * Swagger validator URL (null disables validation and avoids insecure HTTP calls)
         */
        'validator_url' => null,

        /*
         * Swagger UI configuration parameters
         */
        'ui' => [
            'display' => [
                'dark_mode' => env('L5_SWAGGER_UI_DARK_MODE', false),
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'list'),
                'filter' => env('L5_SWAGGER_UI_FILTERS', true),
            ],

            'authorization' => [
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', true),
                'oauth2' => [
                    'use_pkce_with_authorization_code_grant' => false,
                ],
            ],
        ],

        /*
         * Constants which can be used in annotations
         */
        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', 'https://api.fatturafacile.eu'),
        ],
    ],
];
