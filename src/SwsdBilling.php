<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class SwsdBilling
{
    public const BILLABLE_KEY = 'Computer.PluginFieldsComputerdmosasset.swsdbillablefieldtwo';
    public const BILLING_START_DATE_KEY = 'Computer.PluginFieldsComputerdmosasset.swsdbillingstartdatefield';

    private const ITEMTYPE = 'Computer';
    private const STATUS_IN_USE = 'In Use';

    /** @var array<string,string> */
    private const INPUTS = [
        'status' => 'Computer.PluginFieldsComputerdmosasset.plugin_fields_statusfielddropdowns_id',
        'redeployed_date' => 'Computer.PluginFieldsComputerdmosasset.redeployeddatefield',
    ];

    /** @var array<string,array{key:string,type:string}> */
    private const OUTPUTS = [
        'billable' => [
            'key' => self::BILLABLE_KEY,
            'type' => 'yesno',
        ],
        'start_date' => [
            'key' => self::BILLING_START_DATE_KEY,
            'type' => 'date',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function localInputKeys(string $itemtype): array
    {
        return self::fields($itemtype) === null ? [] : array_values(self::INPUTS);
    }

    /**
     * @return list<string>
     */
    public static function localOutputKeys(string $itemtype): array
    {
        return self::fields($itemtype) === null ? [] : array_column(self::OUTPUTS, 'key');
    }

    /**
     * @param array<string,mixed> $asset
     * @return array{
     *     values:array<string,mixed>,
     *     mappings:list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}>,
     *     custom_types:array<string,string>
     * }
     */
    public static function syncData(string $itemtype, array $asset): array
    {
        if (self::fields($itemtype) === null) {
            return [
                'values' => [],
                'mappings' => [],
                'custom_types' => [],
            ];
        }

        $valuesByName = self::calculateValues($asset, self::INPUTS);
        $values = [];
        $mappings = [];
        $customTypes = [];

        foreach (self::OUTPUTS as $name => $output) {
            $key = $output['key'];
            $values[$key] = $valuesByName[$name];
            $mappings[] = [
                'glpi_a_field' => $key,
                'glpi_b_field' => $key,
                'source_of_truth' => 'glpi_a',
            ];
            $customTypes[$key] = $output['type'];
        }

        return [
            'values' => $values,
            'mappings' => $mappings,
            'custom_types' => $customTypes,
        ];
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,string> $keys
     * @return array{billable:int,start_date:string}
     */
    public static function calculateValues(array $asset, array $keys): array
    {
        $redeployedDate = self::dateValue($asset[$keys['redeployed_date'] ?? ''] ?? '');
        $status = self::textValue($asset[$keys['status'] ?? ''] ?? '');

        if ($status !== self::STATUS_IN_USE || $redeployedDate === null) {
            return [
                'billable' => 0,
                'start_date' => '',
            ];
        }

        return [
            'billable' => 1,
            'start_date' => self::startDate($redeployedDate)->format('Y-m-d'),
        ];
    }

    /**
     * @return bool|null
     */
    private static function fields(string $itemtype): ?bool
    {
        if ($itemtype !== self::ITEMTYPE) {
            return null;
        }

        $expectedTypes = [
            self::INPUTS['status'] => 'dropdown',
            self::INPUTS['redeployed_date'] => 'date',
            self::OUTPUTS['billable']['key'] => self::OUTPUTS['billable']['type'],
            self::OUTPUTS['start_date']['key'] => self::OUTPUTS['start_date']['type'],
        ];

        try {
            $types = FieldsText::customTypes($itemtype, array_keys($expectedTypes));
        } catch (\RuntimeException $error) {
            if (str_contains($error->getMessage(), 'unavailable')) {
                return null;
            }

            throw $error;
        }

        foreach ($expectedTypes as $key => $expectedType) {
            if (($types[$key] ?? '') !== $expectedType) {
                throw new \RuntimeException('The local SW/SD Billing field has the wrong type: ' . $key);
            }
        }

        return true;
    }

    private static function startDate(\DateTimeImmutable $redeployedDate): \DateTimeImmutable
    {
        $startDate = $redeployedDate->modify('first day of this month');

        return (int) $redeployedDate->format('j') <= 5 ? $startDate : $startDate->modify('first day of next month');
    }

    private static function dateValue($value): ?\DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0);

        return $date !== false && !$hasErrors && $date->format('Y-m-d') === $match[1] ? $date : null;
    }

    private static function textValue($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
