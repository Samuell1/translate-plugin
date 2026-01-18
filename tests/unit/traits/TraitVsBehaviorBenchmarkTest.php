<?php namespace RainLab\Translate\Tests\Unit\Traits;

use Model;
use Schema;
use PluginTestCase;
use RainLab\Translate\Classes\Translator;
use RainLab\Translate\Tests\Fixtures\Models\Country as BehaviorModel;
use RainLab\Translate\Tests\Fixtures\Models\CountryTrait as TraitModel;
use RainLab\Translate\Classes\Locale as LocaleModel;

/**
 * TraitVsBehaviorBenchmarkTest compares performance of Translatable trait vs TranslatableModel behavior.
 *
 * Run with: php artisan test --filter=TraitVsBehaviorBenchmarkTest
 */
class TraitVsBehaviorBenchmarkTest extends PluginTestCase
{
    protected int $iterations = 100;
    protected int $recordCount = 50;

    public function setUp(): void
    {
        parent::setUp();
        $this->seedTestData();
        \DB::enableQueryLog();
    }

    protected function seedTestData(): void
    {
        if (!Schema::hasTable('translate_test_countries')) {
            Schema::create('translate_test_countries', function ($table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name')->nullable();
                $table->string('code')->nullable();
                $table->text('states')->nullable();
                $table->timestamps();
            });
        }

        LocaleModel::firstOrCreate(['code' => 'en', 'name' => 'English', 'is_enabled' => 1]);
        LocaleModel::firstOrCreate(['code' => 'fr', 'name' => 'French', 'is_enabled' => 1]);

        if (TraitModel::count() >= $this->recordCount) {
            return;
        }

        Model::unguard();
        TraitModel::truncate();
        BehaviorModel::truncate();

        for ($i = 1; $i <= $this->recordCount; $i++) {
            // Create with trait
            $traitModel = TraitModel::create([
                'name' => "Country {$i}",
                'code' => "C{$i}",
            ]);
            $traitModel->translateContext('fr');
            $traitModel->name = "Pays {$i}";
            $traitModel->save();

            // Create with behavior
            $behaviorModel = BehaviorModel::create([
                'name' => "Country {$i}",
                'code' => "C{$i}",
            ]);
            $behaviorModel->translateContext('fr');
            $behaviorModel->name = "Pays {$i}";
            $behaviorModel->save();
        }

        Model::reguard();
    }

