<?php

use Sentry\SentryBundle\EventListener\ErrorListenerExceptionEvent;
use Sentry\SentryBundle\EventListener\RequestListenerControllerEvent;
use Sentry\SentryBundle\EventListener\RequestListenerRequestEvent;
use Sentry\SentryBundle\EventListener\SubRequestListenerRequestEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
    if (! class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(ExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (! class_exists(RequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, RequestListenerRequestEvent::class);
    }

    if (! class_exists(RequestListenerControllerEvent::class, false)) {
        class_alias(ControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
    }
} else {
    if (! class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(GetResponseForExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }

    if (! class_exists(RequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, RequestListenerRequestEvent::class);
    }

    if (! class_exists(RequestListenerControllerEvent::class, false)) {
        class_alias(FilterControllerEvent::class, RequestListenerControllerEvent::class);
    }

    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
    }
}
