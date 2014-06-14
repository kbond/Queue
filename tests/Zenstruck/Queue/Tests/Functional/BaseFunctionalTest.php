<?php

namespace Zenstruck\Queue\Tests\Functional;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Zenstruck\Queue\Adapter;
use Zenstruck\Queue\EventListener\LoggableSubscriber;
use Zenstruck\Queue\Queue;
use Zenstruck\Queue\Tests\Fixtures\TestConsumer;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
abstract class BaseFunctionalTest extends \PHPUnit_Framework_TestCase
{
    public function testConsume()
    {
        $consumer = new TestConsumer();

        $queue = new Queue($this->createAdapter(), new EventDispatcher());
        $queue->addConsumer($consumer);

        $this->assertFalse($queue->consume(), 'Empty queue');
        $this->assertNull($consumer->getJob(), 'Not yet set');

        $queue->push('foo', 'foo message');
        $this->pushInvalidData();
        $queue->push('bar', 'bar message');

        $this->assertTrue($queue->consume(), 'Consume foo');
        $this->assertSame('foo', $consumer->getJob()->getData());

        $this->assertFalse($queue->consume(), 'Consume invalid');

        $this->assertTrue($queue->consume(), 'Consume bar');
        $this->assertSame('bar', $consumer->getJob()->getData());

        $this->assertFalse($queue->consume(), 'Consume invalid');
    }

    public function testConsumeWithDelay()
    {
        $consumer = new TestConsumer();

        $queue = new Queue($this->createAdapter(), new EventDispatcher());
        $queue->addConsumer($consumer);

        $queue->push('foo', 'foo message', array(), 1);
        $queue->push('bar', 'foo message', array());

        $this->assertTrue($queue->consume(), 'Consume bar');
        $this->assertSame('bar', $consumer->getJob()->getData());

        $this->assertFalse($queue->consume(), 'Message is delayed');

        sleep(2);

        $this->assertTrue($queue->consume(), 'Consume foo');
        $this->assertSame('foo', $consumer->getJob()->getData());

        $this->assertFalse($queue->consume(), 'Empty queue');
    }

    public function testAttempts()
    {
        $consumer = new TestConsumer(TestConsumer::ACTION_FAIL);

        $queue = new Queue($this->createAdapter(), new EventDispatcher());
        $queue->addConsumer($consumer);

        $queue->push('foo', 'foo message', array());
        $queue->consume();

        $this->assertSame(1, $consumer->getJob()->getAttempts());

        $queue->consume();

        $this->assertSame(2, $consumer->getJob()->getAttempts());
    }

    public function testLoggableSubscriber()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger
            ->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                array('PUSHING: "foo message"'),
                array('CONSUMING: "foo message"')
            )
        ;

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LoggableSubscriber($logger));

        $queue = new Queue($this->createAdapter(), $dispatcher);

        $queue->push('foo', 'foo message', array());
        $queue->consume();
    }

    public function testLoggableSubscriberWithRequeue()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger
            ->expects($this->exactly(3))
            ->method('info')
            ->withConsecutive(
                array('PUSHING: "foo message"'),
                array('CONSUMING: "foo message"'),
                array('REQUEUING: "foo message"')
            )
        ;

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LoggableSubscriber($logger));

        $queue = new Queue($this->createAdapter(), $dispatcher);
        $queue->addConsumer(new TestConsumer(TestConsumer::ACTION_REQUEUE));

        $queue->push('foo', 'foo message', array());
        $queue->consume();
    }

    public function testLoggableSubscriberWithFail()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger
            ->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                array('PUSHING: "foo message"'),
                array('CONSUMING: "foo message"')
            )
        ;

        $logger
            ->expects($this->exactly(1))
            ->method('error')
            ->with('FAILED CONSUMING: "foo message", REASON: "Fail!"')
        ;

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LoggableSubscriber($logger));

        $queue = new Queue($this->createAdapter(), $dispatcher);
        $queue->addConsumer(new TestConsumer(TestConsumer::ACTION_FAIL));

        $queue->push('foo', 'foo message', array());
        $queue->consume();
    }

    /**
     * @return Adapter
     */
    abstract protected function createAdapter();

    /**
     * Pushes Invalid data to the queue.
     */
    abstract protected function pushInvalidData();
}
