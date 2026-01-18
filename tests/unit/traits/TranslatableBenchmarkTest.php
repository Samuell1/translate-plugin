<?php namespace RainLab\Translate\Tests\Unit\Traits;

use Model;
use Schema;
use PluginTestCase;
use RainLab\Translate\Classes\Translator;
use RainLab\Translate\Tests\Fixtures\Models\CountryTrait as CountryModel;
use RainLab\Translate\Classes\Locale as LocaleModel;

/**
 * TranslatableBenchmarkTest measures performance of different translation loading strategies.
 *
 * Run with: php artisan test --filter=TranslatableBenchmarkTest
 */
class TranslatableBenchmarkTest extends PluginTestCase
{
    protected int $recordCount = 100;
    protected array $locales = ['en', 'fr', 'de', 'es', 'it'];

    public function setUp(): void
    {
        parent::setUp();
        $this->seedBenchmarkData();
    }

    protected function seedBenchmarkData(): void
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

        // Ensure locales exist
        foreach ($this->locales as $locale) {
            LocaleModel::firstOrCreate([
                'code' => $locale,
                'name' => ucfirst($locale),
                'is_enabled' => 1
            ]);
        }

        // Skip if data exists
        if (CountryModel::count() >= $this->recordCount) {
            return;
        }

        Model::unguard();
        CountryModel::truncate();

        // Create test records with translations
        for ($i = 1; $i <= $this->recordCount; $i++) {
            $country = CountryModel::create([
                'name' => "Country {$i}",
                'code' => "C{$i}",
                'states' => ['State A', 'State B'],
            ]);

            // Add translations for each locale
            foreach ($this->locales as $locale) {
                if ($locale === 'en') continue;

                $country->translateContext($locale);
                $country->name = "Country {$i} ({$locale})";
                $country->save();
            }

            $country->translateContext('en');
        }

        Model::reguard();
    }

    /**
     * Benchmark: N+1 queries (lazy loading - worst case)
     */
    public function testBenchmarkLazyLoading(): void
    {
        Translator::instance()->disableAutoloadTranslations();
        Translator::instance()->setLocale('fr');

        $startTime = microtime(true);
        $startQueries = $this->getQueryCount();

        $countries = CountryModel::withoutAutoloadTranslations()->get();

        // Access translated attribute on each model (triggers N+1)
        foreach ($countries as $country) {
            $name = $country->name;
        }

        $endTime = microtime(true);
        $endQueries = $this->getQueryCount();

        $this->recordBenchmark('Lazy Loading (N+1)', [
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'queries' => $endQueries - $startQueries,
            'records' => $this->recordCount,
        ]);

        Translator::instance()->setLocale('en');
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Eager load all translations
     */
    public function testBenchmarkEagerLoadAll(): void
    {
        Translator::instance()->setLocale('fr');

        $startTime = microtime(true);
        $startQueries = $this->getQueryCount();

        $countries = CountryModel::withTranslations()->get();

        foreach ($countries as $country) {
            $name = $country->name;
        }

        $endTime = microtime(true);
        $endQueries = $this->getQueryCount();

        $this->recordBenchmark('Eager Load All (withTranslations)', [
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'queries' => $endQueries - $startQueries,
            'records' => $this->recordCount,
        ]);

        Translator::instance()->setLocale('en');
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Eager load current locale only
     */
    public function testBenchmarkEagerLoadCurrentLocale(): void
    {
        Translator::instance()->setLocale('fr');

        $startTime = microtime(true);
        $startQueries = $this->getQueryCount();

        $countries = CountryModel::withTranslation()->get();

        foreach ($countries as $country) {
            $name = $country->name;
        }

        $endTime = microtime(true);
        $endQueries = $this->getQueryCount();

        $this->recordBenchmark('Eager Load Current (withTranslation)', [
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'queries' => $endQueries - $startQueries,
            'records' => $this->recordCount,
        ]);

        Translator::instance()->setLocale('en');
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Auto-load enabled
     */
    public function testBenchmarkAutoloadEnabled(): void
    {
        Translator::instance()->enableAutoloadTranslations();
        Translator::instance()->setLocale('fr');

        $startTime = microtime(true);
        $startQueries = $this->getQueryCount();

        $countries = CountryModel::all();

        foreach ($countries as $country) {
            $name = $country->name;
        }

        $endTime = microtime(true);
        $endQueries = $this->getQueryCount();

        $this->recordBenchmark('Auto-load Enabled', [
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'queries' => $endQueries - $startQueries,
            'records' => $this->recordCount,
        ]);

        Translator::instance()->disableAutoloadTranslations();
        Translator::instance()->setLocale('en');
        $this->assertTrue(true);
    }

    /**
     * Benchmark: Default locale (no translation needed)
     */
    public function testBenchmarkDefaultLocale(): void
    {
        Translator::instance()->disableAutoloadTranslations();
        Translator::instance()->setLocale('en');

        $startTime = microtime(true);
        $startQueries = $this->getQueryCount();

        $countries = CountryModel::withoutAutoloadTranslations()->get();

        foreach ($countries as $country) {
            $name = $country->name;
        }

        $endTime = microtime(true);
        $endQueries = $this->getQueryCount();

        $this->recordBenchmark('Default Locale (no translation)', [
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'queries' => $endQueries - $startQueries,
            'records' => $this->recordCount,
        ]);

        $this->assertTrue(true);
    }

    /**
     * Get current query count from DB connection.
     */
    protected function getQueryCount(): int
    {
        return count(\DB::getQueryLog());
    }

    /**
     * Record benchmark results.
     */
    protected function recordBenchmark(string $name, array $metrics): void
    {
        $output = sprintf(
            "\n[BENCHMARK] %s: %.2fms, %d queries for %d records (%.2f queries/record)",
            $name,
            $metrics['time_ms'],
            $metrics['queries'],
            $metrics['records'],
            $metrics['queries'] / $metrics['records']
        );

        fwrite(STDERR, $output);
    }
}
