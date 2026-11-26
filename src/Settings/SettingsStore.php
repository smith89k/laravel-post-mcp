<?php

namespace Smith89k\PostMcp\Settings;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads and writes the settings page's choices. Without the table (migrations
 * not run yet) it reports nothing saved, so the connector keeps working.
 */
class SettingsStore
{
    protected ?bool $ready = null;

    /**
     * @var array<string, array{readable?: bool, writable?: bool, creatable?: bool, details_off?: list<string>, grow_off?: list<string>, label?: string, description?: string}>|null
     */
    protected ?array $settings = null;

    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $model = new FieldSetting;
                $this->ready = $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
            } catch (Throwable) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /**
     * @return array<string, array{readable?: bool, writable?: bool, creatable?: bool, details_off?: list<string>, grow_off?: list<string>, label?: string, description?: string}>
     */
    public function all(): array
    {
        if ($this->settings === null) {
            $this->settings = $this->ready()
                ? FieldSetting::query()->orderBy('field')->get()->mapWithKeys(fn (FieldSetting $row): array => [$row->field => $row->settings])->all()
                : [];
        }

        return $this->settings;
    }

    /**
     * Swap every saved choice for these. Fields with no choices are left out.
     *
     * @param  array<string, array{readable?: bool, writable?: bool, creatable?: bool, details_off?: list<string>, grow_off?: list<string>, label?: string, description?: string}>  $settingsByField
     */
    public function replace(array $settingsByField): void
    {
        $model = new FieldSetting;

        DB::connection($model->getConnectionName())->transaction(function () use ($settingsByField): void {
            FieldSetting::query()->delete();

            foreach (array_filter($settingsByField) as $field => $settings) {
                FieldSetting::query()->create(['field' => $field, 'settings' => $settings]);
            }
        });

        $this->settings = null;
    }

    public function reset(): void
    {
        $this->replace([]);
    }
}
