<?php

namespace RainLab\Translate\Tests\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * TraitVsBehaviorBench compares trait vs behavior pattern performance.
 *
 * This benchmark simulates the overhead difference between:
 * - Trait: Direct method calls, no proxy layer
 * - Behavior: Extension system with __call magic method proxying
 *
 * Run with:
 *   vendor/bin/phpbench run tests/benchmarks/TraitVsBehaviorBench.php --report=aggregate
 */
#[Bench\BeforeMethods('setUp')]
class TraitVsBehaviorBench
{
    private array $traitModels = [];
    private array $behaviorModels = [];
    private int $modelCount = 100;

    public function setUp(): void
    {
        // Create 100 mock models for each type
        $this->traitModels = [];
        $this->behaviorModels = [];

        for ($i = 0; $i < $this->modelCount; $i++) {
            $this->traitModels[] = new MockTraitModel([
                'id' => $i + 1,
                'name' => "Model {$i}",
                'title' => "Title {$i}",
                'description' => "Description {$i}",
            ]);

            $this->behaviorModels[] = new MockBehaviorModel([
                'id' => $i + 1,
                'name' => "Model {$i}",
                'title' => "Title {$i}",
                'description' => "Description {$i}",
            ]);
        }

        // Simulate loaded translations
        foreach ($this->traitModels as $model) {
            $model->loadTranslations('fr');
        }
        foreach ($this->behaviorModels as $model) {
            $model->loadTranslations('fr');
        }
    }

    /**
     * Benchmark: Instantiate 100 models with trait
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['instantiation'])]
    public function benchInstantiateTraitModels(): void
    {
        $models = [];
        for ($i = 0; $i < $this->modelCount; $i++) {
            $models[] = new MockTraitModel([
                'id' => $i + 1,
                'name' => "Model {$i}",
            ]);
        }
    }

    /**
     * Benchmark: Instantiate 100 models with behavior
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['instantiation'])]
    public function benchInstantiateBehaviorModels(): void
    {
        $models = [];
        for ($i = 0; $i < $this->modelCount; $i++) {
            $models[] = new MockBehaviorModel([
                'id' => $i + 1,
                'name' => "Model {$i}",
            ]);
        }
    }

    /**
     * Benchmark: Get translated attribute from 100 models (trait)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['get-attribute'])]
    public function benchGetAttributeTrait(): void
    {
        foreach ($this->traitModels as $model) {
            $name = $model->getTranslatedAttribute('name');
        }
    }

    /**
     * Benchmark: Get translated attribute from 100 models (behavior)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['get-attribute'])]
    public function benchGetAttributeBehavior(): void
    {
        foreach ($this->behaviorModels as $model) {
            $name = $model->getTranslatedAttribute('name');
        }
    }

    /**
     * Benchmark: Set translated attribute on 100 models (trait)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['set-attribute'])]
    public function benchSetAttributeTrait(): void
    {
        foreach ($this->traitModels as $i => $model) {
            $model->setTranslatedAttribute('name', "New Name {$i}");
        }
    }

    /**
     * Benchmark: Set translated attribute on 100 models (behavior)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['set-attribute'])]
    public function benchSetAttributeBehavior(): void
    {
        foreach ($this->behaviorModels as $i => $model) {
            $model->setTranslatedAttribute('name', "New Name {$i}");
        }
    }

    /**
     * Benchmark: Check isTranslatable on 100 models (trait)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['is-translatable'])]
    public function benchIsTranslatableTrait(): void
    {
        foreach ($this->traitModels as $model) {
            $model->isTranslatable('name');
            $model->isTranslatable('code');
            $model->isTranslatable('description');
        }
    }

    /**
     * Benchmark: Check isTranslatable on 100 models (behavior)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['is-translatable'])]
    public function benchIsTranslatableBehavior(): void
    {
        foreach ($this->behaviorModels as $model) {
            $model->isTranslatable('name');
            $model->isTranslatable('code');
            $model->isTranslatable('description');
        }
    }

    /**
     * Benchmark: Full workflow - get multiple attributes from 100 models (trait)
     */
    #[Bench\Revs(50)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['full-workflow'])]
    public function benchFullWorkflowTrait(): void
    {
        foreach ($this->traitModels as $model) {
            $model->translateContext('fr');
            $name = $model->getTranslatedAttribute('name');
            $title = $model->getTranslatedAttribute('title');
            $desc = $model->getTranslatedAttribute('description');
        }
    }

    /**
     * Benchmark: Full workflow - get multiple attributes from 100 models (behavior)
     */
    #[Bench\Revs(50)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['full-workflow'])]
    public function benchFullWorkflowBehavior(): void
    {
        foreach ($this->behaviorModels as $model) {
            $model->translateContext('fr');
            $name = $model->getTranslatedAttribute('name');
            $title = $model->getTranslatedAttribute('title');
            $desc = $model->getTranslatedAttribute('description');
        }
    }

    /**
     * Benchmark: Dirty checking on 100 models (trait)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['dirty-check'])]
    public function benchDirtyCheckTrait(): void
    {
        foreach ($this->traitModels as $model) {
            $model->isTranslateDirty();
        }
    }

    /**
     * Benchmark: Dirty checking on 100 models (behavior)
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    #[Bench\Groups(['dirty-check'])]
    public function benchDirtyCheckBehavior(): void
    {
        foreach ($this->behaviorModels as $model) {
            $model->isTranslateDirty();
        }
    }
}

/**
 * Mock model using trait pattern (direct method calls)
 */
class MockTraitModel
{
    use MockTranslatableTrait;

