<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/0',
    'logger' => 'app.logger',
    'options' => [
        'integrations' => ['App\\Sentry\\Integration\\FooIntegration'],
        'default_integrations' => false,
        'prefixes' => ['%kernel.project_dir%'],
        'sample_rate' => 1,
        'enable_tracing' => true,
        'traces_sample_rate' => 1,
        'traces_sampler' => 'App\\Sentry\\Tracing\\TracesSampler',
        'profiles_sample_rate' => 1,
        'attach_stacktrace' => true,
        'attach_metric_code_locations' => true,
        'context_lines' => 0,
        'environment' => 'development',
        'logger' => 'php',
        'spotlight' => true,
        'spotlight_url' => 'http://localhost:8969',
        'release' => '4.0.x-dev',
        'server_name' => 'localhost',
        'ignore_exceptions' => ['Symfony\Component\HttpKernel\Exception\BadRequestHttpException'],
        'ignore_transactions' => ['GET tracing_ignored_transaction'],
        'before_send' => 'App\\Sentry\\BeforeSendCallback',
        'before_send_transaction' => 'App\\Sentry\\BeforeSendTransactionCallback',
        'before_send_check_in' => 'App\\Sentry\\BeforeSendCheckInCallback',
        'before_send_metrics' => 'App\\Sentry\\BeforeSendMetricsCallback',
        'trace_propagation_targets' => ['website.invalid'],
        'tags' => [
            'context' => 'development',
        ],
        'error_types' => \E_ALL,
        'max_breadcrumbs' => 1,
        'before_breadcrumb' => 'App\\Sentry\\BeforeBreadcrumbCallback',
        'in_app_exclude' => ['%kernel.cache_dir%'],
        'in_app_include' => ['%kernel.project_dir%'],
        'send_default_pii' => true,
        'max_value_length' => 255,
        'transport' => 'App\\Sentry\\Transport',
        'http_client' => 'App\\Sentry\\HttpClient',
        'http_proxy' => 'proxy.example.com:8080',
        'http_proxy_authentication' => 'user:password',
        'http_connect_timeout' => 15,
        'http_timeout' => 10,
        'http_ssl_verify_peer' => true,
        'http_compression' => true,
        'capture_silenced_errors' => true,
        'max_request_body_size' => 'none',
        'class_serializers' => ['App\\FooClass' => 'App\\Sentry\\Serializer\\FooClassSerializer'],
    ],
    'messenger' => [
        'enabled' => true,
        'capture_soft_fails' => false,
    ],
    'tracing' => [
        'dbal' => [
            'enabled' => false,
            'connections' => ['default'],
        ],
        'http_client' => [
            'enabled' => false,
        ],
        'twig' => [
            'enabled' => false,
        ],
        'cache' => [
            'enabled' => false,
        ],
        'console' => [
            'excluded_commands' => ['app:command'],
        ],
    ],
]);
