<?php

namespace SlothPress\Foundation\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;

class Kernel implements HttpKernelContract
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Router
     */
    protected $router;

    /**
     * Kernel constructor.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @param \Illuminate\Routing\Router                   $router
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app    = $app;
        $this->router = $router;
    }

    /**
     * Bootstrap the application for HTTP requests.
     */
    public function bootstrap()
    {
        // TODO: Implement bootstrap() method.
    }

    /**
     * Get the SlothPress application instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public function getApplication()
    {
        // TODO: Implement getApplication() method.
    }

    /**
     * Handle an incoming HTTP request.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request)
    {
        // TODO: Implement handle() method.
    }

    /**
     * Initialize the kernel (bootstrap application base components).
     *
     * @param \Illuminate\Http\Request $request
     */
    public function init($request)
    {
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        $this->bootstrap();
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param \Symfony\Component\HttpFoundation\Request  $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function terminate($request, $response)
    {
        // TODO: Implement terminate() method.
    }
}
