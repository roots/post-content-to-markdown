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

test('.md URL suffix serves markdown', function () {
    $response = makeRequest('/hello-world.md');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toContain('text/markdown')
        ->and($response['body'])->toContain('# Hello world');
});

test('.md URL response carries X-Robots-Tag: noindex, nofollow', function () {
    $response = makeRequest('/hello-world.md');

    expect($response['headers'])->toHaveKey('x-robots-tag')
        ->and($response['headers']['x-robots-tag'])->toContain('noindex');
});

test('regular URL response does not carry noindex from this plugin', function () {
    $response = makeRequest('/hello-world/');

    expect($response['headers']['x-robots-tag'] ?? '')->not->toContain('noindex');
});

test('.md URL overrides Accept header so the shared URL wins', function () {
    $response = makeRequest('/hello-world.md', [
        'Accept' => 'text/html',
    ]);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toContain('text/markdown');
});

test('.md suffix on REST paths is ignored (skipped)', function () {
    $response = makeRequest('/wp-json/wp/v2/posts.md');

    expect($response['headers']['content-type'] ?? '')->not->toContain('text/markdown');
});

test('.md suffix on wp-admin paths is ignored (skipped)', function () {
    $response = makeRequest('/wp-admin/index.php.md');

    expect($response['headers']['content-type'] ?? '')->not->toContain('text/markdown');
});

test('.md suffix on wp-login.php is ignored (skipped)', function () {
    $response = makeRequest('/wp-login.php.md');

    expect($response['headers']['content-type'] ?? '')->not->toContain('text/markdown');
});

test('.md URL for a non-existent page returns 404', function () {
    $response = makeRequest('/does-not-exist.md');

    expect($response['status'])->toBe(404);
});

test('HTML response advertises the .md alternate via a Link header', function () {
    $response = makeRequest('/hello-world/');

    expect($response['headers'])->toHaveKey('link')
        ->and($response['headers']['link'])->toContain('rel="alternate"')
        ->and($response['headers']['link'])->toContain('type="text/markdown"');
});

test('HTML head includes a <link rel="alternate" type="text/markdown">', function () {
    $response = makeRequest('/hello-world/');

    expect($response['body'])->toContain('rel="alternate"')
        ->and($response['body'])->toContain('type="text/markdown"')
        ->and($response['body'])->toContain('href="');
});

test('markdown via Accept reports X-Markdown-Source: accept', function () {
    $response = makeRequest('/hello-world/', [
        'Accept' => 'text/markdown',
    ]);

    expect($response['headers']['x-markdown-source'] ?? '')->toBe('accept');
});

test('markdown via .md URL reports X-Markdown-Source: md-url', function () {
    $response = makeRequest('/hello-world.md');

    expect($response['headers']['x-markdown-source'] ?? '')->toBe('md-url');
});

test('markdown via query parameter reports X-Markdown-Source: query', function () {
    $response = makeRequest('/hello-world/?format=markdown');

    expect($response['headers']['x-markdown-source'] ?? '')->toBe('query');
});

test('password-protected post is not served as markdown', function (string $path, array $headers) {
    $response = makeRequest($path, $headers);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'] ?? '')->not->toContain('text/markdown')
        ->and($response['body'])->toContain('post-password-form')
        ->and($response['body'])->not->toContain('PROTECTED-BODY');
})->with([
    'query parameter' => ['/protected-post/?format=markdown', []],
    'Accept header' => ['/protected-post/', ['Accept' => 'text/markdown']],
    '.md URL' => ['/protected-post.md', []],
]);

test('password-protected post does not advertise a .md alternate', function () {
    $response = makeRequest('/protected-post/');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('post-password-form')
        ->and($response['headers']['link'] ?? '')->not->toContain('text/markdown')
        ->and($response['body'])->not->toContain('type="text/markdown"');
});

test('markdown feed excludes password-protected posts', function () {
    makeRequest('/?clear_markdown_feed_cache=1');

    $dedicated = makeRequest('/feed/markdown/');
    $negotiated = makeRequest('/feed/?format=markdown');

    expect($dedicated['body'])->toContain('Hello world')
        ->and($dedicated['body'])->not->toContain('PROTECTED-BODY')
        ->and($dedicated['body'])->not->toContain('PROTECTED-COMMENT')
        ->and($negotiated['body'])->toContain('Hello world')
        ->and($negotiated['body'])->not->toContain('PROTECTED-BODY')
        ->and($negotiated['body'])->not->toContain('PROTECTED-COMMENT');
});

test('password-protected post comment feed is not served as markdown', function () {
    $response = makeRequest('/protected-post/feed/?format=markdown');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'] ?? '')->not->toContain('text/markdown')
        ->and($response['body'])->toContain('Protected Comments')
        ->and($response['body'])->not->toContain('PROTECTED-BODY')
        ->and($response['body'])->not->toContain('PROTECTED-COMMENT');
});

test('password-protected post is served as markdown once the password is entered', function () {
    $cookies = postFormCookies('/wp-login.php?action=postpass', ['post_password' => 'letmein']);

    expect($cookies)->toContain('wp-postpass_');

    $response = makeRequest('/protected-post/?format=markdown', [
        'Cookie' => $cookies,
    ]);

    expect($response['headers']['content-type'] ?? '')->toContain('text/markdown')
        ->and($response['body'])->toContain('PROTECTED-BODY');
});

test('single post comment feed returns markdown', function () {
    $response = makeRequest('/hello-world/feed/?format=markdown');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'] ?? '')->toContain('text/markdown')
        ->and($response['body'])->toContain('# Hello world')
        ->and($response['body'])->toContain('## Comments');
});

test('comment feed is not served as markdown for posts that are not allowed', function (string $path) {
    $response = makeRequest($path);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'] ?? '')->not->toContain('text/markdown')
        ->and($response['body'])->toContain('<rss')
        ->and($response['body'])->not->toContain('HIDDEN-BODY');
})->with([
    'post type not allowed' => ['/sample-page/feed/?format=markdown'],
    'post_allowed returns false' => ['/hidden-post/feed/?format=markdown'],
]);

test('markdown feed excludes posts excluded by post_allowed', function () {
    makeRequest('/?clear_markdown_feed_cache=1');

    $response = makeRequest('/feed/markdown/');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Hello world')
        ->and($response['body'])->not->toContain('Hidden Post')
        ->and($response['body'])->not->toContain('HIDDEN-BODY');
});

test('markdown feed is built with no current user', function () {
    $cookies = adminCookies();

    makeRequest('/?clear_markdown_feed_cache=1');

    // A logged-in user builds the feed cache first.
    $member = makeRequest('/feed/markdown/', ['Cookie' => $cookies]);
    $anonymous = makeRequest('/feed/markdown/');

    expect($member['body'])->toContain('Members Post')
        ->and($member['body'])->not->toContain('MEMBER-SECRET')
        ->and($anonymous['body'])->toContain('login required')
        ->and($anonymous['body'])->not->toContain('MEMBER-SECRET');
});

test('shortcodes and blocks in comments are not rendered', function () {
    makeRequest('/?clear_markdown_feed_cache=1');

    $commentFeed = makeRequest('/hello-world/feed/?format=markdown');
    $feed = makeRequest('/feed/markdown/');

    foreach ([$commentFeed, $feed] as $response) {
        expect($response['headers']['content-type'] ?? '')->toContain('text/markdown')
            ->and($response['body'])->toContain('COMMENT-TEXT')
            ->and($response['body'])->not->toContain('PRIVATE-DATA')
            ->and($response['body'])->not->toContain('PRIVATE-BLOCK-DATA');
    }
});
