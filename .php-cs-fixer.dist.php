<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
        ],
        'declare_strict_types' => true,
        'get_class_to_class_keyword' => false,
        'random_api_migration' => true,
        'yoda_style' => true,
        'self_accessor' => false,
        'nullable_type_declaration_for_default_null_value' => false,
        'no_null_property_initialization' => false,
        'phpdoc_no_useless_inheritdoc' => false,
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_to_comment' => false,
        'phpdoc_align' => [
            'tags' => ['param', 'return', 'throws', 'type', 'var'],
        ],
        'phpdoc_line_span' => [
            'const' => 'multi',
            'method' => 'multi',
            'property' => 'multi',
        ],
        'trailing_comma_in_multiline' => [
            'after_heredoc' => false,
            'elements' => ['arrays'],
        ],
        'modern_serialization_methods' => false, // Could be re-enabled when we drop support for PHP 7.3 and lower
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__)
            ->exclude(['var'])
    );