    public array $translatable = ['name', 'title', 'description'];
    protected array $attributes = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->initializeTranslatable();
    }
}

/**
 * Mock model using behavior pattern (proxied through __call)
 */
class MockBehaviorModel
{
    protected array $attributes = [];
    protected ?MockTranslatableBehavior $behavior = null;
    public array $translatable = ['name', 'title', 'description'];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->behavior = new MockTranslatableBehavior($this);
    }

    /**
     * Simulate October CMS behavior extension via __call
     */
    public function __call(string $method, array $args): mixed
    {
        // Simulate the lookup overhead of behavior system
        if ($this->behavior && method_exists($this->behavior, $method)) {
            return $this->behavior->$method(...$args);
        }

        throw new \BadMethodCallException("Method {$method} not found");
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }
}

/**
 * Mock translatable trait (direct implementation)
 */
trait MockTranslatableTrait
{
    protected string $translatableContext = 'en';
    protected string $translatableDefault = 'en';
    protected array $translatableAttributes = [];
    protected array $translatableOriginals = [];

    public function initializeTranslatable(): void
    {
        $this->translatableContext = 'en';
        $this->translatableDefault = 'en';
    }

    public function translateContext(?string $context = null): ?string
    {
        if ($context === null) {
            return $this->translatableContext;
        }
        $this->translatableContext = $context;
        return null;
    }

    public function isTranslatable(string $key): bool
    {
        if ($this->translatableContext === $this->translatableDefault) {
            return false;
        }
        return in_array($key, $this->translatable);
    }

    public function getTranslatedAttribute(string $key): mixed
    {
        if ($this->translatableContext === $this->translatableDefault) {
            return $this->attributes[$key] ?? null;
        }

        return $this->translatableAttributes[$this->translatableContext][$key]
            ?? $this->attributes[$key]
            ?? null;
    }

    public function setTranslatedAttribute(string $key, mixed $value): void
    {
        if ($this->translatableContext === $this->translatableDefault) {
            $this->attributes[$key] = $value;
            return;
        }

        $this->translatableAttributes[$this->translatableContext][$key] = $value;
    }

    public function loadTranslations(string $locale): void
    {
        $this->translatableAttributes[$locale] = [
            'name' => "Name ({$locale})",
            'title' => "Title ({$locale})",
            'description' => "Description ({$locale})",
        ];
        $this->translatableOriginals[$locale] = $this->translatableAttributes[$locale];
    }

    public function isTranslateDirty(?string $locale = null): bool
    {
        $locale ??= $this->translatableContext;

        if (!isset($this->translatableAttributes[$locale])) {
            return false;
        }

        if (!isset($this->translatableOriginals[$locale])) {
            return count($this->translatableAttributes[$locale]) > 0;
        }

        foreach ($this->translatableAttributes[$locale] as $key => $value) {
            if (!isset($this->translatableOriginals[$locale][$key]) ||
                $value !== $this->translatableOriginals[$locale][$key]) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Mock translatable behavior (simulates extension pattern overhead)
 */
class MockTranslatableBehavior
{
    protected object $model;
    protected string $translatableContext = 'en';
    protected string $translatableDefault = 'en';
    protected array $translatableAttributes = [];
    protected array $translatableOriginals = [];

    public function __construct(object $model)
    {
        $this->model = $model;
    }

    public function translateContext(?string $context = null): ?string
    {
        if ($context === null) {
            return $this->translatableContext;
        }
        $this->translatableContext = $context;
        return null;
    }

    public function isTranslatable(string $key): bool
    {
        if ($this->translatableContext === $this->translatableDefault) {
            return false;
        }
        return in_array($key, $this->model->translatable);
    }

    public function getTranslatedAttribute(string $key): mixed
    {
        $attributes = $this->model->getAttributes();

        if ($this->translatableContext === $this->translatableDefault) {
            return $attributes[$key] ?? null;
        }

        return $this->translatableAttributes[$this->translatableContext][$key]
            ?? $attributes[$key]
            ?? null;
    }

    public function setTranslatedAttribute(string $key, mixed $value): void
    {
        $this->translatableAttributes[$this->translatableContext][$key] = $value;
    }

    public function loadTranslations(string $locale): void
    {
        $this->translatableAttributes[$locale] = [
            'name' => "Name ({$locale})",
            'title' => "Title ({$locale})",
            'description' => "Description ({$locale})",
        ];
        $this->translatableOriginals[$locale] = $this->translatableAttributes[$locale];
    }

    public function isTranslateDirty(?string $locale = null): bool
    {
        $locale ??= $this->translatableContext;

        if (!isset($this->translatableAttributes[$locale])) {
            return false;
        }

        if (!isset($this->translatableOriginals[$locale])) {
            return count($this->translatableAttributes[$locale]) > 0;
        }

        foreach ($this->translatableAttributes[$locale] as $key => $value) {
            if (!isset($this->translatableOriginals[$locale][$key]) ||
                $value !== $this->translatableOriginals[$locale][$key]) {
                return true;
            }
        }

        return false;
    }
}
