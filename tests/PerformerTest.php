<?php

/**
 * Created by PhpStorm.
 * User: nms
 * Date: 28.06.16
 * Time: 17:49
 */
class PerformerTest extends PHPUnit_Framework_TestCase
{

    /**
     * @var resource
     */
    protected static $pulsarProcess;

    /**
     * @var array
     */
    protected static $pipes = [];

    /**
     * @var \React\PublisherPulsar\Performer
     */
    protected static $performer;

    /**
     * Initialize Pulsar (ReplyStack) and Performer for connection
     */
    public static function setUpBeforeClass()
    {
        self::$performer = new \React\PublisherPulsar\Performer();

        $performerDto = new \React\PublisherPulsar\Inventory\PerformerDto();
        $performerDto->setModuleName("PerformerTest");

        self::$performer->setPerformerDto($performerDto);
        self::$performer->initDefaultPerformerSocketsParams();

        $dir = __DIR__;

        $cmd = "php $dir/Inventory/PulsarCommand.php iterationsLimit=10";

        $fdSpec = [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w'], // stderr
        ];

        self::$pulsarProcess = proc_open($cmd, $fdSpec, self::$pipes);
    }

    /**
     * @throws \React\PublisherPulsar\Inventory\Exceptions\PublisherPulsarException
     */
    public function testRequestForActionPermission()
    {
        sleep(2);

        self::$performer->requestForActionPermission();

        $this->assertInstanceOf(\React\PublisherPulsar\Inventory\BecomeTheSubscriberReplyDto::class,
            self::$performer->getBecomeTheSubscriberReplyDto());
    }

    /**
     * @throws \React\PublisherPulsar\Inventory\Exceptions\PublisherPulsarException
     */
    public function testWaitAllowingSubscriptionMessage()
    {
        sleep(2);

        self::$performer->waitAllowingSubscriptionMessage();

        $this->assertInstanceOf(\React\PublisherPulsar\Inventory\PublisherToSubscribersDto::class,
            self::$performer->getPublisherToSubscribersDto());
    }

    /**
     * Stop Pulsar (ReplyStack)
     */
    public static function tearDownAfterClass()
    {
        foreach (self::$pipes as $pipe) {
            fclose($pipe);
        }

        proc_close(self::$pulsarProcess);
    }

}