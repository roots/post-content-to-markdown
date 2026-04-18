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
