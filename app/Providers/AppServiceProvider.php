<?php

namespace App\Providers;

use App\Services\CandidateRepository;
use App\Services\Candidates\BrandRiskDetector;
use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Czds\CzdsClient;
use App\Services\Rdap\RdapBootstrap;
use App\Services\Rdap\RdapClient;
use App\Services\Text\Dictionary;
use App\Services\Weaker\WeakerSiteChecker;
use App\Services\Zone\ZoneDiffer;
use App\Services\Zone\ZoneExtractor;
use App\Services\Zone\ZoneLoader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClickHouseClient::class, fn () => new ClickHouseClient(
            config('zones.clickhouse'),
        ));

        $this->app->singleton(CzdsClient::class, fn () => new CzdsClient(
            config('zones.czds'),
        ));

        $this->app->singleton(ZoneExtractor::class, fn ($app) => new ZoneExtractor(
            config('zones'),
            $app->make(ClickHouseClient::class),
        ));

        $this->app->singleton(ZoneLoader::class, fn ($app) => new ZoneLoader(
            $app->make(ClickHouseClient::class),
            config('zones.clickhouse.database'),
        ));

        $this->app->singleton(ZoneDiffer::class, fn ($app) => new ZoneDiffer(
            $app->make(ClickHouseClient::class),
            config('zones.clickhouse.database'),
        ));

        $this->app->singleton(WeakerSearcher::class, fn ($app) => new WeakerSearcher(
            $app->make(ClickHouseClient::class),
            config('zones.weaker'),
            config('zones.clickhouse.database'),
        ));

        $this->app->singleton(Dictionary::class, fn () => new Dictionary(
            [storage_path('app/dictionary.txt')],
        ));

        $this->app->singleton(BrandRiskDetector::class, fn ($app) => new BrandRiskDetector(
            $app->make(Dictionary::class),
            storage_path('app/brand_terms.txt'),
        ));

        $this->app->singleton(Scorer::class, fn ($app) => new Scorer(
            $app->make(Dictionary::class),
            $app->make(BrandRiskDetector::class),
        ));

        $this->app->singleton(OwnerIndependence::class, fn () => new OwnerIndependence);

        $this->app->singleton(WeakerSiteChecker::class, fn () => new WeakerSiteChecker);

        $this->app->singleton(RdapBootstrap::class, fn () => new RdapBootstrap(
            config('zones.rdap'),
        ));

        $this->app->singleton(RdapClient::class, fn ($app) => new RdapClient(
            config('zones.rdap'),
            $app->make(RdapBootstrap::class),
        ));

        $this->app->singleton(CandidateRepository::class, fn ($app) => new CandidateRepository(
            $app->make(ClickHouseClient::class),
            config('zones.clickhouse.database'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
