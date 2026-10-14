<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Executes Agent B's allow-list through GLPI's own item API, never REST or raw SQL. */
final class InternalGlpiExecutor
{
    /** @var array<string,string> */
    private const ALLOWED_FIELDS = [
        'name'              => 'string',
        'otherserial'       => 'string',
        'comment'           => 'string',
        'computertypes_id'  => 'int',
        'computermodels_id' => 'int',
    ];

    /**
     * Executes the approved Computer fields through GLPI's own update API.
     * Serial is deliberately excluded: it is the immutable matching identity.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $expectedBefore
     * @return array{changed:list<string>,unchanged:list<string>,before:array<string,mixed>,after:array<string,mixed>}
     */
    public static function updateComputerFields(int $serviceUserId, int $computerId, array $input, ?array $expectedBefore): array
    {
        if ($serviceUserId <= 0) {
            throw new \RuntimeException('Agent B service user is not configured.');
        }
        $user = new \User();
        if (!$user->getFromDB($serviceUserId) || !empty($user->fields['is_deleted'])) {
            throw new \RuntimeException('Agent B service user is unavailable.');
        }
        $computer = new \Computer();
        if (!$computer->getFromDB($computerId) || !empty($computer->fields['is_deleted']) || !empty($computer->fields['is_template'])) {
            throw new \RuntimeException('Target Computer is unavailable.');
        }
        if ($input === [] || array_diff_key($input, self::ALLOWED_FIELDS) !== []) {
            throw new \RuntimeException('Only approved Computer fields are authorised in this release.');
        }
        $before = [];
        $write = ['id' => $computerId];
        foreach ($input as $field => $value) {
            $type = self::ALLOWED_FIELDS[$field];
            if ($type === 'string' && !is_string($value)) {
                throw new \RuntimeException('Invalid value for Computer field: ' . $field);
            }
            if ($type === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('Invalid value for Computer field: ' . $field);
            }
            $before[$field] = self::normalise($field, $computer->fields[$field] ?? null);
            $write[$field] = self::normalise($field, $value);
            if ($expectedBefore !== null && array_key_exists($field, $expectedBefore)
                && $before[$field] !== self::normalise($field, $expectedBefore[$field])) {
                throw new StaleTargetException('Target changed since GLPI A evaluated it.', $before);
            }
        }

        $changed = [];
        foreach ($write as $field => $value) {
            if ($field !== 'id' && $before[$field] !== $value) {
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            self::runAs($user, static function () use ($computer, $write): void {
                if (!$computer->update($write)) {
                    throw new \RuntimeException('GLPI B rejected the internal Computer update.');
                }
            });
        }
        if (!$computer->getFromDB($computerId)) {
            throw new \RuntimeException('GLPI B did not persist the Computer update.');
        }
        $after = [];
        foreach ($input as $field => $_value) {
            $after[$field] = self::normalise($field, $computer->fields[$field] ?? null);
            if ($after[$field] !== $write[$field]) {
                throw new \RuntimeException('GLPI B did not persist Computer field: ' . $field);
            }
        }
        return ['changed' => $changed, 'unchanged' => array_values(array_diff(array_keys($input), $changed)), 'before' => $before, 'after' => $after];
    }

    private static function normalise(string $field, mixed $value): string|int
    {
        return self::ALLOWED_FIELDS[$field] === 'int' ? (int) $value : (string) ($value ?? '');
    }

    /** Ensures GLPI's own History records the dedicated service account. */
    private static function runAs(\User $user, callable $operation): void
    {
        $saved = [];
        foreach (['glpiID', 'glpiname', 'glpifriendlyname'] as $key) {
            $saved[$key] = $_SESSION[$key] ?? null;
        }
        $_SESSION['glpiID'] = (int) $user->fields['id'];
        $_SESSION['glpiname'] = (string) ($user->fields['name'] ?? 'assetsync-agent-b');
        $_SESSION['glpifriendlyname'] = (string) $user->getFriendlyName();
        try {
            $operation();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SESSION[$key]);
                } else {
                    $_SESSION[$key] = $value;
                }
            }
        }
    }
}

final class StaleTargetException extends \RuntimeException
{
    /** @param array<string,mixed> $before */
    public function __construct(string $message, public readonly array $before)
    {
        parent::__construct($message);
    }
}
