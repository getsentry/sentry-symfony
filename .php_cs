<?php

$config = PhpCsFixer\Config::create();
$config->setRiskyAllowed(true);
$config->setRules([
    '@PSR2' => true,
    'array_syntax' => [
        'syntax' => 'short',
    ],
    'binary_operator_spaces' => [
        'align_double_arrow' => false,
        'align_equals' => false,
    ],
    'blank_line_after_namespace' => true,
    'blank_line_after_opening_tag' => true,
    'blank_line_before_statement' => ['statements' => ['return']],
    'concat_space' => ['spacing' => 'one'],
    'function_typehint_space' => true,
    'lowercase_cast' => true,
    'method_separation' => true,
    'native_function_casing' => true,
    'new_with_braces' => true,
    'no_empty_comment' => true,
    'no_empty_phpdoc' => true,
    'no_empty_statement' => true,
    'no_extra_consecutive_blank_lines' => [
        'tokens' => [
            'curly_brace_block',
            'extra',
            'parenthesis_brace_block',
            'square_brace_block',
            'throw',
            'use',
        ],
    ],
    'no_leading_import_slash' => true,
    'no_leading_namespace_whitespace' => true,
    'no_multiline_whitespace_around_double_arrow' => true,
    'no_short_bool_cast' => true,
    'no_singleline_whitespace_before_semicolons' => true,
    'no_trailing_comma_in_singleline_array' => true,
    'no_unneeded_control_parentheses' => true,
    'no_unused_imports' => true,
    'no_whitespace_before_comma_in_array' => true,
    'no_whitespace_in_blank_line' => true,
    'normalize_index_brace' => true,
    'not_operator_with_successor_space' => true,
    'object_operator_without_whitespace' => true,
    'ordered_imports' => true,
    'php_unit_construct' => true,
    'php_unit_dedicate_assert' => true,
    'phpdoc_single_line_var_spacing' => true,
    'phpdoc_trim' => true,
    'pre_increment' => true,
    'psr4' => true,
    'random_api_migration' => true,
    'self_accessor' => true,
    'short_scalar_cast' => true,
    'single_blank_line_before_namespace' => true,
    'single_class_element_per_statement' => true,
    'single_quote' => true,
    'space_after_semicolon' => true,
    'standardize_not_equals' => true,
    'ternary_operator_spaces' => true,
    'trailing_comma_in_multiline_array' => true,
    'trim_array_spaces' => true,
    'unary_operator_spaces' => true,
    'whitespace_after_comma_in_array' => true,
]);

$finder = PhpCsFixer\Finder::create();
$finder->in([
    'src',
    'test',
]);

$config->setFinder($finder);

return $config;
