<?php

namespace Sentry\SentryBundle\Test\End2End\App\Controller;

use Symfony\Component\HttpFoundation\Response;

class MainController
{
    public function exception(): Response
    {
        throw new \RuntimeException('This is an intentional error');
    }
}
