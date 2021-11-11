<?php

namespace Codewiser\Polyglot;

use Codewiser\Polyglot\Contracts\ManipulatorInterface;
use Codewiser\Polyglot\Manipulators\GettextManipulator;
use Codewiser\Polyglot\Manipulators\StringsManipulator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PolyglotApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->gate();

        // Loading resources in deferrable provider will not work.
        // So we load resources in here.
        $this->registerRoutes();
        $this->registerResources();
    }

    /**
     * Register the Polyglot routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        Route::group([
            'domain' => config('polyglot.domain', null),
            'prefix' => config('polyglot.path'),
            'middleware' => config('polyglot.middleware', 'web'),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }

    /**
     * Register the Polyglot resources.
     *
     * @return void
     */
    protected function registerResources()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'polyglot');
    }

    /**
     * Register the Polyglot gate.
     *
     * This gate determines who can access Polyglot in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewPolyglot', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/polyglot.php', 'polyglot');

        $this->registerManager();
        $this->registerStringsManipulator();
        $this->registerGettextManipulator();
        $this->registerManipulator();
    }

    protected function registerManager()
    {
        $this->app->singleton(ExtractorsManager::class, function ($app) {
            $config = $app['config']['polyglot'];

            if ($config['mode'] == 'editor')
                return null;

            $manager = new ExtractorsManager($app['translation.loader']);

            if (isset($config['sources'])) {
                // Single (default) extractor.
                $manager->addExtractor(
                    $this->getExtractor(
                        (array)$config['sources'],
                        isset($config['exclude']) ? (array)$config['exclude'] : []
                    )
                );
            }

            if (isset($config['text_domains'])) {
                // Multiple (configurable) extractors.
                foreach ($config['text_domains'] as $text_domain) {
                    $extractor = $this->getExtractor(
                        (array)$text_domain['sources'],
                        isset($text_domain['exclude']) ? (array)$text_domain['exclude'] : []
                    );
                    $extractor->setTextDomain($text_domain['text_domain']);
                    $extractor->setCategory($text_domain['category'] ?? LC_MESSAGES);

                    $manager->addExtractor($extractor);
                }
            }

            return $manager;
        });
    }

    protected function registerManipulator()
    {
        $this->app->bind(ManipulatorInterface::class, function ($app) {
            $config = $app['config']['polyglot'];
            switch ($config['mode']) {
                case 'translator':
                    return app(GettextManipulator::class);
                case 'collector':
                    return app(StringsManipulator::class);
                default:
                    return null;
            }
        });
    }

    protected function registerStringsManipulator()
    {
        $this->app->bind(StringsManipulator::class, function ($app) {
            $config = $app['config']['polyglot'];

            return new StringsManipulator(
                $config['locales'],
                $app['translation.loader']
            );
        });
    }

    protected function registerGettextManipulator()
    {
        $this->app->bind(GettextManipulator::class, function ($app) {
            $config = $app['config']['polyglot'];

            $manipulator = new GettextManipulator(
                $config['locales'],
                $app['translation.loader'],
                app(StringsManipulator::class)
            );

            $manipulator
                ->msginit($config['executables']['msginit'])
                ->msgmerge($config['executables']['msgmerge'])
                ->msgfmt($config['executables']['msgfmt'])
                ->setPassthroughs($config['passthroughs']);

            return $manipulator;
        });
    }

    protected function getExtractor(array $sources, array $exclude): Extractor
    {
        $config = config('polyglot');

        $collector = new Extractor(
            config('app.name'),
            $sources
        );

        $collector
            ->exclude($exclude)
            ->xgettext($config['executables']['xgettext']);

        return $collector;
    }
}