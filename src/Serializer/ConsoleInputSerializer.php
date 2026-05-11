<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Serializer;

use Symfony\Component\Console\Input\InputInterface;

/**
 * @author Sylvain Fabre
 */
final class ConsoleInputSerializer
{
    /**
     * @return array{arguments: array<string, mixed>, options: array<string, mixed>}
     */
    public function __invoke(InputInterface $input): array
    {
        return [
            'arguments' => $input->getArguments(),
            'options' => $input->getOptions(),
        ];
    }
}
