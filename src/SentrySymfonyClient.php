<?php

namespace Sentry\SentryBundle;

class SentrySymfonyClient extends \Raven_Client
{
    public function __construct(?string $dsn = null, array $options = [])
    {
        if (! empty($options['error_types'])) {
            $exParser = new ErrorTypesParser($options['error_types']);
            $options['error_types'] = $exParser->parse();
        }

        $options['sdk'] = [
            'name' => 'sentry-symfony',
            'version' => SentryBundle::getVersion(),
        ];

        parent::__construct($dsn, $options);
    }
}
