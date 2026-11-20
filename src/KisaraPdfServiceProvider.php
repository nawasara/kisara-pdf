<?php

namespace Nawasara\KisaraPdf;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

class KisaraPdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-kisara-pdf.php', 'nawasara-kisara-pdf');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-kisara-pdf');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Guarded: view:cache crashes on a component path that does not exist.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-kisara-pdf');
        }

        $this->registerLivewire();
        $this->registerSchedule();

        // Scopes before routes: the token UI hides scopes nobody registered,
        // so forgetting this would make the scope impossible to grant.
        $this->registerApiScopes();
        $this->registerApiRoutes();
    }

    /** Guarded by class_exists: the package still works without nawasara/api. */
    public function registerApiScopes(): void
    {
        if (! class_exists(\Nawasara\Api\Support\ScopeRegistry::class)) {
            return;
        }

        $registry = $this->app->make(\Nawasara\Api\Support\ScopeRegistry::class);

        $registry->register(
            'kisara-pdf.activity.write',
            'Catat riwayat pekerjaan Kisara PDF (batch, idempoten per job_id). Hanya untuk gateway '
            .'Kisara PDF di LXC 146. Memuat NIP dan nama berkas; pasangi IP allow-list.',
        );

        $registry->register(
            'kisara-pdf.activity.read',
            'Baca riwayat Kisara PDF seorang ASN per NIP, 90 hari terakhir, untuk halaman Riwayat '
            .'aktivitas dan palet cari Kisara PDF. Memuat nama berkas; pasangi IP allow-list.',
        );
    }

    /** Mount routes/api.php at /api/v1/kisara-pdf. */
    public function registerApiRoutes(): void
    {
        if (! class_exists(\Nawasara\Api\ApiServiceProvider::class)) {
            return;
        }

        Route::prefix((string) config('nawasara-api.route.prefix', 'api/v1').'/kisara-pdf')
            ->middleware(['api', 'api.auth', 'api.log'])
            ->name('nawasara-api.kisara-pdf.')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Statistik harian lalu prune, jam dinding WIB (app.timezone = UTC).
     * $schedule->call(), bukan ->command() (AGENTS §7). Agregat berjalan
     * lebih dulu supaya hari yang dihitung ulang belum tersentuh prune
     * (keduanya berjauhan: 3 hari vs 90 hari, tetapi urutannya tetap dijaga).
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            if (! $this->app->runningInConsole() || ! config('nawasara-kisara-pdf.scheduler.enabled', true)) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);

            $schedule->call(fn () => Jobs\AggregateDailyStatsJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-kisara-pdf:aggregate-daily-stats')
                ->dailyAt((string) config('nawasara-kisara-pdf.scheduler.aggregate_at', '00:20'))
                ->timezone('Asia/Jakarta')
                ->withoutOverlapping(60);

            $schedule->call(fn () => Jobs\PruneActivitiesJob::dispatch(triggerSource: 'scheduled'))
                ->name('nawasara-kisara-pdf:prune-activities')
                ->dailyAt((string) config('nawasara-kisara-pdf.scheduler.prune_at', '03:30'))
                ->timezone('Asia/Jakarta')
                ->withoutOverlapping(60);
        });
    }

    public function registerLivewire(): void
    {
        $namespace = 'Nawasara\\KisaraPdf\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-kisara-pdf.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
