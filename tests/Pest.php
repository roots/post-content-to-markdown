<?php

/**
 * Base URL of the WordPress Playground instance
 */
function baseUrl(): string
{
    return 'http://127.0.0.1:9400';
}

/**
 * Make an HTTP request to the WordPress Playground instance
 */
function makeRequest(string $path, array $headers = []): array
{
    $url = baseUrl().$path;

    $context_options = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", array_map(
                fn ($key, $value) => "$key: $value",
                array_keys($headers),
                array_values($headers)
            )),
            'ignore_errors' => true,
        ],
    ];

    $context = stream_context_create($context_options);
    $response = @file_get_contents($url, false, $context);

    // Parse status line and response headers. Repeated headers (e.g. Vary) are
    // concatenated with ", " so assertions can inspect the combined value.
    $status = 0;
    $responseHeaders = [];
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];

                continue;
            }
            if (str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $key = strtolower(trim($key));
                $value = trim($value);
                $responseHeaders[$key] = isset($responseHeaders[$key])
                    ? $responseHeaders[$key].', '.$value
                    : $value;
            }
        }
    }

    return [
        'body' => $response !== false ? $response : '',
        'headers' => $responseHeaders,
        'status' => $status,
    ];
}

/**
 * POST a form to the WordPress Playground instance and return the cookies it
 * sets as a ready-to-send Cookie header value. Redirects are not followed.
 */
function postFormCookies(string $path, array $fields, array $headers = []): string
{
    $headers['Content-Type'] = 'application/x-www-form-urlencoded';

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", array_map(
            fn ($key, $value) => "$key: $value",
            array_keys($headers),
            array_values($headers)
        )),
        'content' => http_build_query($fields),
        'follow_location' => 0,
        'ignore_errors' => true,
    ]]);
    @file_get_contents(baseUrl().$path, false, $context);

    $cookies = [];
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^Set-Cookie:\s*([^=;]+=[^;]*)#i', $header, $m) && ! str_ends_with($m[1], '=deleted')) {
            $cookies[] = $m[1];
        }
    }

    return implode('; ', $cookies);
}

/**
 * Log in as the Playground admin and return the auth cookies.
 */
function adminCookies(): string
{
    $cookies = postFormCookies('/wp-login.php', [
        'log' => 'admin',
        'pwd' => 'password',
        'testcookie' => '1',
    ], [
        'Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check',
    ]);

    expect($cookies)->toContain('wordpress_logged_in_');

    return $cookies;
}
