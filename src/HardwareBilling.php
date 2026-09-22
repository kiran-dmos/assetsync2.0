<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class HardwareBilling
{
    private const ITEMTYPE = 'Computer';
    private const CYCLE_MONTHS = 48;

    private const FREQUENCY_MONTHLY = 'Monthly';
    private const FREQUENCY_ANNUAL = 'Annual';
    private const ANNUAL_MODEL = 'Lenovo ThinkStation P7';

    /** @var list<string> */
    private const EXCLUDED_STATUSES = [
        'In Stock - Available',
        'Retired - Disposed',
        'Retired - Pending Disposal',
    ];

    /** @var list<string> */
    private const MONTHLY_MODELS = [
        'Lenovo Thinkpad L14 Gen 5',
        'Lenovo ThinkPad L14 Gen 6',
        'Lenovo ThinkPad L14 Gen 4',
        'Lenovo ThinkPad L14 16GB',
        'Lenovo ThinkPad L14 32GB',
        'Lenovo ThinkPad L14 64GB',
        'ThinkPad L14 Gen 4',
        'ThinkPad L14 Gen 5',
        'Lenovo ThinkPad L14',
        'Lenovo Thinkpad P1 Gen 7',
        'Lenovo ThinkPad P1',
    ];

    private const OWNERSHIP_CUSTOMER_LEASED = 'Customer Leased';

    /** @var array<string,list<string>> */
    private const INPUT_LABELS = [
        'status' => ['DMOS Asset - Status', 'DMOS Asset Status', 'Asset Status', 'Status'],
        'ownership' => ['DMOS Asset - Ownership', 'DMOS Asset Ownership', 'Ownership'],
        'billable' => ['DMOS Asset - HW Billable', 'DMOS Asset HW Billable', 'HW Billable'],
        'installed_date' => ['DMOS Asset - Installed Date', 'DMOS Asset Installed Date', 'Installed Date'],
        'model' => ['DMOS Asset - Computer Model', 'DMOS Asset Computer Model', 'Computer Model'],
    ];

    /** @var array<string,array{key:string,type:string,labels:list<string>}> */
    private const OUTPUTS = [
        'start_date' => [
            'key' => 'Computer.PluginFieldsComputerdmosasset.hwbillingstartdatefield',
            'type' => 'date',
            'labels' => ['DMOS Asset - HW Billing Start Date', 'HW Billing Start Date'],
        ],
        'end_date' => [
            'key' => 'Computer.PluginFieldsComputerdmosasset.hwbillingenddatefield',
            'type' => 'date',
            'labels' => ['DMOS Asset - HW Billing End Date', 'HW Billing End Date'],
        ],
        'frequency' => [
            'key' => 'Computer.PluginFieldsComputerdmosasset.plugin_fields_hwbillingfrequencyfielddropdowns_id',
            'type' => 'dropdown',
            'labels' => ['DMOS Asset - HW Billing Frequency', 'HW Billing Frequency'],
        ],
        'month' => [
            'key' => 'Computer.PluginFieldsComputerdmosasset.hwbillingmonthfield',
            'type' => 'int',
            'labels' => ['DMOS Asset - HW Billing Month', 'HW Billing Month'],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function localInputKeys(string $itemtype): array
    {
        $fields = self::fields($itemtype);

        return $fields === null ? [] : array_values($fields['inputs']);
    }

    /**
     * @return list<string>
     */
    public static function localOutputKeys(string $itemtype): array
    {
        $fields = self::fields($itemtype);

        return $fields === null ? [] : array_values($fields['outputs']);
    }

    /**
     * @param array<string,mixed> $asset
     * @return array{
     *     values:array<string,mixed>,
     *     mappings:list<array{glpi_a_field:string,glpi_b_field:string,source_of_truth:string}>,
     *     custom_types:array<string,string>
     * }
     */
    public static function syncData(string $itemtype, array $asset, ?\DateTimeImmutable $today = null): array
    {
        $fields = self::fields($itemtype);
        if ($fields === null) {
            return [
                'values' => [],
                'mappings' => [],
                'custom_types' => [],
            ];
        }

        $valuesByName = self::calculateValues($asset, $fields['inputs'], $today ?? new \DateTimeImmutable('today'));
        $values = [];
        $mappings = [];
        $customTypes = [];

        foreach ($fields['outputs'] as $name => $key) {
            $values[$key] = $valuesByName[$name];
            $mappings[] = [
                'glpi_a_field' => $key,
                'glpi_b_field' => $key,
                'source_of_truth' => 'glpi_a',
            ];
            $customTypes[$key] = self::OUTPUTS[$name]['type'];
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
     * @return array{start_date:string,end_date:string,frequency:string,month:int}
     */
    public static function calculateValues(array $asset, array $keys, \DateTimeImmutable $today): array
    {
        $reset = [
            'start_date' => '',
            'end_date' => '',
            'frequency' => '',
            'month' => 0,
        ];

        $status = self::textValue($asset[$keys['status'] ?? ''] ?? '');
        $ownership = self::textValue($asset[$keys['ownership'] ?? ''] ?? '');
        $model = self::textValue($asset[$keys['model'] ?? ''] ?? '');
        $frequency = self::frequencyForModel($model);
        $installedDate = self::dateValue($asset[$keys['installed_date'] ?? ''] ?? '');

        if (
            in_array($status, self::EXCLUDED_STATUSES, true)
            || $ownership !== self::OWNERSHIP_CUSTOMER_LEASED
            || !self::billableValue($asset[$keys['billable'] ?? ''] ?? 0)
            || $frequency === ''
            || $installedDate === null
        ) {
            return $reset;
        }

        $startDate = self::startDate($installedDate);
        $endDate = $startDate->modify('+' . self::CYCLE_MONTHS . ' months')->modify('-1 day');

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'frequency' => $frequency,
            'month' => self::billingMonth($startDate, $today),
        ];
    }

    /**
     * @return array{inputs:array<string,string>,outputs:array<string,string>}|null
     */
    private static function fields(string $itemtype): ?array
    {
        if ($itemtype !== self::ITEMTYPE) {
            return null;
        }

        $inputs = [];
        foreach (self::INPUT_LABELS as $name => $labels) {
            $key = self::localKeyByLabels($itemtype, $labels);
            if ($key === '') {
                return null;
            }
            $inputs[$name] = $key;
        }

        $outputs = [];
        foreach (self::OUTPUTS as $name => $output) {
            $key = self::localKeyByLabels($itemtype, $output['labels']);
            if ($key !== '') {
                $types = FieldsText::customTypes($itemtype, [$key]);
                $type = (string) ($types[$key] ?? '');
                if ($type !== $output['type']) {
                    throw new \RuntimeException('The local HW Billing output field has the wrong type: ' . $key);
                }
            }
            $outputs[$name] = $key !== '' ? $key : $output['key'];
        }

        return [
            'inputs' => $inputs,
            'outputs' => $outputs,
        ];
    }

    /**
     * @param list<string> $labels
     */
    private static function localKeyByLabels(string $itemtype, array $labels): string
    {
        foreach (FieldsText::localOptions($itemtype) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = isset($option['name']) && is_scalar($option['name'])
                ? trim(strip_tags((string) $option['name']))
                : '';
            if (!in_array($label, $labels, true)) {
                continue;
            }

            $key = FieldsText::key($itemtype, $option);
            if ($key === '') {
                continue;
            }

            FieldsText::validate($option);

            return $key;
        }

        return '';
    }

    private static function frequencyForModel(string $model): string
    {
        if ($model === self::ANNUAL_MODEL) {
            return self::FREQUENCY_ANNUAL;
        }

        return in_array($model, self::MONTHLY_MODELS, true) ? self::FREQUENCY_MONTHLY : '';
    }

    private static function startDate(\DateTimeImmutable $installedDate): \DateTimeImmutable
    {
        $startDate = $installedDate->modify('first day of this month');

        return (int) $installedDate->format('j') <= 5 ? $startDate : $startDate->modify('first day of next month');
    }

    private static function billingMonth(\DateTimeImmutable $startDate, \DateTimeImmutable $today): int
    {
        $currentMonth = \DateTimeImmutable::createFromFormat('!Y-m-01', $today->format('Y-m-01'));
        if ($currentMonth === false || $currentMonth < $startDate) {
            return 0;
        }

        $months = ((int) $currentMonth->format('Y') - (int) $startDate->format('Y')) * 12;
        $months += (int) $currentMonth->format('n') - (int) $startDate->format('n');

        return min(self::CYCLE_MONTHS, $months + 1);
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

    private static function billableValue($value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        return is_scalar($value) && strtolower(trim((string) $value)) === 'yes';
    }

    private static function textValue($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
