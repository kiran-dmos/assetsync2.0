<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Preserve a failed request's classification across a collection/permission callback. */
final class RemoteRequestFailure extends \RuntimeException
{
    public function __construct(public readonly array $result)
    {
        parent::__construct((string) ($result['message'] ?? 'GLPI B request failed.'));
    }
}
