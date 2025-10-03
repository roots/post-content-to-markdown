<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'PostContentToMarkdown\Vendor',
    'output-dir' => 'vendor_prefixed',
    'finders' => [
        Finder::create()
            ->files()
            ->name(['*.php', 'composer.json'])
            ->in('vendor/league/html-to-markdown'),
    ],
];
