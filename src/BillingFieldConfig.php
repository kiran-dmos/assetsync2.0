<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class BillingFieldConfig
{
    private const CONTEXT = 'plugin:assetsync20';
    private const KEY = 'billing_fields';

    private const ROLES = [
        'hardware' => [
            'status' => ['Status', ['text', 'textarea', 'dropdown'], false],
            'ownership' => ['Ownership', ['text', 'textarea', 'dropdown'], false],
            'hw_billable' => ['HW Billable', ['yesno'], false],
            'installed_date' => ['Installed Date', ['date'], false],
            'model' => ['Model', ['text', 'textarea', 'dropdown'], false],
            'hw_billing_start_date' => ['HW Billing Start Date', ['date'], true],
            'hw_billing_end_date' => ['HW Billing End Date', ['date'], true],
            'hw_billing_frequency' => ['HW Billing Frequency', ['text', 'dropdown'], true],
            'hw_billing_month' => ['HW Billing Month', ['int'], true],
        ],
        'swsd' => [
            'status' => ['Status', ['text', 'textarea', 'dropdown'], false],
            'redeployed_date' => ['Redeployed Date', ['date'], false],
            'swsd_billable' => ['SW/SD Billable', ['yesno'], true],
            'swsd_billing_start_date' => ['SW/SD Billing Start Date', ['date'], true],
        ],
    ];

    // Only direct Computer columns used by billing are offered. None is a suitable billing output.
    private const NATIVE = [
        'states_id' => ['Status', 'dropdown', false],
        'computermodels_id' => ['Model', 'dropdown', false],
        'name' => ['Name', 'text', false],
        'serial' => ['Serial Number', 'text', false],
        'otherserial' => ['Inventory Number', 'text', false],
        'contact' => ['Contact', 'text', false],
        'contact_num' => ['Contact Number', 'text', false],
    ];

    public static function roles(): array
    {
        return self::ROLES;
    }

    public static function load(): ?array
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            throw new \RuntimeException('GLPI configuration storage is unavailable.');
        }

        $values = \Config::getConfigurationValues(self::CONTEXT, [self::KEY]);
        if (!array_key_exists(self::KEY, $values)) {
            return null;
        }

        $config = json_decode((string) $values[self::KEY], true);
        if (!is_array($config)) {
            throw new \RuntimeException('Billing field configuration is invalid JSON. Re-save it on the Billing Field Configuration page.');
        }

        return $config;
    }

    public static function save(array $input, bool $confirmDisableLegacy = false): void
    {
        $config = self::validate($input);
        if (!$config['hardware']['enabled'] && !$config['swsd']['enabled']
            && !$confirmDisableLegacy && self::load() === null) {
            throw new \RuntimeException('Both billing workflows would be disabled. Confirm that you want to stop legacy billing before saving.');
        }
        if (!class_exists('\Config') || !method_exists('\Config', 'setConfigurationValues')) {
            throw new \RuntimeException('GLPI configuration storage is unavailable.');
        }

        \Config::setConfigurationValues(self::CONTEXT, [self::KEY => json_encode($config, JSON_THROW_ON_ERROR)]);
    }

    public static function legacyDraft(): array
    {
        $config = ['hardware' => ['enabled' => false], 'swsd' => ['enabled' => false]];
        $warnings = [];

        try {
            $inputs = HardwareBilling::localInputKeys('Computer');
            $outputs = HardwareBilling::localOutputKeys('Computer');
            if (count($inputs) === 5 && count($outputs) === 4) {
                $config['hardware'] = [
                    'enabled' => true,
                    'status' => $inputs[0],
                    'ownership' => $inputs[1],
                    'hw_billable' => $inputs[2],
                    'installed_date' => $inputs[3],
                    'model' => $inputs[4],
                    'hw_billing_start_date' => $outputs[0],
                    'hw_billing_end_date' => $outputs[1],
                    'hw_billing_frequency' => $outputs[2],
                    'hw_billing_month' => $outputs[3],
                ];
                self::validate(['hardware' => $config['hardware']]);
            } else {
                $warnings[] = 'Hardware Billing legacy fields could not be fully detected. Select its fields before enabling it.';
            }
        } catch (\RuntimeException $error) {
            $warnings[] = 'Hardware Billing legacy fields need review: ' . $error->getMessage();
        }

        try {
            $inputs = SwsdBilling::localInputKeys('Computer');
            $outputs = SwsdBilling::localOutputKeys('Computer');
            if (count($inputs) === 2 && count($outputs) === 2) {
                $config['swsd'] = [
                    'enabled' => true,
                    'status' => $inputs[0],
                    'redeployed_date' => $inputs[1],
                    'swsd_billable' => $outputs[0],
                    'swsd_billing_start_date' => $outputs[1],
                ];
                self::validate(['swsd' => $config['swsd']]);
            } else {
                $warnings[] = 'SW/SD Billing legacy fields could not be fully detected. Select its fields before enabling it.';
            }
        } catch (\RuntimeException $error) {
            $warnings[] = 'SW/SD Billing legacy fields need review: ' . $error->getMessage();
        }

        return ['config' => $config, 'warnings' => $warnings];
    }

    public static function workflow(string $name): ?array
    {
        $config = self::load();
        if ($config === null) {
            return null;
        }

        $validated = self::validate($config);
        return $validated[$name]['enabled'] ? $validated[$name] : ['enabled' => false];
    }

    public static function options(string $workflow, string $role): array
    {
        $definition = self::ROLES[$workflow][$role] ?? null;
        if ($definition === null) {
            return ['native' => [], 'custom' => []];
        }

        $groups = ['native' => [], 'custom' => []];
        foreach (self::NATIVE as $key => [$label, $type, $outputAllowed]) {
            if (self::nativeFitsRole($key, $role) && in_array($type, $definition[1], true) && (!$definition[2] || $outputAllowed)) {
                $groups['native'][$key] = $label;
            }
        }

        foreach (FieldsText::localOptions('Computer') as $option) {
            if (!is_array($option)) {
                continue;
            }
            $key = FieldsText::key('Computer', $option);
            if ($key === '') {
                continue;
            }
            try {
                $metadata = self::customField($key);
            } catch (\RuntimeException) {
                continue;
            }
            if (in_array($metadata['type'], $definition[1], true)) {
                $groups['custom'][$key] = trim(strip_tags((string) ($option['name'] ?? $key)));
            }
        }

        return $groups;
    }

    public static function billingAsset(array $asset, array $keys): array
    {
        foreach (['status', 'model'] as $role) {
            $key = $keys[$role] ?? '';
            if ($key !== 'states_id' && $key !== 'computermodels_id') {
                continue;
            }
            $id = (int) ($asset[$key] ?? 0);
            $asset[$key] = self::nativeLabel($key, $id);
        }

        return $asset;
    }

    public static function fieldType(string $key): string
    {
        if (isset(self::NATIVE[$key])) {
            return self::NATIVE[$key][1];
        }

        return self::customField($key)['type'];
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::CONTEXT, [self::KEY]);
        }
    }

    private static function validate(array $input): array
    {
        $config = [];
        $outputKeys = [];
        $inputKeys = [];
        foreach (self::ROLES as $workflow => $roles) {
            $posted = $input[$workflow] ?? [];
            if (!is_array($posted)) {
                throw new \RuntimeException('Invalid ' . $workflow . ' Billing configuration.');
            }
            $enabled = !empty($posted['enabled']);
            $config[$workflow] = ['enabled' => $enabled];
            if (!$enabled) {
                continue;
            }

            foreach ($roles as $role => [$label, $types, $isOutput]) {
                $key = $posted[$role] ?? '';
                if (!is_string($key) || trim($key) === '') {
                    throw new \RuntimeException($workflow . ' Billing requires ' . $label . '.');
                }
                if (isset(self::NATIVE[$key])) {
                    $type = self::NATIVE[$key][1];
                    if (!self::nativeFitsRole($key, $role)) {
                        throw new \RuntimeException($label . ' cannot use native field ' . $key . '.');
                    }
                    if ($isOutput && !self::NATIVE[$key][2]) {
                        throw new \RuntimeException($label . ' cannot use native Computer field ' . $key . ' as a billing output.');
                    }
                } elseif (FieldsText::isCustom($key)) {
                    $type = self::customField($key)['type'];
                } else {
                    throw new \RuntimeException($label . ' is not an available Computer field: ' . $key);
                }
                if (!in_array($type, $types, true)) {
                    throw new \RuntimeException($label . ' has the wrong type (' . $type . '): ' . $key);
                }
                if ($isOutput) {
                    if (isset($outputKeys[$key])) {
                        throw new \RuntimeException('Billing outputs must use different fields: ' . $key);
                    }
                    $outputKeys[$key] = true;
                } else {
                    $inputKeys[$key] = true;
                }
                $config[$workflow][$role] = $key;
            }
        }

        foreach ($outputKeys as $key => $_) {
            if (isset($inputKeys[$key])) {
                throw new \RuntimeException('A billing output cannot also be an input: ' . $key);
            }
        }

        return $config;
    }

    private static function customField(string $key): array
    {
        $allMetadata = FieldsText::customMetadata('Computer', [$key]);
        $metadata = $allMetadata[$key] ?? null;
        if ($metadata === null) {
            throw new \RuntimeException('The Fields-plugin field is unavailable: ' . $key);
        }
        if (!class_exists('\PluginFieldsField')) {
            throw new \RuntimeException('Fields-plugin metadata is unavailable: ' . $key);
        }
        $definition = new \PluginFieldsField();
        if (!$definition->getFromDB((int) $metadata['field_id'])) {
            throw new \RuntimeException('The Fields-plugin definition is missing: ' . $key);
        }

        return FieldsText::metadataWithDefinition($metadata, $definition->fields);
    }

    private static function nativeFitsRole(string $key, string $role): bool
    {
        if ($key === 'states_id') {
            return $role === 'status';
        }
        if ($key === 'computermodels_id') {
            return $role === 'model';
        }

        return true;
    }

    private static function nativeLabel(string $key, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $class = $key === 'states_id' ? '\State' : '\ComputerModel';
        if (!class_exists($class)) {
            throw new \RuntimeException('GLPI ' . ($key === 'states_id' ? 'Status' : 'Model') . ' lookup is unavailable.');
        }
        $item = new $class();
        if (!$item->getFromDB($id)) {
            throw new \RuntimeException('GLPI ' . ($key === 'states_id' ? 'Status' : 'Model') . ' #' . $id . ' is missing.');
        }

        $label = trim((string) ($item->fields['name'] ?? ''));
        if ($label === '') {
            throw new \RuntimeException('GLPI ' . ($key === 'states_id' ? 'Status' : 'Model') . ' #' . $id . ' has no name.');
        }

        return $label;
    }
}
