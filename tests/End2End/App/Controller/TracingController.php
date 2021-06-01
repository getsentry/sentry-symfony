<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

class TracingController
{
    /**
     * @var Connection|null
     */
    private $connection;

    public function __construct(Connection $connection = null)
    {
        $this->connection = $connection;
    }

    public function pingDatabase(): Response
    {
        if ($this->connection) {
            $this->connection->executeQuery('SELECT 1');
        }

        return new Response('Success');
    }
}
