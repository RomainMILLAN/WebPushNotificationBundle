<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        __DIR__.'/tests/Integration/Symfony/App/var',
        __DIR__.'/tests/Fixtures',
        // Schema-only entity: Doctrine's metadata needs these "unused" mutable properties.
        __DIR__.'/src/Bridge/Symfony/Doctrine/SubscriptionRecord.php',
        // DI factories must stay [class, method] arrays: the container dumps them.
        Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector::class => [__DIR__.'/src/Bridge/Symfony/config'],
    ])
    // The package supports PHP 8.2: never let Rector introduce 8.3+ syntax (typed constants, #[Override]…).
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
