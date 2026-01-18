<?php namespace RainLab\Translate\Traits;

use Arr;
use Db;
use DbDongle;
use RainLab\Translate\Classes\Translator;
use October\Rain\Html\Helper as HtmlHelper;

/**
 * Translatable model trait
 *
 * Usage:
 *
 * In the model class definition:
 *
 *   use \RainLab\Translate\Traits\Translatable;
 *
 *   public $translatable = ['name', 'content'];
 *
 */
trait Translatable
{
    /**
     * @var string|null Active language for translations.
     */
    protected ?string $translatableContext = null;

    /**
     * @var string|null Default system language.
     */
    protected ?string $translatableDefault = null;

    /**
     * @var bool Determines if empty translations should be replaced by default values.
     */
    protected bool $translatableUseFallback = true;

    /**
     * @var array Data store for translated attributes.
     */
    protected array $translatableAttributes = [];

    /**
     * @var array Data store for original translated attributes.
     */
    protected array $translatableOriginals = [];

    /**
     * bootTranslatable boots the translatable trait for a model.
     */
    public static function bootTranslatable(): void
    {
        static::extend(function ($model) {
            $model->morphMany['translations'] = [
                \RainLab\Translate\Models\Attribute::class,
                'name' => 'model'
            ];
        });

        static::deleted(function ($model) {
            $modelKey = $model->getKey();
            $modelType = get_class($model);

            Db::table('rainlab_translate_attributes')
                ->where('model_id', $modelKey)
                ->where('model_type', $modelType)
                ->delete();

            Db::table('rainlab_translate_indexes')
                ->where('model_id', $modelKey)
                ->where('model_type', $modelType)
                ->delete();
        });
    }

    /**
     * initializeTranslatable initializes the translatable trait for a model instance.
     */
    public function initializeTranslatable(): void
    {
        $this->initTranslatableContext();

        $this->extendTranslatableFileModels('attachOne');
        $this->extendTranslatableFileModels('attachMany');

        $this->bindEvent('model.saveInternal', [$this, 'syncTranslatableAttributes']);
    }

