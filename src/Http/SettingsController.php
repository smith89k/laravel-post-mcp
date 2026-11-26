<?php

namespace Smith89k\PostMcp\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Smith89k\PostMcp\Fields\Field;
use Smith89k\PostMcp\Fields\FieldResolver;
use Smith89k\PostMcp\PostMcp;
use Smith89k\PostMcp\Settings\SavedSettings;
use Smith89k\PostMcp\Settings\SettingsStore;
use Smith89k\PostMcp\Support\ConnectedApps;

/**
 * The settings page: choose by clicking which fields the connected AI app sees and changes.
 */
class SettingsController
{
    public const ACCESS_LEVELS = ['off', 'read', 'edit'];

    public function __construct(
        protected PostMcp $postMcp,
        protected FieldResolver $fieldResolver,
        protected SettingsStore $settingsStore,
        protected SavedSettings $savedSettings,
        protected ConnectedApps $connectedApps,
    ) {}

    public function show(): View
    {
        $baseline = $this->fieldResolver->baseline();
        $current = $this->fieldResolver->build();
        $rows = [];

        foreach ($baseline->all() as $name => $field) {
            $now = $current->get($name) ?? $field;
            $locks = $this->savedSettings->locks($field);

            $rows[] = [
                'name' => $name,
                'label' => $now->label,
                'default_label' => $field->label,
                'description' => $now->description,
                'default_description' => $field->description,
                'type' => $field->isRelation() ? ($field->isMultipleRelation() ? 'list of links' : 'link') : $field->type,
                'is_relation' => $field->isRelation(),
                'access' => $this->accessOf($now),
                'creatable' => (bool) ($now->relation['creatable'] ?? false),
                'one' => Str::lower(Str::headline(Str::singular($name))),
                'parts' => $field->isRelation() && $field->writable ? $this->partsOf($field, $now, $locks['create']) : [],
                'locks' => $locks,
                'group' => match (true) {
                    ! $field->readable => 'hidden',
                    $field->isRelation() => 'links',
                    default => 'fields',
                },
            ];
        }

        return view('post-mcp::settings', [
            'rows' => collect($rows)->groupBy('group'),
            'ready' => $this->settingsStore->ready(),
            'hasChoices' => $this->settingsStore->all() !== [],
            'connectorUrl' => $this->postMcp->connectorUrl(),
            'siteName' => (string) config('app.name', 'Laravel'),
            'formAction' => $this->postMcp->settingsPageUrl(),
            'apps' => $apps = $this->connectedApps->names(),
            'ai' => match (count($apps)) {
                0 => 'your AI app',
                1 => $apps[0],
                default => 'your AI apps',
            },
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'fields' => ['array'],
            'fields.*.access' => ['nullable', 'in:'.implode(',', self::ACCESS_LEVELS)],
            'fields.*.creatable' => ['nullable', 'boolean'],
            'fields.*.label' => ['nullable', 'string', 'max:100'],
            'fields.*.description' => ['nullable', 'string', 'max:500'],
        ], [], [
            'fields.*.label' => 'label',
            'fields.*.description' => 'note for the AI app',
        ]);

        if (! $this->settingsStore->ready()) {
            return $this->backToThePage('Not saved: the database update has not been run yet.');
        }

        $input = (array) $request->input('fields', []);
        $choices = [];

        foreach ($this->fieldResolver->baseline()->all() as $name => $field) {
            if (is_array($input[$name] ?? null)) {
                $choices[$name] = $this->choicesFor($field, $input[$name]);
            }
        }

        $this->settingsStore->replace($choices);

        return $this->backToThePage('Saved. The change applies right away.');
    }

    public function reset(): RedirectResponse
    {
        if ($this->settingsStore->ready()) {
            $this->settingsStore->reset();
        }

        return $this->backToThePage('Back to the defaults.');
    }

