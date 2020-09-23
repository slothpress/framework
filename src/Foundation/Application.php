<?php

namespace SlothPress\Foundation;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class Application extends Container implements ApplicationContract, HttpKernelInterface
{
    /**
     * Application version.
     *
     * @var string
     */
    const VERSION = '0.0.1';

    /**
     * Base path of the framework.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Indicates if the application has booted.
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * List of booted callbacks.
     *
     * @var array
     */
    protected $bootedCallbacks = [];

    /**
     * List of booting callbacks.
     *
     * @var array
     */
    protected $bootingCallbacks = [];

    /**
     * The deferred services and their providers.
     *
     * @var array
     */
    protected $deferredServices = [];

    /**
     * The environment file to load during bootstrapping.
     *
     * @var string
     */
    protected $environmentFile = '.env';

    /**
     * Path location (directory) of env files.
     *
     * @var string
     */
    protected $environmentPath;

    /**
     * Indicates if the application has been bootstrapped or not.
     *
     * @var bool
     */
    protected $hasBeenBootstrapped = false;

    /**
     * The names of the loaded service providers.
     *
     * @var array
     */
    protected $loadedProviders = [];

    /**
     * @var string
     */
    protected $namespace;

    /**
     * All of the registered service providers.
     *
     * @var array
     */
    protected $serviceProviders = [];

    /**
     * List of terminating callbacks.
     *
     * @var array
     */
    protected $terminatingCallbacks = [];

    public function __construct($basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
        $this->registerBaseServiceProviders();
        $this->registerCoreContainerAliases();
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @param int    $code
     * @param string $message
     * @param array  $headers
     */
    public function abort($code, $message = '', array $headers = [])
    {
        if (404 == $code) {
            throw new NotFoundHttpException($message);
        }

        throw new HttpException($code, $message, null, $headers);
    }

    /**
     * Add an array of services to the application's deferred services.
     *
     * @param array $services
     */
    public function addDeferredServices(array $services)
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }

