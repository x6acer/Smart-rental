<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('requiredEnvironmentValue')) {
    function requiredEnvironmentValue(string $key): string
    {
        $value = getenv($key);

        if ($value === false || trim($value) === '') {
            throw new RuntimeException('Missing required environment variable: ' . $key);
        }

        return trim($value);
    }
}

if (!function_exists('optionalEnvironmentValue')) {
    function optionalEnvironmentValue(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }
}

if (!function_exists('requiredEnvironmentPort')) {
    function requiredEnvironmentPort(string $key): int
    {
        $value = requiredEnvironmentValue($key);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Environment variable ' . $key . ' must be a valid integer port value.');
        }

        return (int) $value;
    }
}

return [
    'database' => [
        'host' => requiredEnvironmentValue('DB_HOST'),
        'port' => requiredEnvironmentPort('DB_PORT'),
        'name' => requiredEnvironmentValue('DB_NAME'),
        'user' => requiredEnvironmentValue('DB_USER'),
        'password' => optionalEnvironmentValue('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'brevo' => [
        'host' => requiredEnvironmentValue('BREVO_HOST'),
        'port' => requiredEnvironmentPort('BREVO_PORT'),
        'username' => requiredEnvironmentValue('BREVO_USERNAME'),
        'password' => requiredEnvironmentValue('BREVO_PASSWORD'),
    ],
    'didit' => [
        'public_key' => requiredEnvironmentValue('DIDIT_PUBLIC_KEY'),
        'secret_key' => requiredEnvironmentValue('DIDIT_SECRET_KEY'),
        'base_url' => requiredEnvironmentValue('DIDIT_BASE_URL'),
    ],
    'momo' => [
        'api_user' => requiredEnvironmentValue('MOMO_API_USER'),
        'api_key' => requiredEnvironmentValue('MOMO_API_KEY'),
        'subscription_key' => requiredEnvironmentValue('MOMO_SUBSCRIPTION_KEY'),
        'target_environment' => requiredEnvironmentValue('MOMO_TARGET_ENVIRONMENT'),
    ],
    'payments' => [
        'momo' => [
            'api_user' => optionalEnvironmentValue('MOMO_API_USER', ''),
            'api_key' => optionalEnvironmentValue('MOMO_API_KEY', ''),
            'subscription_key' => optionalEnvironmentValue('MOMO_SUBSCRIPTION_KEY', ''),
            'target_environment' => optionalEnvironmentValue('MOMO_TARGET_ENVIRONMENT', 'sandbox'),
            'endpoint' => optionalEnvironmentValue('MOMO_API_ENDPOINT', ''),
        ],
        'card' => [
            'provider' => optionalEnvironmentValue('CARD_GATEWAY_PROVIDER', 'paystack'),
            'public_key' => optionalEnvironmentValue('CARD_PUBLIC_KEY', ''),
            'secret_key' => optionalEnvironmentValue('CARD_SECRET_KEY', ''),
            'base_url' => optionalEnvironmentValue('CARD_GATEWAY_BASE_URL', ''),
            'verify_path' => optionalEnvironmentValue('CARD_GATEWAY_VERIFY_PATH', '/transaction/verify/'),
        ],
    ],
];