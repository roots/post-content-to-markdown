<?php

test('wordpress site loads', function () {
    $response = makeRequest('/');

    expect($response['body'])->not->toBeEmpty();
});

test('single post returns markdown with query parameter', function () {
    $response = makeRequest('/hello-world/?format=markdown');

    expect($response['body'])
        ->toContain('# Hello world')
        ->toContain('WordPress');
});

test('single post returns markdown with Accept header', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'text/markdown',
    ]);

    expect($response['body'])
        ->toContain('# Hello world')
        ->toContain('WordPress');
});

test('main feed returns markdown with query parameter', function () {
    $response = makeRequest('/feed/?format=markdown');

    expect($response['body'])
        ->toContain('Markdown Feed')
        ->toContain('Hello world');
});

test('dedicated markdown feed endpoint works', function () {
    $response = makeRequest('/feed/markdown/');

    expect($response['body'])
        ->toContain('Markdown Feed')
        ->toContain('Hello world');
});

test('content type header is set to text/markdown', function () {
    $response = makeRequest('/hello-world/?format=markdown');

    expect($response['headers'])
        ->toHaveKey('content-type')
        ->and($response['headers']['content-type'])
        ->toContain('text/markdown');
});

test('html is served when Accept prefers html over markdown via q-values', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'text/html, text/markdown;q=0.5',
    ]);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toContain('text/html');
});

test('markdown is served when Accept prefers markdown over html via q-values', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'text/html;q=0.5, text/markdown;q=0.9',
    ]);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toContain('text/markdown');
});

test('html is served when Accept is */* (default representation wins)', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => '*/*',
    ]);

    expect($response['headers']['content-type'])->toContain('text/html');
});

test('406 is returned when Accept rules out every supported representation', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'application/x-content-negotiation-probe',
    ]);

    expect($response['status'])->toBe(406);
});

test('406 is returned for application/json on a page that does not serve json', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'application/json',
    ]);

    expect($response['status'])->toBe(406);
});

test('html response advertises Vary: Accept for cache correctness', function () {
    $response = makeRequest('/hello-world/');

    expect($response['headers'])->toHaveKey('vary')
        ->and($response['headers']['vary'])->toContain('Accept');
});

test('markdown response advertises Vary: Accept', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'text/markdown',
    ]);

    expect($response['headers'])->toHaveKey('vary')
        ->and($response['headers']['vary'])->toContain('Accept');
});
