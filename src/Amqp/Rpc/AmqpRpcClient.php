<?php
namespace Icecave\Overpass\Amqp\Rpc;

use Icecave\Overpass\Rpc\Message\Request;
use Icecave\Overpass\Rpc\Message\Response;
use Icecave\Overpass\Rpc\RpcClientInterface;
use Icecave\Overpass\Serialization\JsonSerialization;
use Icecave\Overpass\Serialization\SerializationInterface;
use Icecave\Repr\Repr;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;

class AmqpRpcClient implements RpcClientInterface
{
    use LoggerAwareTrait;

    /**
     * @param AMQPChannel                 $channel
     * @param DeclarationManager|null     $declarationManager
     * @param SerializationInterface|null $serialization
     */
    public function __construct(
        AMQPChannel $channel,
        DeclarationManager $declarationManager = null,
        SerializationInterface $serialization = null
    ) {
        $this->channel = $channel;
        $this->declarationManager = $declarationManager ?: new DeclarationManager($channel);
        $this->serialization = $serialization ?: new JsonSerialization();
        $this->correlationId = 0;
    }

    /**
     * Invoke a remote procedure.
     *
     * @param string $name      The name of the procedure to invoke
     * @param array  $arguments The arguments to pass.
     *
     * @return mixed The return value.
     */
    public function call($name, array $arguments)
    {
        $this->initialize();

        $correlationId = ++$this->correlationId;

        if ($this->logger) {
            $this->logger->debug(
                'Call #{id} invoke "{procedure}" with {arguments}',
                [
                    'procedure' => $name,
                    'arguments' => Repr::repr($arguments),
                    'id' => $correlationId
                ]
            );
        }

        $this->send(
            Request::create($name, $arguments)
        );

        $response = $this->wait();

        if ($this->logger) {
            $this->logger->debug(
                'Call #{id} invoke "{procedure}" with {arguments}: {code} {value}',
                [
                    'code' => $response->code()->key(),
                    'value' => Repr::repr($response->value()),
                    'id' => $correlationId
                ]
            );
        }

        return $response->extract();
    }

    /**
     * Invoke a remote procedure.
     *
     * @param string $name      The name of the procedure to invoke
     * @param array  $arguments The arguments to pass.
     *
     * @return mixed The return value.
     */
    public function __call($name, array $arguments)
    {
        return $this->call($name, $arguments);
    }

    /**
     * Initialize AMQP resources.
     */
    private function initialize()
    {
        if ($this->consumerTag) {
            return;
        }

        $this->consumerTag = $this->channel->basic_consume(
            $this->declarationManager->responseQueue(),
            '',    // consumer tag
            false, // no local
            true,  // no ack
            true,  // exclusive
            false, // no wait
            function ($message) {
                $this->recv($message);
            }
        );
    }

    /**
     * Send an RPC request.
     *
     * @param Request $request
     */
    private function send(Request $request)
    {
        $payload = $this
            ->serialization
            ->serialize($request);

        $message = new AMQPMessage(
            $payload,
            [
                'reply_to'       => $this->declarationManager->responseQueue(),
                'correlation_id' => $this->correlationId,
            ]
        );

        $this->channel->basic_publish(
            $message,
            '', // default direct exchange
            $this->declarationManager->requestQueue($request->name())
        );
    }

    /**
     * Receive an RPC response.
     *
     * @param AMQPMessage $message
     */
    private function recv(AMQPMessage $message)
    {
        $correlationId = $message->get('correlation_id');

        if ($correlationId < $this->correlationId) {
            return;
        } elseif ($correlationId > $this->correlationId) {
            throw new RuntimeException(
                'Out-of-order RPC response returned by server.'
            );
        }

        $payload = $this
            ->serialization
            ->unserialize($message->body);

        $this->response = Response::createFromPayload($payload);
    }

    /**
     * Wait for an RPC response.
     *
     * @return Response
     */
    private function wait()
    {
        while (
            !$this->response
            && $this->channel->callbacks
        ) {
            $this->channel->wait();
        }

        $response = $this->response;
        $this->response = null;

        return $response;
    }

    private $channel;
    private $declarationManager;
    private $serialization;
    private $correlationId;
    private $consumerTag;
    private $response;
}
