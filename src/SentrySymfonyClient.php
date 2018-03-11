<?php

namespace Sentry\SentryBundle;

class SentrySymfonyClient extends \Raven_Client
{
    public function __construct(?string $dsn = null, array $options = [], $env = 'undefined')
    {
        if (! empty($options['error_types'])) {
            $exParser = new ErrorTypesParser($options['error_types']);
            $options['error_types'] = $exParser->parse();
        }

        $options['sdk'] = [
            'name' => 'sentry-symfony',
            'version' => SentryBundle::getVersion(),
        ];
        $default_tags = [
          "symfony_version" => \Symfony\Component\HttpKernel\Kernel::VERSION,
          "symfony_app_env" => $env
        ];
        if (isset($options["tags"])) {
            $options["tags"] = array_merge($options["tags"], $default_tags);
        } else {
            $options["tags"] = $default_tags;
        }

        parent::__construct($dsn, $options);
    }
}
