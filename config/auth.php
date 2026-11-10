<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Self-registration
    |--------------------------------------------------------------------------
    */

    'registration' => [
        'verification_expires_minutes' => (int) env('AUTH_VERIFICATION_EXPIRES_MINUTES', 1440),
        'prune_unverified_after_days' => (int) env('AUTH_PRUNE_UNVERIFIED_AFTER_DAYS', 7),
        'password' => [
            'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 10),
            'mixed_case' => (bool) env('AUTH_PASSWORD_MIXED_CASE', true),
            'numbers' => (bool) env('AUTH_PASSWORD_NUMBERS', true),
            'symbols' => (bool) env('AUTH_PASSWORD_SYMBOLS', true),
        ],
        'rate_limits' => [
            'register_per_minute' => (int) env('AUTH_REGISTER_PER_MINUTE', 5),
            'register_per_email_per_hour' => (int) env('AUTH_REGISTER_PER_EMAIL_PER_HOUR', 3),
            'verify_per_minute' => (int) env('AUTH_VERIFY_PER_MINUTE', 20),
            'resend_per_minute' => (int) env('AUTH_RESEND_PER_MINUTE', 3),
            'resend_per_hour' => (int) env('AUTH_RESEND_PER_HOUR', 5),
        ],
    ],

    'password_reset' => [
        'expires_minutes' => (int) env('AUTH_PASSWORD_RESET_EXPIRES_MINUTES', 60),
        'prune_after_days' => (int) env('AUTH_PASSWORD_RESET_PRUNE_AFTER_DAYS', 7),
        'max_failed_attempts' => (int) env('AUTH_PASSWORD_RESET_MAX_FAILED_ATTEMPTS', 10),
        'lockout_minutes' => (int) env('AUTH_PASSWORD_RESET_LOCKOUT_MINUTES', 15),
        'rate_limits' => [
            'request_per_minute' => (int) env('AUTH_PASSWORD_RESET_REQUEST_PER_MINUTE', 5),
            'request_per_email_per_hour' => (int) env('AUTH_PASSWORD_RESET_REQUEST_PER_EMAIL_PER_HOUR', 3),
            'attempts_per_minute' => (int) env('AUTH_PASSWORD_RESET_ATTEMPTS_PER_MINUTE', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password change by the signed-in user
    |--------------------------------------------------------------------------
    |
    | A wrong current password or second factor counts as a failure for the user; after max_failed_attempts the change
    | is locked for lockout_minutes, so a stolen session cannot guess the password.
    |
    */

    'password_change' => [
        'max_failed_attempts' => (int) env('AUTH_PASSWORD_CHANGE_MAX_FAILED_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('AUTH_PASSWORD_CHANGE_LOCKOUT_MINUTES', 15),
        'rate_limits' => [
            'attempts_per_minute' => (int) env('AUTH_PASSWORD_CHANGE_ATTEMPTS_PER_MINUTE', 6),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication (TOTP)
    |--------------------------------------------------------------------------
    */

    'two_factor' => [
        'issuer' => env('AUTH_TWO_FACTOR_ISSUER', env('APP_NAME', 'FatturaFacile')),
        'challenge_ttl_minutes' => (int) env('AUTH_TWO_FACTOR_CHALLENGE_TTL_MINUTES', 5),
        'max_challenge_attempts' => (int) env('AUTH_TWO_FACTOR_MAX_CHALLENGE_ATTEMPTS', 5),
        'recovery_codes' => (int) env('AUTH_TWO_FACTOR_RECOVERY_CODES', 8),
        'rate_limits' => [
            'challenge_per_minute' => (int) env('AUTH_TWO_FACTOR_CHALLENGE_PER_MINUTE', 10),
        ],
    ],

];
