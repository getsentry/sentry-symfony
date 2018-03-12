<?php

namespace Sentry\SentryBundle;

class SentrySymfonyClient extends \Raven_Client
{
    public function __construct($dsn = null, $options = [])
    {
        if (! empty($options['error_types'])) {
            $exParser = new ErrorTypesParser($options['error_types']);
            $options['error_types'] = $exParser->parse();
        }

        $options['sdk'] = [
            'name' => 'sentry-symfony',
            'version' => SentryBundle::VERSION,
        ];
        $options['tags']['symfony_version'] = \Symfony\Component\HttpKernel\Kernel::VERSION;

        parent::__construct($dsn, $options);
    }
}
