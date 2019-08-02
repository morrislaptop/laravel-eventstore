<?php

namespace DigitalRisks\LaravelEventStore;

use Illuminate\Console\Command;

use EventLoop\EventLoop;
use Rxnet\EventStore\EventStore;
use Rxnet\EventStore\Record\AcknowledgeableEventRecord;
use Rxnet\EventStore\Record\EventRecord;

class EventStoreWorker extends Command
{
    private $loop;

    private $timeout = 10;

    protected $signature = 'eventstore:worker';

    protected $description = 'Worker handling incoming events from ES';

    protected $eventstore;

    public function __construct()
    {
        parent::__construct();

        $this->loop = EventLoop::getLoop();
    }

    public function handle()
    {
        $this->loop->stop();

        try {
            $this->processAllStreams();
            $this->loop->run();
        } catch (\Exception $e) {
            report($e);
        }

        $this->error("Lost connection with EventStore - reconnecting in $this->timeout");

        sleep($this->timeout);

        $this->handle();
    }

    public function processAllStreams()
    {
        $streams = config('eventstore.streams');

        foreach ($streams as $stream) {
            $eventStore = new EventStore();
            $connection = $eventStore->connect(config('eventstore.tcp_url'));

            $connection->subscribe(function () use ($eventStore, $stream) {
                $this->processStream($eventStore, $stream);
            }, 'report');
        }
    }

    private function processStream($eventStore, string $stream)
    {
        $eventStore
            ->persistentSubscription($stream, config('eventstore.group'))
            ->subscribe(function (AcknowledgeableEventRecord $event) {
                $url = config('eventstore.http_url')."/streams/{$event->getStreamId()}/{$event->getNumber()}";
                $this->info($url);

                try {
                    $this->dispatch($event);
                    $event->ack();
                } catch (\Exception $e) {
                    dump([
                        'id' => $event->getId(),
                        'number' => $event->getNumber(),
                        'stream' => $event->getStreamId(),
                        'type' => $event->getType(),
                        'created' => $event->getCreated(),
                        'data' => $event->getData(),
                        'metadata' => $event->getMetadata(),
                    ]);

                    $event->nack();

                    report($e);
                }
            }, 'report');
    }
    
    protected function makeSerializableEvent(AcknowledgeableEventRecord $ack)
    {
        $event = new DataEventRecord();
        $created = new Carbon($ack->getCreated());

        $event->setEventType($ack->getType());
        $event->setCreatedEpoch($created->getTimestamp() * 1000);
        $event->setData(json_encode($ack->getData()));
        $event->setMetadata(json_encode($ack->getMetadata()));

        return new JsonEventRecord($event);
    }

    public function dispatch(EventRecord $event)
    {
        $event = $this->makeSerializableEvent($event);
        $type = $event->getType();
        $class = config('eventstore.namespace') . '\\' . $type;

        class_exists($class) ? event(new $class($event)) : event($type, $event);
    }
}
