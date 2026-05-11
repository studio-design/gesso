<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Pest tests follow Pest's own conventions — `it(...)` callbacks must be
    // non-static (Pest binds $this to the test case), and FQCN class
    // references avoid Pest's "use statement with non-compound name has no
    // effect" warning. Both clash with our `static_lambda` /
    // `global_namespace_import` rules. Excluding the directory is cheaper
    // than maintaining a parallel rule set just for Pest files.
    ->exclude('Integration/Pest')
    ->append([
        __FILE__,
    ])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        // Base ruleset
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        '@PHP8x2Migration' => true,
        '@PHPUnit10x0Migration:risky' => true,

        // Strict types
        'declare_strict_types' => true,

        // Imports
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'no_unused_imports' => true,
        'no_leading_import_slash' => true,
        'ordered_imports' => [
            'imports_order' => ['const', 'class', 'function'],
            'sort_algorithm' => 'alpha',
        ],
        'single_import_per_statement' => true,

        // Arrays & trailing commas
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
        'no_trailing_comma_in_singleline' => true,
        'trim_array_spaces' => true,
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array' => true,

        // Type declarations
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'nullable_type_declaration_for_default_null_value' => true,
        'void_return' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'compact_nullable_type_declaration' => true,

        // Strict comparisons & modernization
        'strict_comparison' => true,
        'strict_param' => true,
        'is_null' => true,
        'modernize_strpos' => true,
        'modernize_types_casting' => true,
        'get_class_to_class_keyword' => true,
        'no_alias_functions' => true,
        'no_unreachable_default_argument_value' => true,
        'self_accessor' => true,
        'dir_constant' => true,
        'function_to_constant' => true,
        'logical_operators' => true,
        'ternary_to_null_coalescing' => true,
        'ternary_to_elvis_operator' => true,

        // Native function/constant optimization
        'native_constant_invocation' => true,
        'native_function_invocation' => [
            'include' => ['@internal'],
        ],

        // PHPUnit
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
        'php_unit_set_up_tear_down_visibility' => true,
        'php_unit_attributes' => ['keep_annotations' => false],
        'php_unit_data_provider_name' => true,
        'php_unit_data_provider_return_type' => true,
        'php_unit_data_provider_static' => true,
        'php_unit_strict' => true,

        // String formatting
        'single_quote' => true,
        'explicit_string_variable' => true,

        // Spacing & whitespace
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'unary_operator_spaces' => true,
        'cast_spaces' => true,
        'object_operator_without_whitespace' => true,
        'no_spaces_around_offset' => true,

        // Blank lines & structure
        'blank_line_before_statement' => [
            'statements' => ['declare', 'return', 'throw', 'try'],
        ],
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'break',
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'return',
                'square_brace_block',
                'switch',
                'throw',
                'use',
            ],
        ],
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'single_line_empty_body' => true,

        // Method & function formatting
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'method_chaining_indentation' => true,
        'lambda_not_used_import' => true,
        'static_lambda' => true,

        // Class structure
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_public',
                'method_protected_static',
                'method_protected',
                'method_private_static',
                'method_private',
            ],
        ],
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'none',
                'method' => 'one',
                'property' => 'only_if_meta',
            ],
        ],
        'ordered_interfaces' => ['direction' => 'ascend', 'order' => 'alpha'],
        'ordered_types' => true,
        'ordered_traits' => true,
        'protected_to_private' => true,
        'single_class_element_per_statement' => true,

        // PHPDoc
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_order' => ['order' => ['param', 'return', 'throws']],
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types' => ['groups' => ['simple', 'meta']],
        'phpdoc_types_order' => true,
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_alias_tag' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => true,
        ],
        'no_empty_phpdoc' => true,

        // Control structures & miscellaneous
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_useless_concat_operator' => true,
        'no_useless_nullsafe_operator' => true,
        'no_superfluous_elseif' => true,
        'no_empty_statement' => true,
        'no_empty_comment' => true,
        'no_unneeded_braces' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unset_cast' => true,
        'no_null_property_initialization' => true,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'include' => true,
        'increment_style' => ['style' => 'post'],
        'list_syntax' => ['syntax' => 'short'],
        'multiline_comment_opening_closing' => true,
        'multiline_whitespace_before_semicolons' => true,
        'normalize_index_brace' => true,
        'operator_linebreak' => ['only_booleans' => true, 'position' => 'end'],
        'semicolon_after_instruction' => true,
        'single_line_comment_style' => true,
        'return_assignment' => true,
    ]);
