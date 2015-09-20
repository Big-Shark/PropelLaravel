<?php

/**
 * Laravel Propel integration.
 *
 * @author    Alex Kazinskiy <alboo@list.ru>
 * @author    Alexander Zhuravlev <scif-1986@ya.ru>
 * @author    Maxim Soloviev <BigShark666@gmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @link      https://github.com/propelorm/PropelLaravel
 */

namespace Propel\PropelLaravel;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Propel\Common\Config\Exception\InvalidConfigurationException;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Propel;
use Symfony\Component\Console\Input\ArgvInput;

class PropelIntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if (! $this->app->make('config')->has('propel.propel.general')
            && is_file(config_path('propel.php'))
        ) {
            $this->app->make('config')->set('propel', require config_path('propel.php'));
        }

        $this->mergeConfigFrom(
            __DIR__.'/../config/propel.php', 'propel'
        );
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $configurator = $this->app->make('config');

        $this->publishes([
            __DIR__.'/../config/propel.php' => config_path('propel.php'),
        ]);

        $converted_conf_file = $configurator->get('propel.propel.paths.phpConfDir').'/config.php';

        // load pregenerated config
        if (file_exists($converted_conf_file)) {
            include $converted_conf_file;
        } else {
            $this->registerRuntimeConfiguration();
        }

        if ('propel' === $configurator->get('auth.driver')) {
            $this->registerPropelAuth();
        }

        if (\App::runningInConsole()) {
            $this->registerCommands();
        }
    }

    /**
     * Register propel runtime configuration.
     *
     * @return void
     */
    protected function registerRuntimeConfiguration()
    {
        $propel_conf = $this->app->config['propel.propel'];

        if (! isset($propel_conf['runtime']['connections'])) {
            throw new \InvalidArgumentException('Unable to guess Propel runtime config file. Please, initialize the "propel.runtime" parameter.');
        }

        /** @var $serviceContainer \Propel\Runtime\ServiceContainer\StandardServiceContainer */
        $serviceContainer = Propel::getServiceContainer();
        $serviceContainer->closeConnections();
        $serviceContainer->checkVersion('2.0.0-dev');

        $runtime_conf = $propel_conf['runtime'];

        // set connections
        foreach ($runtime_conf['connections'] as $connection_name) {
            $config = $propel_conf['database']['connections'][$connection_name];

            $serviceContainer->setAdapterClass($connection_name, $config['adapter']);
            $manager = new ConnectionManagerSingle();
            $manager->setConfiguration($config + [$propel_conf['paths']]);
            $manager->setName($connection_name);
            $serviceContainer->setConnectionManager($connection_name, $manager);
        }

        $serviceContainer->setDefaultDatasource($runtime_conf['defaultConnection']);

        // set loggers
        $has_default_logger = false;
        if (isset($runtime_conf['log'])) {
            $has_default_logger = array_key_exists('defaultLogger', $runtime_conf['log']);
            foreach ($runtime_conf['log'] as $logger_name => $logger_conf) {
                $serviceContainer->setLoggerConfiguration($logger_name, $logger_conf);
            }
        }

        if (! $has_default_logger) {
            $serviceContainer->setLogger('defaultLogger', \Log::getMonolog());
        }

        Propel::setServiceContainer($serviceContainer);
    }

    /**
     * Register propel auth provider.
     *
     * @return void
     */
    protected function registerPropelAuth()
    {
        $command = false;

        if (\App::runningInConsole()) {
            $input = new ArgvInput();
            $command = $input->getFirstArgument();
        }

        // skip auth driver adding if running as CLI to avoid auth model not found
        if ('propel:model:build' === $command) {
            return;
        }

        $query_name = \Config::get('auth.user_query', false);

        if ($query_name) {
            $query = new $query_name();

            if (! $query instanceof Criteria) {
                throw new InvalidConfigurationException('Configuration directive «auth.user_query» must contain valid classpath of user Query. Excpected type: instanceof Propel\\Runtime\\ActiveQuery\\Criteria');
            }
        } else {
            $user_class = \Config::get('auth.model');
            $query = new $user_class();

            if (! method_exists($query, 'buildCriteria')) {
                throw new InvalidConfigurationException('Configuration directive «auth.model» must contain valid classpath of model, which has method «buildCriteria()»');
            }

            $query = $query->buildPkeyCriteria();
            $query->clear();
        }

        \Auth::extend('propel', function (Application $app) use ($query) {
            return new Auth\PropelUserProvider($query, $app->make('hash'));
        });
    }

    public function registerCommands()
    {
        $commands = [
            Commands\ConfigConvertCommand::class,
            Commands\DatabaseReverseCommand::class,
            Commands\GraphvizGenerateCommand::class,
            Commands\MigrationDiffCommand::class,
            Commands\MigrationDownCommand::class,
            Commands\MigrationMigrateCommand::class,
            Commands\MigrationStatusCommand::class,
            Commands\MigrationUpCommand::class,
            Commands\ModelBuildCommand::class,
            Commands\SqlBuildCommand::class,
            Commands\SqlInsertCommand::class,

            Commands\CreateSchema::class,
        ];

        $this->commands($commands);
    }
}
