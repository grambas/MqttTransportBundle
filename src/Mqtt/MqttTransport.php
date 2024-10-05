<?php

namespace Kl3sk\MqttTransportBundle\Mqtt;

use Monolog\Level;
use Monolog\Logger;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\ConfigurationInvalidException;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\Exceptions\ProtocolNotSupportedException;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class MqttTransport implements TransportInterface {

    private SerializerInterface $serializer;
    private ?MqttClient $client = null;

    public function __construct(private readonly array   $options,
                                private readonly array   $topics,
                                SerializerInterface      $serializer = null,
                                private readonly ?Logger $logger = null
    )
    {
        $this->serializer = $serializer ?? new PhpSerializer();
        $this->connect();
    }

    private function createClient(): MqttClient
    {
        $repository = new MemoryRepository();

        try {
            return new MqttClient(
                $this->options['host'],
                $this->options['port'],
                $this->options['client_id'],
                MqttClient::MQTT_3_1_1,
                $repository,
                $this->logger
            );
        } catch (ProtocolNotSupportedException $e) {
            throw new \LogicException($e->getMessage());
        }
    }

    private function connect(): void
    {
        if (null === $this->client || ! $this->connected) {
            $this->client = $this->createClient();
            $this->logger?->log(Level::Info, 'Connect', ['client_connected' =>var_export($this->client->isConnected(), true)]);
        }

        $setting = (new ConnectionSettings())
            ->setUsername($this->options['username'])
            ->setPassword($this->options['password'])
            ->setConnectTimeout(1)
            ->setUseTls(false)
            ->setTlsSelfSignedAllowed(false);

        try {
            $this->client->connect($setting, true);
            $this->connected = true;
            $this->logger?->log(Level::Info, 'Connecting', [
                'last_will_message' => $setting->getLastWillMessage(),
                'last_will_topic'   => $setting->getLastWillTopic(),
                'client_connected'  => var_export($this->client->isConnected(), true),
            ]);

        } catch (ConfigurationInvalidException|ConnectingToBrokerFailedException $e) {
            throw new \LogicException($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function get(): iterable
    {
        $queue = [];

        foreach ($this->topics as $topic) {
            // Subscribe to a topic pattern.
            $this->client->subscribe($topic, function (string $topic, string $message, bool $retained) use (&$queue) {
                $queue[] = Envelope::wrap(new MqttMessage($topic, $message, 0, $retained));
            }, MqttClient::QOS_AT_MOST_ONCE);
        }

        // Manually loop once at a time and then yield all the queued messages.
        $loopStartedAt = \microtime(true);

        while (true) {
            $this->client->loopOnce($loopStartedAt, true);

            while ( ! empty($queue)) {
                yield \array_shift($queue);
            }
        }
    }

    public function stop(): void
    {
        $this->client->disconnect();
        // $this->connected = false;
    }

    /**
     * @inheritDoc
     */
    public function ack(Envelope $envelope): void
    {
    }

    /**
     * @inheritDoc
     */
    public function reject(Envelope $envelope): void
    {
    }

    /**
     * @inheritDoc
     */
    public function send(Envelope $envelope): Envelope
    {
        // $encodedMessage = $this->serializer->encode($envelope);
        //
        // // Only QoS 0 can be used because for other kinds of publishing, looping is required,
        // // which blocks the process (temporarily).
        // $this->client->publish(self::$topicToPublishTo, $encodedMessage);
        //
        // return $envelope;

        if ($envelope->getMessage() instanceof MqttMessageInterface) {
            $this->client->publish($envelope->getMessage()->getTopic(), $envelope->getMessage()->getContent(), $envelope->getMessage()->getQos(), $envelope->getMessage()->getRetain());
        }

        return $envelope;
    }
}