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
        $options['tags']['symfony_version'] = \Symfony\Component\HttpKernel\Kernel::VERSION;
        $options['tags']['php_version'] = phpversion();

        parent::__construct($dsn, $options);
    }
}
