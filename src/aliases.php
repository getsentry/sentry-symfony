<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Doctrine\DBAL\Result;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapterForV2;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapterForV3;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapterForV2;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapterForV3;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV2;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV3;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV2;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV3;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\DoctrineProvider;

if (interface_exists(AdapterInterface::class)) {
    if (!class_exists(DoctrineProvider::class, false) && version_compare(\PHP_VERSION, '8.0.0', '>=')) {
        if (!class_exists(TraceableCacheAdapter::class, false)) {
            class_alias(TraceableCacheAdapterForV3::class, TraceableCacheAdapter::class);
        }

        if (!class_exists(TraceableTagAwareCacheAdapter::class, false)) {
            class_alias(TraceableTagAwareCacheAdapterForV3::class, TraceableTagAwareCacheAdapter::class);
        }
    } else {
        if (!class_exists(TraceableCacheAdapter::class, false)) {
            class_alias(TraceableCacheAdapterForV2::class, TraceableCacheAdapter::class);
        }

        if (!class_exists(TraceableTagAwareCacheAdapter::class, false)) {
            class_alias(TraceableTagAwareCacheAdapterForV2::class, TraceableTagAwareCacheAdapter::class);
        }
    }
}

if (!class_exists('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatement')) {
    if (class_exists(Result::class)) {
        class_alias(TracingStatementForV3::class, 'Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingStatement');
        class_alias(TracingDriverForV3::class, 'Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver');
    } elseif (interface_exists(Result::class)) {
        class_alias(TracingStatementForV2::class, 'Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingStatement');
        class_alias(TracingDriverForV2::class, 'Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver');
    }
}
