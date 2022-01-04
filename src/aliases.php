<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Doctrine\DBAL\Driver\Middleware as DoctrineMiddlewareInterface;
use Doctrine\DBAL\Result;
use Sentry\SentryBundle\EventListener\ErrorListenerExceptionEvent;
use Sentry\SentryBundle\EventListener\RequestListenerControllerEvent;
use Sentry\SentryBundle\EventListener\RequestListenerRequestEvent;
use Sentry\SentryBundle\EventListener\RequestListenerResponseEvent;
use Sentry\SentryBundle\EventListener\RequestListenerTerminateEvent;
use Sentry\SentryBundle\EventListener\SubRequestListenerRequestEvent;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapterForV2;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapterForV3;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapterForV2;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapterForV3;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\MiddlewareInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV2;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV3;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV2;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV3;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\DoctrineProvider;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Kernel;

if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
    if (!class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(ExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (!class_exists(RequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, RequestListenerRequestEvent::class);
    }

    if (!class_exists(RequestListenerControllerEvent::class, false)) {
        class_alias(ControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (!class_exists(RequestListenerResponseEvent::class, false)) {
        class_alias(ResponseEvent::class, RequestListenerResponseEvent::class);
    }

    if (!class_exists(RequestListenerTerminateEvent::class, false)) {
        class_alias(TerminateEvent::class, RequestListenerTerminateEvent::class);
    }

    if (!class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
    }
} else {
    if (!class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(GetResponseForExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (!class_exists(RequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, RequestListenerRequestEvent::class);
    }

    if (!class_exists(RequestListenerControllerEvent::class, false)) {
        class_alias(FilterControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (!class_exists(RequestListenerResponseEvent::class, false)) {
        class_alias(FilterResponseEvent::class, RequestListenerResponseEvent::class);
    }

    if (!class_exists(RequestListenerTerminateEvent::class, false)) {
        class_alias(PostResponseEvent::class, RequestListenerTerminateEvent::class);
    }

    if (!class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
    }
}

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

if (!interface_exists(DoctrineMiddlewareInterface::class)) {
    class_alias(MiddlewareInterface::class, DoctrineMiddlewareInterface::class);
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
