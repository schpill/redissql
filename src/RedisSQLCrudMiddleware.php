<?php

namespace Morbihanet\RedisSQL;

use Closure;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

class RedisSQLCrudMiddleware extends StartSession
{
    /**
     * @var QueueingFactory
     */
    protected $cookies;

    /**
     * @var Registrar
     */
    protected $router;

    /**
     * @param SessionManager $manager
     * @param QueueingFactory $cookies
     * @param Registrar $router
     */
    public function __construct(SessionManager $manager, QueueingFactory $cookies, Registrar $router)
    {
        $this->cookies = $cookies;
        $this->router = $router;

        parent::__construct($manager);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $this->router->substituteBindings($route = $request->route());
        $this->router->substituteImplicitBindings($route);

        $response = parent::handle($request, $next);

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
