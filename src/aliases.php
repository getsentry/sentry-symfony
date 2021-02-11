<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\DriverException as LegacyDriverExceptionInterface;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Driver\ExceptionConverterDriver as LegacyExceptionConverterDriverInterface;
use Doctrine\DBAL\Driver\Middleware as DoctrineMiddlewareInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Sentry\SentryBundle\EventListener\ErrorListenerExceptionEvent;
use Sentry\SentryBundle\EventListener\RequestListenerControllerEvent;
use Sentry\SentryBundle\EventListener\RequestListenerRequestEvent;
use Sentry\SentryBundle\EventListener\SubRequestListenerRequestEvent;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\ExceptionConverterDriverInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\MiddlewareInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
    if (!class_exists(ErrorListenerExceptionEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(ExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (!class_exists(RequestListenerRequestEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(RequestEvent::class, RequestListenerRequestEvent::class);
    }

    if (!class_exists(RequestListenerControllerEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(ControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (!class_exists(SubRequestListenerRequestEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
    }
} else {
    if (!class_exists(ErrorListenerExceptionEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(GetResponseForExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (!class_exists(RequestListenerRequestEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(GetResponseEvent::class, RequestListenerRequestEvent::class);
    }

    if (!class_exists(RequestListenerControllerEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(FilterControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (!class_exists(SubRequestListenerRequestEvent::class, false)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
    }
}

if (class_exists(Connection::class)) {
    if (!interface_exists(Result::class)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(Statement::class, Result::class);
    }

    if (!interface_exists(DoctrineMiddlewareInterface::class)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(MiddlewareInterface::class, DoctrineMiddlewareInterface::class);
    }

    if (!interface_exists(LegacyExceptionConverterDriverInterface::class)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(ExceptionConverterDriverInterface::class, LegacyExceptionConverterDriverInterface::class);
    }

    if (!interface_exists(LegacyDriverExceptionInterface::class)) {
        /** @psalm-suppress UndefinedClass */
        class_alias(DriverExceptionInterface::class, LegacyDriverExceptionInterface::class);
    }
}
