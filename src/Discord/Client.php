<?php

namespace Discord\Slash;

use Discord\Interaction as DiscordInteraction;
use Discord\InteractionResponseType;
use Discord\InteractionType;
use Discord\Slash\Parts\Interaction;
use Discord\Slash\Parts\RegisteredCommand;
use InvalidArgumentException;
use React\Http\MEssage\Response;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\Server as HttpServer;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\Promise;
use React\Socket\Server as SocketServer;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Client class acts as an HTTP web server to handle requests from Discord when a command
 * is triggered. The class can also be used as a request handler by mocking a ServerRequestInterface
 * to allow it to be used with another webserver such as Apache or nginx.
 *
 * @author David Cole <david.cole1340@gmail.com>
 */
class Client
{
    const API_BASE_URI = "https://discord.com/api/v8/";

    /**
     * Array of options for the client.
     *
     * @var array
     */
    private $options;

    /**
     * HTTP server.
     *
     * @var HttpServer
     */
    private $server;

    /**
     * Socket listening for connections.
     *
     * @var SocketServer
     */
    private $socket;

    /**
     * An array of registered commands.
     *
     * @var RegisteredCommand[] 
     */
    private $commands;

    /**
     * Logger for client.
     * 
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(array $options = [])
    {
        $this->options = $this->resolveOptions($options);
        $this->logger = $this->options['logger'];
        $this->registerServer();
    }

    /**
     * Resolves the options for the client.
     *
     * @param array $options
     *
     * @return array
     */
    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();

        $resolver
            ->setDefined([
                'uri',
                'logger',
                'loop',
                'public_key',
                'socket_options',
            ])
            ->setDefaults([
                'uri' => '0.0.0.0:80',
                'loop' => Factory::create(),
                'socket_options' => [],
            ])
            ->setRequired([
                'public_key',
            ]);

        $options = $resolver->resolve($options);

        if (! isset($options['logger'])) {
            $options['logger'] = (new Logger('DiscordPHP/Slash'))->pushHandler(new StreamHandler('php://stdout'));
        }

        return $options;
    }

    /**
     * Sets up the ReactPHP HTTP server.
     */
    private function registerServer()
    {
        $this->server = new HttpServer($this->getLoop(), [$this, 'handleRequest']);
        $this->socket = new SocketServer($this->options['uri'], $this->getLoop(), $this->options['socket_options']);

        // future tick so that the socket won't listen
        // when running in CGI mode
        $this->getLoop()->futureTick(function () {
            $this->server->listen($this->socket);
        });
    }

    /**
     * Handles an HTTP request to the server.
     *
     * @param ServerRequestInterface $request
     */
    public function handleRequest(ServerRequestInterface $request)
    {
        // validate request with public key
        $signature = $request->getHeaderLine('X-Signature-Ed25519');
        $timestamp = $request->getHeaderLine('X-Signature-Timestamp');

        if (empty($signature) || empty($timestamp) || ! DiscordInteraction::verifyKey((string) $request->getBody(), $signature, $timestamp, $this->options['public_key'])) {
            return new Response(401, [0], 'Not verified');
        }

        $interaction = new Interaction(json_decode($request->getBody(), true));

        $this->logger->info('received interaction', $interaction->jsonSerialize());

        return $this->handleInteraction($interaction)->then(function ($result) {
            $this->logger->info('responding to interaction', $result);
            return new Response(200, [], json_encode($result));
        });
    }

    /**
     * Handles an interaction from Discord.
     *
     * @param Interaction $interaction
     * 
     * @return ExtendedPromiseInterface
     */
    private function handleInteraction(Interaction $interaction): ExtendedPromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($interaction) { 
            switch ($interaction->type) {
                case InteractionType::PING:
                    return $resolve([
                        'type' => InteractionResponseType::PONG,
                    ]);
                case InteractionType::APPLICATION_COMMAND:
                    $interaction->setResolve($resolve);
                    return $this->handleApplicationCommand($interaction);
            }
        });
    }

    /**
     * Handles an application command interaction from Discord.
     *
     * @param Interaction $interaction
     */
    private function handleApplicationCommand(Interaction $interaction): void
    {
        $checkCommand = function ($command) use ($interaction, &$checkCommand) {
            if (isset($this->commands[$command['name']])) {
                if ($this->commands[$command['name']]->execute($command['options'], $interaction)) return true;
            }

            foreach ($command['options'] ?? [] as $option) {
                if ($checkCommand($option)) return true;
            }
        };

        $checkCommand($interaction->data);
    }

    /**
     * Registeres a command with the client.
     *
     * @param string|array $name
     * @param callable $callback
     * 
     * @return RegisteredCommand
     */
    public function registerCommand($name, callable $callback = null): RegisteredCommand 
    {
        if (is_array($name) && count($name) == 1) $name = array_shift($name);

        // registering base command
        if (! is_array($name) || count($name) == 1) {
            if (isset($this->commands[$name])) throw new InvalidArgumentException("The command `{$name}` already exists.");
            
            return $this->commands[$name] = new RegisteredCommand($name, $callback);
        }

        $baseCommand = array_shift($name);

        if (! isset($this->commands[$baseCommand])) {
            $this->registerCommand($baseCommand);
        }

        return $this->commands[$baseCommand]->addSubCommand($name, $callback);
    }

    /**
     * Starts the ReactPHP event loop.
     */
    public function run()
    {
        $this->getLoop()->run();
    }

    /**
     * Gets the ReactPHP event loop.
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->options['loop'];
    }
}
