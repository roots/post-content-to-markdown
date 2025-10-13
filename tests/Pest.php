<?php

/**
 * Make an HTTP request to the WordPress Playground instance
 */
function makeRequest(string $path, array $headers = []): array
{
    $url = 'http://localhost:9400'.$path;

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

    // Parse response headers
    $responseHeaders = [];
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
        }
    }

    return [
        'body' => $response !== false ? $response : '',
        'headers' => $responseHeaders,
    ];
}
