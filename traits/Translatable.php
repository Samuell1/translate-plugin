<?php namespace RainLab\Translate\Traits;

use Db;
use Str;
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
     * @var string Active language for translations.
     */
    protected $translatableContext;

    /**
     * @var string Default system language.
     */
    protected $translatableDefault;

    /**
     * @var bool Determines if empty translations should be replaced by default values.
     */
    protected $translatableUseFallback = true;

    /**
     * @var array Data store for translated attributes.
     */
    protected $translatableAttributes = [];

    /**
     * @var array Data store for original translated attributes.
     */
    protected $translatableOriginals = [];

    /**
     * bootTranslatable boots the translatable trait for a model.
     */
    public static function bootTranslatable()
    {
        static::extend(function ($model) {
            // Add the translations morphMany relationship
            $model->morphMany['translations'] = [
                \RainLab\Translate\Models\Attribute::class,
                'name' => 'model'
            ];
        });

        // Clean up indexes when this model is deleted
        static::deleted(function ($model) {
            Db::table('rainlab_translate_attributes')
                ->where('model_id', $model->getKey())
                ->where('model_type', get_class($model))
                ->delete();

            Db::table('rainlab_translate_indexes')
                ->where('model_id', $model->getKey())
                ->where('model_type', get_class($model))
                ->delete();
        });
    }

    /**
     * initializeTranslatable initializes the translatable trait for a model instance.
     */
    public function initializeTranslatable()
    {
        $this->initTranslatableContext();

        // Replace file attachments with translatable ones
        $this->extendTranslatableFileModels('attachOne');
        $this->extendTranslatableFileModels('attachMany');

        // Bind instance-level events
        $this->bindEvent('model.saveInternal', [$this, 'syncTranslatableAttributes']);

        $this->bindEvent('model.beforeGetAttribute', function ($key) {
            if ($this->isTranslatable($key)) {
                $value = $this->getAttributeTranslated($key);
                if ($this->hasGetMutator($key)) {
                    $method = 'get' . Str::studly($key) . 'Attribute';
                    $value = $this->{$method}($value);
                }
                return $value;
            }
        });

        $this->bindEvent('model.beforeSetAttribute', function ($key, $value) {
            if ($this->isTranslatable($key)) {
                $value = $this->setAttributeTranslated($key, $value);
                if ($this->hasSetMutator($key)) {
                    $method = 'set' . Str::studly($key) . 'Attribute';
                    $value = $this->{$method}($value);
                }
                return $value;
            }
        });
    }

    /**
     * initTranslatableContext initializes this class, sets the default language code to use.
     */
    public function initTranslatableContext()
    {
        $translate = Translator::instance();
        $this->translatableContext = $translate->getLocale();
        $this->translatableDefault = $translate->getDefaultLocale();
    }

    /**
     * extendTranslatableFileModels will swap the standard File model with MLFile instead
     */
    protected function extendTranslatableFileModels(string $relationGroup)
    {
        if (!isset($this->$relationGroup) || !is_array($this->$relationGroup)) {
            return;
        }

        foreach ($this->$relationGroup as $relationName => $relationObj) {
            $relationClass = is_array($relationObj) ? $relationObj[0] : $relationObj;

            // Custom implementation
            if ($relationClass !== \System\Models\File::class) {
                continue;
            }

            // Normalize definition
            if (!is_array($relationObj)) {
                $relationObj = (array) $relationObj;
            }

            // Translatable individual file models
            if (in_array($relationName, $this->getTranslatableAttributes())) {
                $relationObj['relationClass'] = $relationGroup === 'attachOne'
                    ? \RainLab\Translate\Classes\Relations\MLAttachOne::class
                    : \RainLab\Translate\Classes\Relations\MLAttachMany::class;
            }
            // Translate file models attributes only
            else {
                $relationObj[0] = \RainLab\Translate\Models\MLFile::class;
            }

            $this->$relationGroup[$relationName] = $relationObj;
        }
    }

    /**
     * shouldTranslate determines if the context is applying translated values
     */
    public function shouldTranslate()
    {
        return $this->translatableContext !== $this->translatableDefault;
    }

    /**
     * isTranslatable checks if an attribute should be translated or not.
     */
    public function isTranslatable($key)
    {
        if ($key === 'translatable' || !$this->shouldTranslate()) {
            return false;
        }

        return in_array($key, $this->getTranslatableAttributes());
    }

    /**
     * noFallbackLocale disables translation fallback locale.
     * @return self
     */
    public function noFallbackLocale()
    {
        $this->translatableUseFallback = false;

        return $this;
    }

    /**
     * withFallbackLocale enables translation fallback locale.
     * @return self
     */
    public function withFallbackLocale()
    {
        $this->translatableUseFallback = true;

        return $this;
    }

    /**
     * getAttributeTranslated returns a translated attribute value.
     *
     * The base value must come from 'attributes' on the model otherwise the process
     * can possibly loop back to this event, then method triggered by __get() magic.
     */
    public function getAttributeTranslated($key, $locale = null)
    {
        if ($locale == null) {
            $locale = $this->translatableContext;
        }

        // Result should not return NULL to successfully hook beforeGetAttribute event
        $result = '';

        // Default locale
        if ($locale == $this->translatableDefault) {
            $result = $this->getTranslatableAttributeFromData($this->attributes, $key);
        }
        // Other locale
        else {
            if (!array_key_exists($locale, $this->translatableAttributes)) {
                $this->loadTranslatableData($locale);
            }

            if ($this->hasTranslation($key, $locale)) {
                $result = $this->getTranslatableAttributeFromData($this->translatableAttributes[$locale], $key);
            }
            elseif ($this->translatableUseFallback) {
                $result = $this->getTranslatableAttributeFromData($this->attributes, $key);
            }
        }

        // Handle jsonable attributes, default locale may return the value as a string
        if (
            is_string($result) &&
            method_exists($this, 'isJsonable') &&
            $this->isJsonable($key)
        ) {
            $result = json_decode($result, true);
        }

        return $result;
    }

    /**
     * getTranslateAttributes returns all translated attribute values.
     */
    public function getTranslateAttributes($locale)
    {
        if (!array_key_exists($locale, $this->translatableAttributes)) {
            $this->loadTranslatableData($locale);
        }

        return array_get($this->translatableAttributes, $locale, []);
    }

    /**
     * hasTranslation returns whether the attribute is translatable (has a translation) for the given locale.
     */
    public function hasTranslation($key, $locale)
    {
        // If the default locale is passed, the attributes are retrieved from the model,
        // otherwise fetch the attributes from the $translatableAttributes property
        if ($locale == $this->translatableDefault) {
            $translatableAttributes = $this->attributes;
        }
        else {
            // Ensure that the translatableData has been loaded
            if (!isset($this->translatableAttributes[$locale])) {
                $this->loadTranslatableData($locale);
            }

            $translatableAttributes = $this->translatableAttributes[$locale];
        }

        $value = $this->getTranslatableAttributeFromData($translatableAttributes, $key);

        // Checkboxes can use zero values
        return !!$value || $value === 0 || $value === '0';
    }

    /**
     * setAttributeTranslated sets a translated attribute value.
     */
    public function setAttributeTranslated($key, $value, $locale = null)
    {
        if ($locale == null) {
            $locale = $this->translatableContext;
        }

        if ($locale == $this->translatableDefault) {
            return $this->setTranslatableAttributeFromData($this->attributes, $key, $value);
        }

        if (!array_key_exists($locale, $this->translatableAttributes)) {
            $this->loadTranslatableData($locale);
        }

        return $this->setTranslatableAttributeFromData($this->translatableAttributes[$locale], $key, $value);
    }

    /**
     * syncTranslatableAttributes restores the default language values on the model and
     * stores the translated values in the attributes table.
     */
    public function syncTranslatableAttributes()
    {
        // Spin through the known locales, store the translations if necessary
        $knownLocales = array_keys($this->translatableAttributes);
        foreach ($knownLocales as $locale) {
            if (!$this->isTranslateDirty(null, $locale)) {
                continue;
            }

            $this->storeTranslatableData($locale);
        }

        // Saving the default locale, no need to restore anything
        if (!$this->shouldTranslate()) {
            return;
        }

        // Restore translatable values to models originals
        $original = $this->getOriginal();
        $attributes = $this->getAttributes();
        $translatable = $this->getTranslatableAttributes();
        $originalValues = array_intersect_key($original, array_flip($translatable));
        $this->attributes = array_merge($attributes, $originalValues);
    }

    /**
     * translateContext changes the active language for this model
     */
    public function translateContext($context = null)
    {
        if ($context === null) {
            return $this->translatableContext;
        }

        if ($context !== null) {
            $this->reloadTranslatableRelations();
        }

        $this->translatableContext = $context;
    }

    /**
     * lang is a shorthand for translateContext method, and chainable.
     * @return self
     */
    public function lang($context = null)
    {
        $this->translateContext($context);

        return $this;
    }

    /**
     * reloadTranslatableRelations reloads relations when the context changes
     */
    public function reloadTranslatableRelations()
    {
        $loadedRelations = $this->getRelations();
        if (!$loadedRelations) {
            return;
        }

        foreach ($loadedRelations as $relationName => $value) {
            if (in_array($relationName, $this->getTranslatableAttributes())) {
                $this->reloadRelations($relationName);
            }
        }
    }

    /**
     * hasTranslatableAttributes checks if this model has translatable attributes.
     */
    public function hasTranslatableAttributes()
    {
        return is_array($this->translatable) &&
            count($this->translatable) > 0;
    }

    /**
     * getTranslatableAttributes returns a collection of fields that will be hashed.
     */
    public function getTranslatableAttributes()
    {
        $translatable = [];

        if (!is_array($this->translatable)) {
            return [];
        }

        foreach ($this->translatable as $attribute) {
            $translatable[] = is_array($attribute) ? array_shift($attribute) : $attribute;
        }

        return $translatable;
    }

    /**
     * getTranslatableAttributesWithOptions returns the defined options for a translatable attribute.
     */
    public function getTranslatableAttributesWithOptions()
    {
        $attributes = [];

        foreach ($this->translatable as $options) {
            if (!is_array($options)) {
                continue;
            }

            $attributeName = array_shift($options);

            $attributes[$attributeName] = $options;
        }

        return $attributes;
    }

    /**
     * isTranslateDirty determines if the model or a given translated attribute has been modified.
     */
    public function isTranslateDirty($attribute = null, $locale = null)
    {
        $dirty = $this->getTranslateDirty($locale);

        if (is_null($attribute)) {
            return count($dirty) > 0;
        }
        else {
            return array_key_exists($attribute, $dirty);
        }
    }

    /**
     * getDirtyLocales returns locales that have changed, if any
     */
    public function getDirtyLocales()
    {
        $dirtyLocales = [];
        $knownLocales = array_keys($this->translatableAttributes);
        foreach ($knownLocales as $locale) {
            if ($this->isTranslateDirty(null, $locale)) {
                $dirtyLocales[] = $locale;
            }
        }

        return $dirtyLocales;
    }

    /**
     * getTranslatableOriginals gets the original values of the translated attributes.
     */
    public function getTranslatableOriginals($locale = null)
    {
        if (!$locale) {
            return $this->translatableOriginals;
        }
        else {
            return $this->translatableOriginals[$locale] ?? null;
        }
    }

    /**
     * getTranslateDirty gets the translated attributes that have been changed since last sync.
     */
    public function getTranslateDirty($locale = null)
    {
        if (!$locale) {
            $locale = $this->translatableContext;
        }

        if (!array_key_exists($locale, $this->translatableAttributes)) {
            return [];
        }

        // All dirty
        if (!array_key_exists($locale, $this->translatableOriginals)) {
            return $this->translatableAttributes[$locale];
        }

        $dirty = [];
        foreach ($this->translatableAttributes[$locale] as $key => $value) {
            if (!array_key_exists($key, $this->translatableOriginals[$locale])) {
                $dirty[$key] = $value;
            }
            elseif ($value != $this->translatableOriginals[$locale][$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * scopeTransWhere applies a translatable index to a basic query.
     */
    public function scopeTransWhere($query, $index, $value, $locale = null, $operator = '=')
    {
        return $this->transWhereInternal($query, $index, $value, [
            'locale' => $locale,
            'operator' => $operator
        ]);
    }

    /**
     * scopeTransWhereNoFallback is identical to scopeTransWhere except it will not
     * use a fallback query when there are no indexes found.
     */
    public function scopeTransWhereNoFallback($query, $index, $value, $locale = null, $operator = '=')
    {
        // Ignore translatable indexes in default locale context
        if (($locale ?: $this->translatableContext) === $this->translatableDefault) {
            return $query->where($index, $operator, $value);
        }

        return $this->transWhereInternal($query, $index, $value, [
            'locale' => $locale,
            'operator' => $operator,
            'noFallback' => true
        ]);
    }

    /**
     * transWhereInternal
     */
    protected function transWhereInternal($query, $index, $value, $options = [])
    {
        extract(array_merge([
            'locale' => null,
            'operator' => '=',
            'noFallback' => false
        ], $options));

        if (!$locale) {
            $locale = $this->translatableContext;
        }

        // Separate query into two separate queries for improved performance
        $translateIndexes = Db::table('rainlab_translate_indexes')
            ->where('rainlab_translate_indexes.model_type', '=', $this->getTranslatableModelClass())
            ->where('rainlab_translate_indexes.locale', '=', $locale)
            ->where('rainlab_translate_indexes.item', $index)
            ->where('rainlab_translate_indexes.value', $operator, $value)
            ->pluck('model_id')
        ;

        if ($translateIndexes->count() || $noFallback) {
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
    public function scopeTransOrderBy($query, $index, $direction = 'asc', $locale = null)
    {
        if (!$locale) {
            $locale = $this->translatableContext;
        }
        $indexTableAlias = 'rainlab_translate_indexes_' . $index . '_' . $locale;

        $query->select(
            $this->getTable().'.*',
            Db::raw('COALESCE(' . $indexTableAlias . '.value, '. $this->getTable() .'.'.$index.') AS translate_sorting_key')
        );

        $query->orderBy('translate_sorting_key', $direction);

        $this->joinTranslateIndexesTable($query, $locale, $index, $indexTableAlias);

        return $query;
    }

    /**
     * joinTranslateIndexesTable joins the translatable indexes table to a query.
     */
    protected function joinTranslateIndexesTable($query, $locale, $index, $indexTableAlias)
    {
        $joinTableWithAlias = 'rainlab_translate_indexes as ' . $indexTableAlias;
        // check if table with same name and alias is already joined
        if (collect($query->getQuery()->joins)->contains('table', $joinTableWithAlias)) {
            return $query;
        }

        $query->leftJoin($joinTableWithAlias, function($join) use ($locale, $index, $indexTableAlias) {
            $join
                ->on(Db::raw(DbDongle::cast($this->getQualifiedKeyName(), 'TEXT')), '=', $indexTableAlias . '.model_id')
                ->where($indexTableAlias . '.model_type', '=', $this->getTranslatableModelClass())
                ->where($indexTableAlias . '.item', '=', $index)
                ->where($indexTableAlias . '.locale', '=', $locale);
        });

        return $query;
    }

    /**
     * storeTranslatableData saves the translation data in the join table.
     */
    protected function storeTranslatableData($locale = null)
    {
        if (!$locale) {
            $locale = $this->translatableContext;
        }

        // Model doesn't exist yet, defer this logic in memory
        if (!$this->exists) {
            $this->bindEventOnce('model.afterCreate', function() use ($locale) {
                $this->storeTranslatableData($locale);
            });

            return;
        }

        /**
         * @event model.translate.resolveComputedFields
         * Resolve computed fields before saving
         */
        $computedFields = $this->fireEvent('model.translate.resolveComputedFields', [$locale], true);
        if (is_array($computedFields)) {
            $this->translatableAttributes[$locale] = array_merge($this->translatableAttributes[$locale], $computedFields);
        }

        $this->storeTranslatableBasicData($locale);
        $this->storeTranslatableIndexData($locale);

        // Trigger event workflow
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }
    }

    /**
     * storeTranslatableBasicData saves the basic translation data in the join table.
     */
    protected function storeTranslatableBasicData($locale = null)
    {
        if (!$locale) {
            $locale = $this->translatableContext;
        }

        $data = (array) $this->translatableAttributes[$locale];

        $data = $this->getUniqueTranslatableData($locale, $data);

        $data = json_encode($data, JSON_UNESCAPED_UNICODE);

        $obj = Db::table('rainlab_translate_attributes')
            ->where('locale', $locale)
            ->where('model_id', $this->getKey())
            ->where('model_type', $this->getTranslatableModelClass());

        if ($obj->count() > 0) {
            $obj->update(['attribute_data' => $data]);
        }
        else {
            Db::table('rainlab_translate_attributes')->insert([
                'locale' => $locale,
                'model_id' => $this->getKey(),
                'model_type' => $this->getTranslatableModelClass(),
                'attribute_data' => $data
            ]);
        }
    }

    /**
     * getUniqueTranslatableData returns data that differs with the default locale
     */
    protected function getUniqueTranslatableData($locale, array $data): array
    {
        $originalContext = $this->translateContext();

        $originalAttrs = $this->attributes;

        $this->translateContext($locale);

        $this->forceFill($data);

        // Only include attributes that are different from the parent
        $includeAttrs = $this->getDirty();

        // Or attributes that are requested via [fallback => false]
        if ($optionedAttributes = $this->getTranslatableAttributesWithOptions()) {
            foreach ($optionedAttributes as $attribute => $options) {
                if (array_key_exists('fallback', $options) && $options['fallback'] === false) {
                    $includeAttrs[$attribute] = array_get($data, $attribute);
                }
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
    protected function storeTranslatableIndexData($locale = null)
    {
        $optionedAttributes = $this->getTranslatableAttributesWithOptions();
        if (!count($optionedAttributes)) {
            return;
        }

        $data = $this->translatableAttributes[$locale];

        foreach ($optionedAttributes as $attribute => $options) {
            if (!array_get($options, 'index', false)) {
                continue;
            }

            $value = array_get($data, $attribute);

            $obj = Db::table('rainlab_translate_indexes')
                ->where('locale', $locale)
                ->where('model_id', $this->getKey())
                ->where('model_type', $this->getTranslatableModelClass())
                ->where('item', $attribute);

            $recordExists = $obj->count() > 0;

            if (!strlen($value)) {
                if ($recordExists) {
                    $obj->delete();
                }
                continue;
            }

            if ($recordExists) {
                $obj->update(['value' => $value]);
            }
            else {
                Db::table('rainlab_translate_indexes')->insert([
                    'locale' => $locale,
                    'model_id' => $this->getKey(),
                    'model_type' => $this->getTranslatableModelClass(),
                    'item' => $attribute,
                    'value' => $value
                ]);
            }
        }
    }

    /**
     * loadTranslatableData loads the translation data from the join table.
     */
    protected function loadTranslatableData($locale = null)
    {
        if (!$locale) {
            $locale = $this->translatableContext;
        }

        if (!$this->exists) {
            return $this->translatableAttributes[$locale] = [];
        }

        $obj = $this->translations->first(function ($value, $key) use ($locale) {
            return $value->attributes['locale'] === $locale;
        });

        $result = (array) ($obj ? json_decode($obj->attribute_data, true) : []);

        return $this->translatableOriginals[$locale] = $this->translatableAttributes[$locale] = $result;
    }

    /**
     * getTranslatableAttributeFromData extracts an attribute from a model/array with nesting support.
     */
    protected function getTranslatableAttributeFromData($data, $attribute)
    {
        $keyArray = HtmlHelper::nameToArray($attribute);

        return array_get($data, implode('.', $keyArray));
    }

    /**
     * setTranslatableAttributeFromData sets an attribute from a model/array with nesting support.
     */
    protected function setTranslatableAttributeFromData(&$data, $attribute, $value)
    {
        $keyArray = HtmlHelper::nameToArray($attribute);

        array_set($data, implode('.', $keyArray), $value);

        return $value;
    }

    /**
     * getTranslatableModelClass returns the class name of the model. Takes any
     * custom morphMap aliases into account.
     */
    protected function getTranslatableModelClass()
    {
        return $this->getMorphClass();
    }
}