    /**
     * Register a callback to run after a bootstrapper.
     *
     * @param string  $bootstrapper
     * @param Closure $callback
     */
    public function afterBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapped: ' . $bootstrapper, $callback);
    }

    /**
     * Register a callback to run after loading the environment.
     *
     * @param Closure $callback
     */
    public function afterLoadingEnvironment(Closure $callback)
    {
    }

    /**
     * Get the base path of the SlothPress installation.
     *
     * @param string $path Optional path to append to the base path.
     *
     * @return string
     */
    public function basePath($path = '')
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Register a callback to run before a bootstrapper.
     *
     * @param string  $bootstrapper
     * @param Closure $callback
     */
    public function beforeBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapping: ' . $bootstrapper, $callback);
    }

    /**
     * Boot the application's service providers.
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        /*
         * Once the application has booted we will also fire some "booted" callbacks
         * for any listeners that need to do work after this initial booting gets
         * finished. This is useful when ordering the boot-up processes we run.
         */
        $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->serviceProviders, function ($provider) {
            $this->bootProvider($provider);
        });

        $this->booted = true;

        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    /**
     * Register a new "booted" listener.
     *
     * @param mixed $callback
     */
    public function booted($callback)
    {
        $this->bootedCallbacks[] = $callback;

        if ($this->isBooted()) {
            $this->fireAppCallbacks([$callback]);
        }
    }

    /**
     * Register a new boot listener.
     *
     * @param mixed $callback
     */
    public function booting($callback)
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path Optionally, a path to append to the bootstrap path
     *
     * @return string
     */
    public function bootstrapPath($path = '')
    {
        // TODO: Implement bootstrapPath() method.
    }

    /**
     * Bootstrap the application with given list of bootstrap
     * classes.
     *
     * @param array $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers)
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->dispatch('bootstrapping: ' . $bootstrapper, [$this]);

            /*
             * Instantiate each bootstrap class and call its "bootstrap" method
             * with the Application as a parameter.
             */
            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->dispatch('bootstrapped: ' . $bootstrapper, [$this]);
        }
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * (Overriding Container::bound)
     *
     * @param string $abstract
     *
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->deferredServices[$abstract]) || parent::bound($abstract);
    }

    /**
     * Get the path to the application configuration files.
     *
     * @param string $path Optionally, a path to append to the config path
     *
     * @return string
     */
    public function configPath($path = '')
    {
        // TODO: Implement configPath() method.
    }

    /**
     * Determine if the application configuration is cached.
     *
     * @return bool
     */
    public function configurationIsCached()
    {
        return file_exists($this->getCachedConfigPath());
    }

    public function configureWP()
    {
        # var_dump(config('wordpress'));
    }

    /**
     * Get the path to the database directory.
     *
     * @param string $path Optionally, a path to append to the database path
     *
     * @return string
     */
    public function databasePath($path = '')
    {
        // TODO: Implement databasePath() method.
    }

    /**
     * Detech application's current environment.
     *
     * @param Closure $callback
     *
     * @return string
     */
    public function detectEnvironment(Closure $callback)
    {
        $args = $_SERVER['argv'] ?? null;

        return $this['env'] = (new EnvironmentDetector())->detect($callback, $args);
    }

    /**
     * Get or check the current application environment.
     *
     * @param string|array $environments
     *
     * @return string|bool
     */
    public function environment(...$environments)
    {
        if (count($environments) > 0) {
            $patterns = is_array($environments[0]) ? $environments[0] : $environments;

            return Str::is($patterns, $this['env']);
        }

        return $this['env'];
    }

    /**
     * Return the environment file name base.
     *
     * @return string
     */
    public function environmentFile()
    {
        return $this->environmentFile ?: '.env';
    }

    /**
     * Return the environment file path.
     *
     * @return string
     */
    public function environmentFilePath()
    {
        return $this->environmentPath() . DIRECTORY_SEPARATOR . $this->environmentFile();
    }

    /**
     * Return the environment path directory.
     *
     * @return string
     */
    public function environmentPath()
    {
        return $this->environmentPath ?: $this->basePath();
    }

    /**
     * Get the path to the configuration cache file.
     *
     * @return string
     */
    public function getCachedConfigPath()
    {
        return $this->bootstrapPath('cache/config.php');
    }

    /**
     * Get the path to the cached packages.php file.
     *
     * @return string
     */
    public function getCachedPackagesPath()
    {
        return $this->bootstrapPath('cache/packages.php');
    }

    /**
     * Get the path to the routes cache file.
     *
     * @return string
     */
    public function getCachedRoutesPath()
    {
        return $this->bootstrapPath('cache/routes.php');
    }

    /**
     * Get the path to the cached services.php file.
     *
     * @return string
     */
    public function getCachedServicesPath()
    {
        return $this->bootstrapPath('cache/services.php');
    }

    /**
     * Get the application's deferred services.
     *
     * @return array
     */
    public function getDeferredServices()
    {
        return $this->deferredServices;
    }

    /**
     * Get the service providers that have been loaded.
     *
     * @return array
     */
    public function getLoadedProviders()
    {
        return $this->loadedProviders;
    }

    /**
     * Get the application locale.
     *
     * @return string
     */
    public function getLocale()
    {
        return $this['config']->get('app.locale');
    }

    /**
     * Return the application namespace.
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    public function getNamespace()
    {
        if (! is_null($this->namespace)) {
            return $this->namespace;
        }

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path) {
            foreach ((array) $path as $pathChoice) {
                if (realpath(app_path()) == realpath(base_path($pathChoice))) {
                    return $this->namespace = $namespace;
                }
            }
        }

        throw new \RuntimeException('Unable to detect application namespace.');
    }

    /**
     * Get the registered service provider instance if it exists.
     *
     * @param ServiceProvider|string $provider
     *
     * @return ServiceProvider|null
     */
    public function getProvider($provider)
    {
        return array_values($this->getProviders($provider))[0] ?? null;
    }

    /**
     * Get the registered service provider instances if any exist.
     *
     * @param ServiceProvider|string $provider
     *
     * @return array
     */
    public function getProviders($provider)
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return Arr::where($this->serviceProviders, function ($value) use ($name) {
            return $value instanceof $name;
        });
    }

    /**
     * Handles a Request to convert it to a Response.
     *
     * When $catch is true, the implementation must catch all exceptions
     * and do its best to convert them to a Response instance.
     *
     * @param SymfonyRequest $request A Request instance
     * @param int            $type    The type of the request
     *                                (one of HttpKernelInterface::MASTER_REQUEST or HttpKernelInterface::SUB_REQUEST)
     * @param bool           $catch   Whether to catch exceptions or not
     *
     * @throws \Exception When an Exception occurs during processing
     *
     * @return Response A Response instance
     */
    public function handle(SymfonyRequest $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        return $this[HttpKernelContract::class]->handle(Request::createFromBase($request));
    }

    /**
     * Verify if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Determine if the application has booted.
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @throws \Illuminate\Container\EntryNotFoundException
     *
     * @return bool
     */
    public function isDownForMaintenance()
    {
        $filePath = $this->wordpressPath('.maintenance');

        if (function_exists('wp_installing') && ! file_exists($filePath)) {
            return \wp_installing();
        }

        return file_exists($filePath);
    }

    /**
     * Check if passed locale is current locale.
     *
     * @param string $locale
     *
     * @return bool
     */
    public function isLocale($locale)
    {
        return $this->getLocale() == $locale;
    }

    /**
     * Load configuration files based on given path.
     *
     * @param Repository $config
     * @param string     $path   The configuration files folder path.
     *
     * @return Application
     */
    public function loadConfigurationFiles(Repository $config, $path = '')
    {
        $files = $this->getConfigurationFiles($path);

        foreach ($files as $key => $path) {
            $config->set($key, require $path);
        }

        return $this;
    }

    /**
     * Load the provider for a deferred service.
     *
     * @param string $service
     */
    public function loadDeferredProvider($service)
    {
        if (! isset($this->deferredServices[$service])) {
            return;
        }

        $provider = $this->deferredServices[$service];

        // If the service provider has not already been loaded and registered we can
        // register it with the application and remove the service from this list
        // of deferred services, since it will already be loaded on subsequent.
        if (! isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Load and boot all of the remaining deferred providers.
     */
    public function loadDeferredProviders()
    {
        // We will simply spin through each of the deferred providers and register each
        // one and boot them if the application has booted. This should make each of
        // the remaining services available to this application for immediate use.
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }

        $this->deferredServices = [];
    }

    /**
     * Set the environment file to be loaded during bootstrapping.
     *
     * @param string $file
     *
     * @return $this
     */
    public function loadEnvironmentFrom($file)
    {
        $this->environmentFile = $file;

        return $this;
    }

    /**
     * Bootstrap a SlothPress like plugin.
     *
     * @param string $filePath
     * @param string $configPath
     *
     * @return PluginManager
     */
    public function loadPlugin(string $filePath, string $configPath)
    {
        $plugin = (new PluginManager($this, $filePath, new ClassLoader()))->load($configPath);

        $this->instance('wp.plugin.' . $plugin->getHeader('plugin_id'), $plugin);

        return $plugin;
    }

    /**
     * Resolve the given type from the container.
     *
     * (Overriding Container::make)
     *
     * @param string $abstract
     * @param array  $parameters
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->deferredServices[$abstract]) && ! isset($this->instances[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }

        return parent::make($abstract, $parameters);
    }

    /**
     * Handle incoming request and return a response.
     * Abstract the implementation from the user for easy
     * theme integration.
     *
     * @param string                                    $kernel  Application kernel class name.
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return $this
     */
    public function manage(string $kernel, $request)
    {
        $kernel = $this->make($kernel);

        $response = $kernel->handle($request);
        $response->send();

        $kernel->terminate($request, $response);

        return $this;
    }

    /**
     * Get the path to the application "SlothPress-application" directory.
     *
     * @param string $path
     *
     * @return string
     */
    public function path($path = '')
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'app' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Register a service provider with the application.
     *
     * @param \Illuminate\Support\ServiceProvider|string $provider
     * @param array                                      $options
     * @param bool                                       $force
     *
     * @return \Illuminate\Support\ServiceProvider
     */
    public function register($provider, $options = [], $force = false)
    {
        if (($registered = $this->getProvider($provider)) && ! $force) {
            return $registered;
        }

        // If the given "provider" is a string, we will resolve it, passing in the
        // application instance automatically for the developer. This is simply
        // a more convenient way of specifying your service provider classes.
        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }

        if (method_exists($provider, 'register')) {
            $provider->register();
        }

        // If there are bindings / singletons set as properties on the provider we
        // will spin through them and register them with the application, which
        // serves as a convenience layer while registering a lot of bindings.
        if (property_exists($provider, 'bindings')) {
            foreach ($provider->bindings as $key => $value) {
                $this->bind($key, $value);
            }
        }

        if (property_exists($provider, 'singletons')) {
            foreach ($provider->singletons as $key => $value) {
                $this->singleton($key, $value);
            }
        }

        $this->markAsRegistered($provider);

        // If the application has already booted, we will call this boot method on
        // the provider class so it has an opportunity to do its boot logic and
        // will be ready for any usage by this developer's application logic.
        if ($this->booted) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Register all of the configured providers.
     */
    public function registerConfiguredProviders()
    {
        $providers = Collection::make($this->config['app.providers'])
                               ->partition(function ($provider) {
                                   return Str::startsWith($provider, 'Illuminate\\');
                               });

        $providers->splice(1, 0, [$this->make(PackageManifest::class)->providers()]);

        (new ProviderRepository($this, new Filesystem(), $this->getCachedServicesPath()))
            ->load($providers->collapse()->toArray());
    }

    /**
     * Register a deferred provider and service.
     *
     * @param string      $provider
     * @param string|null $service
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        if ($service) {
            unset($this->deferredServices[$service]);
        }

        $this->register($instance = new $provider($this));

        if (! $this->booted) {
            $this->booting(function () use ($instance) {
                $this->bootProvider($instance);
            });
        }
    }

    /**
     * Resolve a service provider instance from the class name.
     *
     * @param string $provider
     *
     * @return ServiceProvider
     */
    public function resolveProvider($provider)
    {
        return new $provider($this);
    }

    /**
     * Get the path to the resources directory.
     *
     * @param string $path
     *
     * @return string
     */
    public function resourcePath($path = '')
    {
        // TODO: Implement resourcePath() method.
    }

    /**
     * Determine if the application routes are cached.
     *
     * @return bool
     */
    public function routesAreCached()
    {
        return $this['files']->exists($this->getCachedRoutesPath());
    }

    /**
     * Determine if we are running in the console.
     *
     * @return bool
     */
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli' || php_sapi_name() == 'phpdbg';
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests()
    {
        return $this['env'] == 'testing';
    }

    /**
     * Set the base path for the application.
     *
     * @param string $basePath
     *
     * @return $this
     */
    public function setBasePath($basePath)
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->bindPathsInContainer();

        return $this;
    }

    /**
     * Set the application's deferred services.
     *
     * @param array $services
     */
    public function setDeferredServices(array $services)
    {
        $this->deferredServices = $services;
    }

    /**
     * Set the application locale.
     *
     * @param string $locale
     */
    public function setLocale($locale)
    {
        $this['config']->set('app.locale', $locale);
        $this['translator']->setLocale($locale);
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware()
    {
        return $this->bound('middleware.disable') &&
               $this->make('middleware.disable') === true;
    }

    /**
     * Get the path to the storage directory.
     *
     * @return string
     */
    public function storagePath()
    {
        // TODO: Implement storagePath() method.
    }

    /**
     * Terminate the application.
     */
    public function terminate()
    {
        foreach ($this->terminatingCallbacks as $terminating) {
            $this->call($terminating);
        }
    }

    /**
     * Register a terminating callback with the application.
     *
     * @param Closure $callback
     *
     * @return $this
     */
    public function terminating(Closure $callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the directory for the environment file.
     *
     * @param string $path
     *
     * @return $this
     */
    public function useEnvironmentPath($path)
    {
        $this->environmentPath = $path;

        return $this;
    }

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        return static::VERSION;
    }

    /**
     * Bind all of the application paths in the container.
     */
    protected function bindPathsInContainer()
    {
        // Core
        $this->instance('path', $this->path());
        // Base
        $this->instance('path.base', $this->basePath());
    }

    /**
     * Boot the given service provider.
     *
     * @param ServiceProvider $provider
     *
     * @return mixed
     */
    protected function bootProvider(ServiceProvider $provider)
    {
        if (method_exists($provider, 'boot')) {
            return $this->call([$provider, 'boot']);
        }
    }

    /**
     * Call the booting callbacks for the application.
     *
     * @param array $callbacks
     */
    protected function fireAppCallbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $this);
        }
    }

    /**
     * Get all configuration files.
     *
     * @param mixed $path
     *
     * @return array
     */
    protected function getConfigurationFiles($path)
    {
        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
            $directory                                                  = $this->getNestedDirectory($file, $path);
            $files[$directory . basename($file->getRealPath(), '.php')] = $file->getRealPath();
        }

        ksort($files, SORT_NATURAL);

        return $files;
    }

    /**
     * Get configuration file nesting path.
     *
     * @param SplFileInfo $file
     * @param string      $path
     *
     * @return string
     */
    protected function getNestedDirectory(SplFileInfo $file, $path)
    {
        $directory = $file->getPath();

        if ($nested = trim(str_replace($path, '', $directory), DIRECTORY_SEPARATOR)) {
            $nested = str_replace(DIRECTORY_SEPARATOR, '.', $nested) . '.';
        }

        return $nested;
    }

    /**
     * Mark the given provider as registered.
     *
     * @param ServiceProvider $provider
     */
    protected function markAsRegistered($provider)
    {
        $this->serviceProviders[]                    = $provider;
        $this->loadedProviders[get_class($provider)] = true;
    }

    /**
     * Register basic bindings into the container.
     */
    protected function registerBaseBindings()
    {
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(PackageManifest::class, new PackageManifest(
            new Filesystem(),
            $this->basePath(),
            $this->getCachedPackagesPath()
        ));
    }

    /**
     * Register base service providers.
     */
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));
        $this->register(new LogServiceProvider($this));
    }

    /**
     * Register the core class aliases in the container.
     */
    protected function registerCoreContainerAliases()
    {
        foreach (
            [
                'app'    => [
                    Application::class,
                    \Illuminate\Contracts\Container\Container::class,
                    \Illuminate\Contracts\Foundation\Application::class,
                    \Psr\Container\ContainerInterface::class,
                ],
                'config' => [
                    \Illuminate\Config\Repository::class,
                    \Illuminate\Contracts\Config\Repository::class,
                ],
                'events' => [
                    \Illuminate\Events\Dispatcher::class,
                    \Illuminate\Contracts\Events\Dispatcher::class,
                ],
            ] as $key => $aliases
        ) {
            foreach ($aliases as $alias) {
                $this->alias($key, $alias);
            }
        }
    }
}
