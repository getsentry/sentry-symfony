<?php

namespace Sentry\SentryBundle;

class SentrySymfonyClient extends \Raven_Client
{
    public function __construct($dsn=null, $options=array())
    {
        if (array_key_exists('error_types', $options)) {
            $exParser = new ErrorTypesParser($options['error_types']);
            $options['error_types'] = $exParser->parse();
        }

        $options['sdk'] = array(
            'name' => 'sentry-symfony',
            'version' => SentryBundle::VERSION,
        );
        parent::__construct($dsn, $options);
    }
}
