<?php

namespace RainLab\Translate\Tests\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * TranslatableBench benchmarks translation loading strategies.
 *
 * Run with:
 *   vendor/bin/phpbench run tests/benchmarks --report=summary
 *   vendor/bin/phpbench run tests/benchmarks --report=aggregate
 *
 * Note: For full integration with October CMS models, run benchmarks
 * within the October CMS application context using the PluginTestCase approach.
 *
 * These benchmarks test the core translation array operations independent
 * of the database layer.
 */
#[Bench\BeforeMethods('setUp')]
class TranslatableBench
{
    private array $attributes = [];
    private array $translatableAttributes = [];
    private array $translations = [];
    private array $translatableConfig = [];
    private string $locale = 'fr';
    private string $defaultLocale = 'en';

    public function setUp(): void
    {
        // Simulate model with 20 attributes
        $this->attributes = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->attributes["field_{$i}"] = "Value {$i}";
        }

        // Simulate 5 translatable attributes
        $this->translatableConfig = [
            'name',
            'title',
            'description',
            ['slug', 'index' => true],
            ['content', 'index' => true, 'fallback' => false],
        ];

        // Pre-compute translatable attributes list (simulating once() cache)
        $this->translatableAttributes = ['name', 'title', 'description', 'slug', 'content'];

        // Simulate translations for 5 locales
        $locales = ['fr', 'de', 'es', 'it', 'pt'];
        foreach ($locales as $loc) {
            $this->translations[$loc] = [
                'name' => "Name ({$loc})",
                'title' => "Title ({$loc})",
                'description' => "Description ({$loc})",
                'slug' => "slug-{$loc}",
                'content' => "Content ({$loc})",
            ];
        }
    }

    /**
     * Benchmark: Check if attribute is translatable using in_array
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['attribute-check'])]
    public function benchIsTranslatableInArray(): void
    {
        $key = 'description';
        $result = in_array($key, $this->translatableAttributes);
    }

    /**
     * Benchmark: Check if attribute is translatable using isset on flipped array
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['attribute-check'])]
    public function benchIsTranslatableIsset(): void
    {
        static $flipped = null;
        if ($flipped === null) {
            $flipped = array_flip($this->translatableAttributes);
        }
        $key = 'description';
        $result = isset($flipped[$key]);
    }

    /**
     * Benchmark: Parse translatable config to get attribute names
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['config-parse'])]
    public function benchParseTranslatableConfig(): void
    {
        $translatable = [];
        foreach ($this->translatableConfig as $attribute) {
            $translatable[] = is_array($attribute)
                ? (array_key_first($attribute) ?? $attribute[0])
                : $attribute;
        }
    }

    /**
     * Benchmark: Parse translatable config with options
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['config-parse'])]
    public function benchParseTranslatableConfigWithOptions(): void
    {
        $attributes = [];
        foreach ($this->translatableConfig as $options) {
            if (!is_array($options)) {
                continue;
            }
            $attributeName = array_key_first($options) ?? array_shift($options);
            if (is_int($attributeName)) {
                $attributeName = array_shift($options);
            }
            $attributes[$attributeName] = $options;
        }
    }

    /**
     * Benchmark: Get translated attribute (cache hit)
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['translation-get'])]
    public function benchGetTranslatedAttributeCacheHit(): void
    {
        $key = 'name';
        $locale = $this->locale;

        // Simulating cache hit - translations already loaded
        if (isset($this->translations[$locale][$key])) {
            $result = $this->translations[$locale][$key];
        } else {
            $result = $this->attributes[$key] ?? null;
        }
    }

    /**
     * Benchmark: Get translated attribute with fallback
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['translation-get'])]
    public function benchGetTranslatedAttributeWithFallback(): void
    {
        $key = 'nonexistent';
        $locale = $this->locale;
        $useFallback = true;

        if (isset($this->translations[$locale][$key])) {
            $result = $this->translations[$locale][$key];
        } elseif ($useFallback) {
            $result = $this->attributes[$key] ?? null;
        } else {
            $result = '';
        }
    }

    /**
     * Benchmark: Set translated attribute
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['translation-set'])]
    public function benchSetTranslatedAttribute(): void
    {
        $key = 'name';
        $value = 'Nouveau Nom';
        $locale = $this->locale;

        $this->translations[$locale][$key] = $value;
    }

    /**
     * Benchmark: Check translation dirty state
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['dirty-check'])]
    public function benchCheckTranslationDirty(): void
    {
        $locale = $this->locale;
        $originals = $this->translations[$locale];
        $current = array_merge($this->translations[$locale], ['name' => 'Modified']);

        $dirty = [];
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $originals) || $value != $originals[$key]) {
                $dirty[$key] = $value;
            }
        }
    }

    /**
     * Benchmark: JSON encode translation data
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['json'])]
    public function benchJsonEncodeTranslations(): void
    {
        $data = $this->translations[$this->locale];
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Benchmark: JSON decode translation data
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['json'])]
    public function benchJsonDecodeTranslations(): void
    {
        $encoded = '{"name":"Name (fr)","title":"Title (fr)","description":"Description (fr)","slug":"slug-fr","content":"Content (fr)"}';
        $decoded = json_decode($encoded, true);
    }

    /**
     * Benchmark: Filter unique translation data (diff with original)
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['data-processing'])]
    public function benchFilterUniqueTranslationData(): void
    {
        $data = [
            'name' => 'Modified Name',
            'title' => $this->attributes['title'] ?? 'Title', // same as original
            'description' => 'Modified Description',
        ];

        $dirty = ['name' => true, 'description' => true];
        $filtered = array_intersect_key($data, $dirty);
    }

    /**
     * Benchmark: Simulate collection search for locale (like eager loaded translations)
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['collection'])]
    public function benchFindTranslationInCollection(): void
    {
        // Simulate array of translation objects
        $translations = [
            (object)['locale' => 'en', 'attribute_data' => '{}'],
            (object)['locale' => 'fr', 'attribute_data' => '{"name":"Nom"}'],
            (object)['locale' => 'de', 'attribute_data' => '{"name":"Name"}'],
            (object)['locale' => 'es', 'attribute_data' => '{"name":"Nombre"}'],
            (object)['locale' => 'it', 'attribute_data' => '{"name":"Nome"}'],
        ];

        $locale = 'fr';
        $found = null;
        foreach ($translations as $item) {
            if ($item->locale === $locale) {
                $found = $item;
                break;
            }
        }
    }

    /**
     * Benchmark: Array key lookup vs iteration (5 items)
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['collection'])]
    public function benchFindTranslationByKeyLookup(): void
    {
        // Pre-indexed by locale
        $translations = [
            'en' => (object)['locale' => 'en', 'attribute_data' => '{}'],
            'fr' => (object)['locale' => 'fr', 'attribute_data' => '{"name":"Nom"}'],
            'de' => (object)['locale' => 'de', 'attribute_data' => '{"name":"Name"}'],
            'es' => (object)['locale' => 'es', 'attribute_data' => '{"name":"Nombre"}'],
            'it' => (object)['locale' => 'it', 'attribute_data' => '{"name":"Nome"}'],
        ];

        $locale = 'fr';
        $found = $translations[$locale] ?? null;
    }
}
