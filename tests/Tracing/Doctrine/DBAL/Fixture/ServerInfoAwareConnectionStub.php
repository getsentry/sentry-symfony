<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL\Fixture;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;

interface ServerInfoAwareConnectionStub extends Connection, ServerInfoAwareConnection
{
}
