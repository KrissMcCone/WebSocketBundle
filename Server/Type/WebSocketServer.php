<?php

namespace Gos\Bundle\WebSocketBundle\Server\Type;

use Gos\Bundle\WebSocketBundle\Event\Events;
use Gos\Bundle\WebSocketBundle\Event\ServerEvent;
use Gos\Bundle\WebSocketBundle\Periodic\PeriodicInterface;
use Gos\Bundle\WebSocketBundle\Server\App\Registry\OriginRegistry;
use Gos\Bundle\WebSocketBundle\Server\App\Registry\PeriodicRegistry;
use Gos\Bundle\WebSocketBundle\Server\App\Stack\OriginCheck;
use Gos\Bundle\WebSocketBundle\Server\App\WampApplication;
use Psr\Log\LoggerInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Http\HttpServerInterface;
use Ratchet\Server\IoServer;
use Ratchet\Session\SessionProvider;
use Ratchet\Wamp\WampServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @author Johann Saunier <johann_27@hotmail.fr>
 */
class WebSocketServer implements ServerInterface
{
    /**
     * @var HttpServerInterface
     */
    protected $app;

    /**
     * @var IoServer
     */
    protected $server;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var Server
     */
    protected $socket;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var \SessionHandler|null
     */
    protected $sessionHandler;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var PeriodicRegistry
     */
    protected $periodicRegistry;

    /**
     * @var WampApplication
     */
    protected $wampApplication;

    /**
     * @var OriginRegistry|null
     */
    protected $originRegistry;

    /**
     * @var bool
     */
    protected $originCheck;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param string                   $host
     * @param int                      $port
     * @param EventDispatcherInterface $eventDispatcher
     * @param PeriodicRegistry         $periodicRegistry
     * @param WampApplication          $wampApplication
     * @param OriginRegistry           $originRegistry
     * @param bool                     $originCheck
     * @param LoggerInterface          $logger
     */
    public function __construct(
        $host,
        $port,
        EventDispatcherInterface $eventDispatcher,
        PeriodicRegistry $periodicRegistry,
        WampApplication $wampApplication,
        OriginRegistry $originRegistry,
        $originCheck,
        LoggerInterface $logger = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->eventDispatcher = $eventDispatcher;
        $this->periodicRegistry = $periodicRegistry;
        $this->wampApplication = $wampApplication;
        $this->originRegistry = $originRegistry;
        $this->originCheck = $originCheck;
        $this->logger = $logger;
    }

    /**
     * @param \SessionHandlerInterface $sessionHandler
     */
    public function setSessionHandler(\SessionHandlerInterface $sessionHandler)
    {
        $this->sessionHandler = $sessionHandler;
    }

    public function launch()
    {
        if (null !== $this->logger) {
            $this->logger->info('Starting web socket');
        }

        $serverStack = new WampServer($this->wampApplication);

        if (null !== $this->sessionHandler) {
            $serverStack = new SessionProvider(
                $serverStack,
                $this->sessionHandler
            );
        }

        $serverStack = new WsServer($serverStack);

        if (true === $this->originCheck) {
            $serverStack = new OriginCheck(
                $serverStack,
                array('localhost', '127.0.0.1'),
                $this->eventDispatcher
            );

            foreach ($this->originRegistry->getOrigins() as $origin) {
                $serverStack->allowedOrigins[] = $origin;
            }
        }

        $this->app = new HttpServer($serverStack);

        /* @var $loop LoopInterface */
        $this->loop = Factory::create();

        $this->socket = new Server($this->loop);

        $this->socket->listen($this->port, $this->host);

        /** @var PeriodicInterface $periodic */
        foreach ($this->periodicRegistry as $periodic) {
            $this->loop->addPeriodicTimer($periodic->getTimeout(), [$periodic, 'tick']);
        }

        $this->server = new IoServer($this->app, $this->socket, $this->loop);

        /* Server Event Loop to add other services in the same loop. */
        $event = new ServerEvent($this->loop);
        $this->eventDispatcher->dispatch(Events::SERVER_LAUNCHED, $event);

        if (null !== $this->logger) {
            $this->logger->info(sprintf(
                'Launching %s on %s',
                $this->getName(),
                $this->getAddress()
            ));
        }

        $this->loop->run();
    }

    /**
     * @return string
     */
    public function getAddress()
    {
        return $this->host . ':' . $this->port;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Ratchet';
    }
}
