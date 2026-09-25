<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->exclude(['Integration/Symfony/App/var', 'Fixtures'])
    // Mapped by Doctrine, which proxies entities: it cannot be final.
    ->notPath('Bridge/Symfony/Doctrine/SubscriptionRecord.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'final_class' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'global_namespace_import' => ['import_classes' => false, 'import_functions' => false, 'import_constants' => false],
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_to_comment' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder);
