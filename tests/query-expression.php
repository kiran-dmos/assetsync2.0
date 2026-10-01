<?php

declare(strict_types=1);

namespace Glpi\DBAL;

final class QueryExpression
{
    public function __construct(public string $expression, public ?string $alias = null)
    {
    }
}