    /**
     * Benchmark: Model instantiation
     */
    public function testBenchInstantiation(): void
    {
        // Warm up
        new TraitModel();
        new BehaviorModel();

        // Trait
        $traitStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $model = new TraitModel();
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;

        // Behavior
        $behaviorStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $model = new BehaviorModel();
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;

        $this->outputBenchmark('Model Instantiation', $traitTime, $behaviorTime);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Fetch all records (no translation)
     */
    public function testBenchFetchAllDefaultLocale(): void
    {
        Translator::instance()->setLocale('en');

        // Trait
        \DB::flushQueryLog();
        $traitStart = hrtime(true);
        $traitModels = TraitModel::all();
        foreach ($traitModels as $model) {
            $name = $model->name;
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;
        $traitQueries = count(\DB::getQueryLog());

        // Behavior
        \DB::flushQueryLog();
        $behaviorStart = hrtime(true);
        $behaviorModels = BehaviorModel::all();
        foreach ($behaviorModels as $model) {
            $name = $model->name;
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;
        $behaviorQueries = count(\DB::getQueryLog());

        $this->outputBenchmark('Fetch All (default locale)', $traitTime, $behaviorTime, $traitQueries, $behaviorQueries);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Fetch all records with translation (lazy loading - N+1)
     */
    public function testBenchFetchAllTranslatedLazy(): void
    {
        Translator::instance()->setLocale('fr');

        // Trait
        \DB::flushQueryLog();
        $traitStart = hrtime(true);
        $traitModels = TraitModel::all();
        foreach ($traitModels as $model) {
            $name = $model->name;
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;
        $traitQueries = count(\DB::getQueryLog());

        // Behavior
        \DB::flushQueryLog();
        $behaviorStart = hrtime(true);
        $behaviorModels = BehaviorModel::all();
        foreach ($behaviorModels as $model) {
            $name = $model->name;
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;
        $behaviorQueries = count(\DB::getQueryLog());

        Translator::instance()->setLocale('en');

        $this->outputBenchmark('Fetch All Translated (lazy N+1)', $traitTime, $behaviorTime, $traitQueries, $behaviorQueries);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Fetch all records with eager loading
     */
    public function testBenchFetchAllTranslatedEager(): void
    {
        Translator::instance()->setLocale('fr');

        // Trait - using withTranslation scope
        \DB::flushQueryLog();
        $traitStart = hrtime(true);
        $traitModels = TraitModel::withTranslation()->get();
        foreach ($traitModels as $model) {
            $name = $model->name;
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;
        $traitQueries = count(\DB::getQueryLog());

        // Behavior - using with('translations')
        \DB::flushQueryLog();
        $behaviorStart = hrtime(true);
        $behaviorModels = BehaviorModel::with('translations')->get();
        foreach ($behaviorModels as $model) {
            $name = $model->name;
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;
        $behaviorQueries = count(\DB::getQueryLog());

        Translator::instance()->setLocale('en');

        $this->outputBenchmark('Fetch All Translated (eager)', $traitTime, $behaviorTime, $traitQueries, $behaviorQueries);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Set and save translation
     */
    public function testBenchSetTranslation(): void
    {
        Translator::instance()->setLocale('fr');

        $traitModel = TraitModel::first();
        $behaviorModel = BehaviorModel::first();

        // Trait
        $traitStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $traitModel->name = "Nouveau Nom {$i}";
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;

        // Behavior
        $behaviorStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $behaviorModel->name = "Nouveau Nom {$i}";
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;

        Translator::instance()->setLocale('en');

        $this->outputBenchmark('Set Translation (x' . $this->iterations . ')', $traitTime, $behaviorTime);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Get translation
     */
    public function testBenchGetTranslation(): void
    {
        Translator::instance()->setLocale('fr');

        $traitModel = TraitModel::with('translations')->first();
        $behaviorModel = BehaviorModel::with('translations')->first();

        // Trait
        $traitStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $name = $traitModel->name;
        }
        $traitTime = (hrtime(true) - $traitStart) / 1e6;

        // Behavior
        $behaviorStart = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $name = $behaviorModel->name;
        }
        $behaviorTime = (hrtime(true) - $behaviorStart) / 1e6;

        Translator::instance()->setLocale('en');

        $this->outputBenchmark('Get Translation (x' . $this->iterations . ')', $traitTime, $behaviorTime);
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Memory usage
     */
    public function testBenchMemoryUsage(): void
    {
        Translator::instance()->setLocale('fr');

        // Trait
        gc_collect_cycles();
        $traitMemStart = memory_get_usage(true);
        $traitModels = TraitModel::with('translations')->get();
        foreach ($traitModels as $model) {
            $name = $model->name;
        }
        $traitMemEnd = memory_get_usage(true);
        $traitMem = ($traitMemEnd - $traitMemStart) / 1024;
        unset($traitModels);

        // Behavior
        gc_collect_cycles();
        $behaviorMemStart = memory_get_usage(true);
        $behaviorModels = BehaviorModel::with('translations')->get();
        foreach ($behaviorModels as $model) {
            $name = $model->name;
        }
        $behaviorMemEnd = memory_get_usage(true);
        $behaviorMem = ($behaviorMemEnd - $behaviorMemStart) / 1024;
        unset($behaviorModels);

        Translator::instance()->setLocale('en');

        $diff = $behaviorMem - $traitMem;
        $diffPct = $behaviorMem > 0 ? (($diff / $behaviorMem) * 100) : 0;

        fwrite(STDERR, sprintf(
            "\n[MEMORY] Trait: %.2f KB | Behavior: %.2f KB | Diff: %.2f KB (%.1f%%)\n",
            $traitMem,
            $behaviorMem,
            $diff,
            $diffPct
        ));

        $this->assertTrue(true);
    }

    /**
     * Output benchmark comparison
     */
    protected function outputBenchmark(string $name, float $traitTime, float $behaviorTime, ?int $traitQueries = null, ?int $behaviorQueries = null): void
    {
        $diff = $behaviorTime - $traitTime;
        $diffPct = $behaviorTime > 0 ? (($diff / $behaviorTime) * 100) : 0;
        $winner = $traitTime < $behaviorTime ? 'TRAIT' : 'BEHAVIOR';

        $queryInfo = '';
        if ($traitQueries !== null && $behaviorQueries !== null) {
            $queryInfo = sprintf(' | Queries: %d vs %d', $traitQueries, $behaviorQueries);
        }

        fwrite(STDERR, sprintf(
            "\n[BENCH] %s: Trait=%.2fms | Behavior=%.2fms | Diff=%.2fms (%.1f%%) | Winner: %s%s",
            $name,
            $traitTime,
            $behaviorTime,
            $diff,
            $diffPct,
            $winner,
            $queryInfo
        ));
    }
}