    /**
     * getAttribute overrides the parent method to return translated values.
     */
    public function getAttribute($key): mixed
    {
        if ($this->isTranslatable($key)) {
            return $this->getAttributeTranslated($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * setAttribute overrides the parent method to store translated values.
     */
    public function setAttribute($key, $value): mixed
    {
        if ($this->isTranslatable($key)) {
            $this->setAttributeTranslated($key, $value);
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * initTranslatableContext initializes this class, sets the default language code to use.
     */
    public function initTranslatableContext(): void
    {
        $translate = Translator::instance();
        $this->translatableContext = $translate->getLocale();
        $this->translatableDefault = $translate->getDefaultLocale();
    }

    /**
     * extendTranslatableFileModels will swap the standard File model with MLFile instead.
     */
    protected function extendTranslatableFileModels(string $relationGroup): void
    {
        if (!isset($this->$relationGroup) || !is_array($this->$relationGroup)) {
            return;
        }

        $translatableAttrs = $this->getTranslatableAttributes();

        foreach ($this->$relationGroup as $relationName => $relationObj) {
            $relationClass = is_array($relationObj) ? $relationObj[0] : $relationObj;

            if ($relationClass !== \System\Models\File::class) {
                continue;
            }

            if (!is_array($relationObj)) {
                $relationObj = (array) $relationObj;
            }

            if (in_array($relationName, $translatableAttrs)) {
                $relationObj['relationClass'] = $relationGroup === 'attachOne'
                    ? \RainLab\Translate\Classes\Relations\MLAttachOne::class
                    : \RainLab\Translate\Classes\Relations\MLAttachMany::class;
            }
            else {
                $relationObj[0] = \RainLab\Translate\Models\MLFile::class;
            }

            $this->$relationGroup[$relationName] = $relationObj;
        }
    }

    /**
     * shouldTranslate determines if the context is applying translated values.
     */
    public function shouldTranslate(): bool
    {
        return $this->translatableContext !== $this->translatableDefault;
    }

    /**
     * isTranslatable checks if an attribute should be translated or not.
     */
    public function isTranslatable(string $key): bool
    {
        if ($key === 'translatable' || !$this->shouldTranslate()) {
            return false;
        }

        return in_array($key, $this->getTranslatableAttributes());
    }

    /**
     * noFallbackLocale disables translation fallback locale.
     */
    public function noFallbackLocale(): static
    {
        $this->translatableUseFallback = false;

        return $this;
    }

    /**
     * withFallbackLocale enables translation fallback locale.
     */
    public function withFallbackLocale(): static
    {
        $this->translatableUseFallback = true;

        return $this;
    }

    /**
     * getAttributeTranslated returns a translated attribute value.
     */
    public function getAttributeTranslated(string $key, ?string $locale = null): mixed
    {
        $locale ??= $this->translatableContext;

        if ($locale === $this->translatableDefault) {
            return $this->getTranslatableAttributeFromData($this->attributes, $key);
        }

        if (!array_key_exists($locale, $this->translatableAttributes)) {
            $this->loadTranslatableData($locale);
        }

        if ($this->hasTranslation($key, $locale)) {
            $result = $this->getTranslatableAttributeFromData($this->translatableAttributes[$locale], $key);
        }
        elseif ($this->translatableUseFallback) {
            $result = $this->getTranslatableAttributeFromData($this->attributes, $key);
        }
        else {
            $result = '';
        }

        if (is_string($result) && method_exists($this, 'isJsonable') && $this->isJsonable($key)) {
            $result = json_decode($result, true);
        }

        return $result;
    }

    /**
     * getTranslateAttributes returns all translated attribute values.
     */
    public function getTranslateAttributes(string $locale): array
    {
        if (!array_key_exists($locale, $this->translatableAttributes)) {
            $this->loadTranslatableData($locale);
        }

        return $this->translatableAttributes[$locale] ?? [];
    }

    /**
     * hasTranslation returns whether the attribute has a translation for the given locale.
     */
    public function hasTranslation(string $key, string $locale): bool
    {
        if ($locale === $this->translatableDefault) {
            $translatableAttributes = $this->attributes;
        }
        else {
            if (!isset($this->translatableAttributes[$locale])) {
                $this->loadTranslatableData($locale);
            }
            $translatableAttributes = $this->translatableAttributes[$locale];
        }

        $value = $this->getTranslatableAttributeFromData($translatableAttributes, $key);

        return $value !== null && $value !== '' || $value === 0 || $value === '0';
    }

    /**
     * setAttributeTranslated sets a translated attribute value.
     */
    public function setAttributeTranslated(string $key, mixed $value, ?string $locale = null): mixed
    {
        $locale ??= $this->translatableContext;

        if ($locale === $this->translatableDefault) {
            return $this->setTranslatableAttributeFromData($this->attributes, $key, $value);
        }

        if (!array_key_exists($locale, $this->translatableAttributes)) {
            $this->loadTranslatableData($locale);
        }

        return $this->setTranslatableAttributeFromData($this->translatableAttributes[$locale], $key, $value);
    }

    /**
     * syncTranslatableAttributes restores the default language values on the model
     * and stores the translated values in the attributes table.
     */
    public function syncTranslatableAttributes(): void
    {
        foreach (array_keys($this->translatableAttributes) as $locale) {
            if ($this->isTranslateDirty(null, $locale)) {
                $this->storeTranslatableData($locale);
            }
        }

        if (!$this->shouldTranslate()) {
            return;
        }

        $original = $this->getOriginal();
        $translatable = $this->getTranslatableAttributes();
        $originalValues = array_intersect_key($original, array_flip($translatable));
        $this->attributes = array_merge($this->getAttributes(), $originalValues);
    }

    /**
     * translateContext changes the active language for this model.
     */
    public function translateContext(?string $context = null): ?string
    {
        if ($context === null) {
            return $this->translatableContext;
        }

        $this->reloadTranslatableRelations();
        $this->translatableContext = $context;

        return null;
    }

    /**
     * lang is a shorthand for translateContext method, and chainable.
     */
    public function lang(?string $context = null): static
    {
        $this->translateContext($context);

        return $this;
    }

    /**
     * reloadTranslatableRelations reloads relations when the context changes.
     */
    public function reloadTranslatableRelations(): void
    {
        $loadedRelations = $this->getRelations();
        if (empty($loadedRelations)) {
            return;
        }

        $translatableAttrs = $this->getTranslatableAttributes();
        foreach ($loadedRelations as $relationName => $value) {
            if (in_array($relationName, $translatableAttrs)) {
                $this->reloadRelations($relationName);
            }
        }
    }

    /**
     * hasTranslatableAttributes checks if this model has translatable attributes.
     */
    public function hasTranslatableAttributes(): bool
    {
        return is_array($this->translatable) && count($this->translatable) > 0;
    }

    /**
     * getTranslatableAttributes returns a collection of translatable field names.
     */
    public function getTranslatableAttributes(): array
    {
        return once(function () {
            if (!is_array($this->translatable)) {
                return [];
            }

            $translatable = [];
            foreach ($this->translatable as $attribute) {
                $translatable[] = is_array($attribute) ? array_key_first($attribute) ?? $attribute[0] : $attribute;
            }

            return $translatable;
        });
    }

    /**
     * getTranslatableAttributesWithOptions returns the defined options for translatable attributes.
     */
    public function getTranslatableAttributesWithOptions(): array
    {
        return once(function () {
            $attributes = [];

            foreach ($this->translatable as $options) {
                if (!is_array($options)) {
                    continue;
                }

                $attributeName = array_key_first($options) ?? array_shift($options);
                if (is_int($attributeName)) {
                    $attributeName = array_shift($options);
                }

                $attributes[$attributeName] = $options;
            }

            return $attributes;
        });
    }

    /**
     * isTranslateDirty determines if the model or a given translated attribute has been modified.
     */
    public function isTranslateDirty(?string $attribute = null, ?string $locale = null): bool
    {
        $dirty = $this->getTranslateDirty($locale);

        if ($attribute === null) {
            return count($dirty) > 0;
        }

        return array_key_exists($attribute, $dirty);
    }

    /**
     * getDirtyLocales returns locales that have changed, if any.
     */
    public function getDirtyLocales(): array
    {
        $dirtyLocales = [];

        foreach (array_keys($this->translatableAttributes) as $locale) {
            if ($this->isTranslateDirty(null, $locale)) {
                $dirtyLocales[] = $locale;
            }
        }

        return $dirtyLocales;
    }

    /**
     * getTranslatableOriginals gets the original values of the translated attributes.
     */
    public function getTranslatableOriginals(?string $locale = null): ?array
    {
        if ($locale === null) {
            return $this->translatableOriginals;
        }

        return $this->translatableOriginals[$locale] ?? null;
    }

    /**
     * getTranslateDirty gets the translated attributes that have been changed since last sync.
     */
    public function getTranslateDirty(?string $locale = null): array
    {
        $locale ??= $this->translatableContext;

        if (!array_key_exists($locale, $this->translatableAttributes)) {
            return [];
        }

        if (!array_key_exists($locale, $this->translatableOriginals)) {
            return $this->translatableAttributes[$locale];
        }

        $dirty = [];
        $originals = $this->translatableOriginals[$locale];

        foreach ($this->translatableAttributes[$locale] as $key => $value) {
            if (!array_key_exists($key, $originals) || $value != $originals[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * scopeTransWhere applies a translatable index to a basic query.
     */
    public function scopeTransWhere($query, string $index, mixed $value, ?string $locale = null, string $operator = '=')
    {
        return $this->transWhereInternal($query, $index, $value, $locale, $operator, false);
    }

    /**
     * scopeTransWhereNoFallback is identical to scopeTransWhere except it will not
     * use a fallback query when there are no indexes found.
     */
    public function scopeTransWhereNoFallback($query, string $index, mixed $value, ?string $locale = null, string $operator = '=')
    {
        $locale ??= $this->translatableContext;

        if ($locale === $this->translatableDefault) {
            return $query->where($index, $operator, $value);
        }

        return $this->transWhereInternal($query, $index, $value, $locale, $operator, true);
    }

    /**
     * transWhereInternal handles the internal logic for translation where queries.
     */
    protected function transWhereInternal($query, string $index, mixed $value, ?string $locale, string $operator, bool $noFallback)
    {
        $locale ??= $this->translatableContext;

        $translateIndexes = Db::table('rainlab_translate_indexes')
            ->where('model_type', $this->getTranslatableModelClass())
            ->where('locale', $locale)
            ->where('item', $index)
            ->where('value', $operator, $value)
            ->pluck('model_id');

        if ($translateIndexes->isNotEmpty() || $noFallback) {
            $query->whereIn($this->getQualifiedKeyName(), $translateIndexes);
        }
        else {
            $query->where($index, $operator, $value);
        }

        return $query;
    }

    /**
     * scopeTransOrderBy applies a sort operation with a translatable index to a basic query.
     */
    public function scopeTransOrderBy($query, string $index, string $direction = 'asc', ?string $locale = null)
    {
        $locale ??= $this->translatableContext;
        $indexTableAlias = 'rainlab_translate_indexes_' . $index . '_' . $locale;
        $table = $this->getTable();

        $query->select(
            $table . '.*',
            Db::raw("COALESCE({$indexTableAlias}.value, {$table}.{$index}) AS translate_sorting_key")
        );

        $query->orderBy('translate_sorting_key', $direction);

        $this->joinTranslateIndexesTable($query, $locale, $index, $indexTableAlias);

        return $query;
    }

    /**
     * joinTranslateIndexesTable joins the translatable indexes table to a query.
     */
    protected function joinTranslateIndexesTable($query, string $locale, string $index, string $indexTableAlias): void
    {
        $joinTableWithAlias = 'rainlab_translate_indexes as ' . $indexTableAlias;

        $joins = $query->getQuery()->joins ?? [];
        foreach ($joins as $join) {
            if ($join->table === $joinTableWithAlias) {
                return;
            }
        }

        $query->leftJoin($joinTableWithAlias, function ($join) use ($locale, $index, $indexTableAlias) {
            $join
                ->on(Db::raw(DbDongle::cast($this->getQualifiedKeyName(), 'TEXT')), '=', $indexTableAlias . '.model_id')
                ->where($indexTableAlias . '.model_type', '=', $this->getTranslatableModelClass())
                ->where($indexTableAlias . '.item', '=', $index)
                ->where($indexTableAlias . '.locale', '=', $locale);
        });
    }

    /**
     * storeTranslatableData saves the translation data in the join table.
     */
    protected function storeTranslatableData(?string $locale = null): void
    {
        $locale ??= $this->translatableContext;

        if (!$this->exists) {
            $this->bindEventOnce('model.afterCreate', function () use ($locale) {
                $this->storeTranslatableData($locale);
            });
            return;
        }

        $computedFields = $this->fireEvent('model.translate.resolveComputedFields', [$locale], true);
        if (is_array($computedFields)) {
            $this->translatableAttributes[$locale] = array_merge($this->translatableAttributes[$locale], $computedFields);
        }

        $this->storeTranslatableBasicData($locale);
        $this->storeTranslatableIndexData($locale);

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }
    }

    /**
     * storeTranslatableBasicData saves the basic translation data in the join table.
     */
    protected function storeTranslatableBasicData(string $locale): void
    {
        $data = $this->getUniqueTranslatableData($locale, (array) $this->translatableAttributes[$locale]);
        $encodedData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $modelKey = $this->getKey();
        $modelType = $this->getTranslatableModelClass();

        $exists = Db::table('rainlab_translate_attributes')
            ->where('locale', $locale)
            ->where('model_id', $modelKey)
            ->where('model_type', $modelType)
            ->exists();

        if ($exists) {
            Db::table('rainlab_translate_attributes')
                ->where('locale', $locale)
                ->where('model_id', $modelKey)
                ->where('model_type', $modelType)
                ->update(['attribute_data' => $encodedData]);
        }
        else {
            Db::table('rainlab_translate_attributes')->insert([
                'locale' => $locale,
                'model_id' => $modelKey,
                'model_type' => $modelType,
                'attribute_data' => $encodedData
            ]);
        }
    }

    /**
     * getUniqueTranslatableData returns data that differs with the default locale.
     */
    protected function getUniqueTranslatableData(string $locale, array $data): array
    {
        $originalContext = $this->translateContext();
        $originalAttrs = $this->attributes;

        $this->translateContext($locale);
        $this->forceFill($data);

        $includeAttrs = $this->getDirty();

        foreach ($this->getTranslatableAttributesWithOptions() as $attribute => $options) {
            if (($options['fallback'] ?? true) === false) {
                $includeAttrs[$attribute] = Arr::get($data, $attribute);
            }
        }

        $data = array_intersect_key($data, $includeAttrs);

        $this->translateContext($originalContext);
        $this->attributes = $originalAttrs;

        return $data;
    }

    /**
     * storeTranslatableIndexData saves the indexed translation data in the join table.
     */
    protected function storeTranslatableIndexData(string $locale): void
    {
        $optionedAttributes = $this->getTranslatableAttributesWithOptions();
        if (empty($optionedAttributes)) {
            return;
        }

        $data = $this->translatableAttributes[$locale];
        $modelKey = $this->getKey();
        $modelType = $this->getTranslatableModelClass();

        foreach ($optionedAttributes as $attribute => $options) {
            if (empty($options['index'])) {
                continue;
            }

            $value = Arr::get($data, $attribute);

            $exists = Db::table('rainlab_translate_indexes')
                ->where('locale', $locale)
                ->where('model_id', $modelKey)
                ->where('model_type', $modelType)
                ->where('item', $attribute)
                ->exists();

            if ($value === null || $value === '') {
                if ($exists) {
                    Db::table('rainlab_translate_indexes')
                        ->where('locale', $locale)
                        ->where('model_id', $modelKey)
                        ->where('model_type', $modelType)
                        ->where('item', $attribute)
                        ->delete();
                }
                continue;
            }

            if ($exists) {
                Db::table('rainlab_translate_indexes')
                    ->where('locale', $locale)
                    ->where('model_id', $modelKey)
                    ->where('model_type', $modelType)
                    ->where('item', $attribute)
                    ->update(['value' => $value]);
            }
            else {
                Db::table('rainlab_translate_indexes')->insert([
                    'locale' => $locale,
                    'model_id' => $modelKey,
                    'model_type' => $modelType,
                    'item' => $attribute,
                    'value' => $value
                ]);
            }
        }
    }

    /**
     * loadTranslatableData loads the translation data from the join table.
     */
    protected function loadTranslatableData(?string $locale = null): array
    {
        $locale ??= $this->translatableContext;

        if (!$this->exists) {
            return $this->translatableAttributes[$locale] = [];
        }

        $translation = $this->translations->first(fn ($item) => $item->locale === $locale);

        $result = $translation ? (array) json_decode($translation->attribute_data, true) : [];

        return $this->translatableOriginals[$locale] = $this->translatableAttributes[$locale] = $result;
    }

    /**
     * getTranslatableAttributeFromData extracts an attribute from a model/array with nesting support.
     */
    protected function getTranslatableAttributeFromData(array $data, string $attribute): mixed
    {
        $keyArray = HtmlHelper::nameToArray($attribute);

        return Arr::get($data, implode('.', $keyArray));
    }

    /**
     * setTranslatableAttributeFromData sets an attribute from a model/array with nesting support.
     */
    protected function setTranslatableAttributeFromData(array &$data, string $attribute, mixed $value): mixed
    {
        $keyArray = HtmlHelper::nameToArray($attribute);

        Arr::set($data, implode('.', $keyArray), $value);

        return $value;
    }

    /**
     * getTranslatableModelClass returns the class name of the model.
     */
    protected function getTranslatableModelClass(): string
    {
        return $this->getMorphClass();
    }
}
