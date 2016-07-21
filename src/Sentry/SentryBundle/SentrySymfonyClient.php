<?php

namespace Sentry\SentryBundle;

class SentrySymfonyClient extends \Raven_Client
{
    public function __construct($dsn=null, $options=array())
    {
        $options['sdk'] = array(
            'name' => 'sentry-symfony',
            'version' => SentryBundle::VERSION,
        );
        parent::__construct($dsn, $options);
    }
}
