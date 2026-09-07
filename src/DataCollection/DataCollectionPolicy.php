<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DataCollection;

use Sentry\Options;

/**
 * Resolves data collection options while preserving legacy behavior when the
 * data_collection option is not configured.
 *
 * @internal
 */
final class DataCollectionPolicy
{
    private function __construct()
    {
    }

    public static function shouldCollectUserInfo(Options $options): bool
    {
        $dataCollection = $options->getDataCollection();

        if (null === $dataCollection) {
            return $options->shouldSendDefaultPii();
        }

        return $dataCollection->shouldCollectUserInfo();
    }
}
