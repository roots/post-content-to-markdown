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