    /**
     * Only what differs from the config file is kept, and only switches that can move.
     *
     * @param  array<string, mixed>  $input
     * @return array{readable?: bool, writable?: bool, creatable?: bool, details_off?: list<string>, grow_off?: list<string>, label?: string, description?: string}
     */
    protected function choicesFor(Field $field, array $input): array
    {
        $locks = $this->savedSettings->locks($field);
        $access = $input['access'] ?? null;
        $choices = [];

        if ($access === 'off' && $locks['hide'] === null) {
            $choices['readable'] = false;
        } elseif ($access === 'read' && $locks['change'] === null) {
            $choices['writable'] = false;
        }

        if ($field->isRelation() && $locks['create'] === null && array_key_exists('creatable', $input)) {
            $creatable = filter_var($input['creatable'], FILTER_VALIDATE_BOOLEAN);

            if ($creatable !== (bool) ($field->relation['creatable'] ?? false)) {
                $choices['creatable'] = $creatable;
            }
        }

        $offered = array_keys($field->relation['details'] ?? []);

        if ($offered !== [] && $locks['create'] === null && is_array($input['details'] ?? null)) {
            $off = array_values(array_filter(
                $offered,
                fn (string $detail): bool => array_key_exists($detail, $input['details'])
                    && ! filter_var($input['details'][$detail], FILTER_VALIDATE_BOOLEAN),
            ));

            if ($off !== []) {
                $choices['details_off'] = $off;
            }
        }

        $growing = array_keys(array_filter($field->relation['details'] ?? [], fn (array $detail): bool => $detail['grow']));

        if ($growing !== [] && $locks['create'] === null && is_array($input['grow'] ?? null)) {
            $stopped = array_values(array_filter(
                $growing,
                fn (string $detail): bool => array_key_exists($detail, $input['grow'])
                    && ! filter_var($input['grow'][$detail], FILTER_VALIDATE_BOOLEAN),
            ));

            if ($stopped !== []) {
                $choices['grow_off'] = $stopped;
            }
        }

        foreach (['label', 'description'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '' && $value !== $field->{$key}) {
                $choices[$key] = $value;
            }
        }

        return $choices;
    }

    /**
     * Every part of a linked item (a project's name, description, links...) and whether the AI app fills it in.
     * Secret and hidden columns are left out.
     *
     * @return list<array{name: string, label: string, type: string, on: bool, list: bool, grow: bool, grow_lock: string|null, lock: string|null}>
     */
    protected function partsOf(Field $baseline, Field $now, ?string $createLock): array
    {
        $offered = $baseline->relation['details'] ?? [];
        $chosen = $now->relation['details'] ?? [];
        $labelColumn = $baseline->relation['label'] ?? null;
        $parts = [];

        foreach ($this->fieldResolver->relatedColumns($baseline) as $name => $column) {
            $isDetail = isset($offered[$name]);

            if (! $column->readable) {
                continue;
            }

            $parts[] = [
                'name' => $name,
                'label' => $column->label,
                'type' => match (true) {
                    $isDetail && $offered[$name]['type'] === 'image' => 'picture',
                    $isDetail && $offered[$name]['type'] === Field::TYPE_ARRAY, $column->type === Field::TYPE_ARRAY => 'list',
                    default => $column->type,
                },
                'on' => $name === $labelColumn || isset($chosen[$name]),
                'list' => $isDetail && $offered[$name]['type'] === Field::TYPE_ARRAY,
                'grow' => (bool) ($chosen[$name]['grow'] ?? false),
                'grow_lock' => $isDetail && ! $offered[$name]['grow'] ? 'Your developer keeps this list as it is.' : $createLock,
                'lock' => match (true) {
                    $name === $labelColumn => 'Used to find it.',
                    ! $isDetail => 'Turned off by your developer.',
                    default => $createLock,
                },
            ];
        }

        usort($parts, fn (array $a, array $b): int => [$a['name'] !== $labelColumn, $a['lock'] !== null && $a['name'] !== $labelColumn] <=> [$b['name'] !== $labelColumn, $b['lock'] !== null && $b['name'] !== $labelColumn]);

        return $parts;
    }

    protected function accessOf(Field $field): string
    {
        return match (true) {
            ! $field->readable => 'off',
            ! $field->writable => 'read',
            default => 'edit',
        };
    }

    protected function backToThePage(string $status): RedirectResponse
    {
        return redirect()->to($this->postMcp->settingsPageUrl())->with('post-mcp.status', $status);
    }
}
