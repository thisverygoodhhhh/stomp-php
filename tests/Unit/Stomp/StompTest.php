<?php
namespace FuseSource\Tests\Unit;

use FuseSource\Stomp\Frame;
use FuseSource\Stomp\Stomp;
use PHPUnit_Framework_TestCase;
use ReflectionMethod;
/**
 *
 * Copyright 2005-2006 The Apache Software Foundation
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
/* vim: set expandtab tabstop=3 shiftwidth=3: */

/**
 * Stomp test case.
 *
 * @package Stomp
 * @author Jens Radtke <swefl.oss@fin-sn.de>
 */
class StompTest extends PHPUnit_Framework_TestCase
{
    /**
     * @expectedException FuseSource\Stomp\Exception\StompException
     */
    public function testConnectWillThrowExceptionIfUnexpectedFrameArrives()
    {
        $stomp = $this->getStompWithInjectedMockedConnectionReadResult(new Frame('FAIL'));
        $stomp->connect();
    }

    /**
     * @expectedException FuseSource\Stomp\Exception\StompException
     */
    public function testConnectWillThrowExceptionIfNoFrameWasRead()
    {
        $stomp = $this->getStompWithInjectedMockedConnectionReadResult(false);
        $stomp->connect();

    }

    public function testConnectWillDetermineRabbitMqDialect()
    {
        $connectFrame = new Frame('CONNECTED');
        $connectFrame->setHeader('session', '-');
        $connectFrame->setHeader('server', 'rabbitmq');

        $stomp = $this->getStompWithInjectedMockedConnectionReadResult($connectFrame);

        $stomp->connect();

        $this->assertInstanceOf('\FuseSource\Stomp\Protocol\RabbitMq', $stomp->getProtocol(), 'Unexpected Protocol.');
    }


    public function testConnectWillDetermineSessionIdAndUsesActiveMqAsDefaultDialect()
    {
        $connectFrame = new Frame('CONNECTED');
        $connectFrame->setHeader('session', 'your-session-id');
        $connectFrame->setHeader('server', 'not-supported');

        $stomp = $this->getStompWithInjectedMockedConnectionReadResult($connectFrame);

        $stomp->connect();

        $this->assertInstanceOf('\FuseSource\Stomp\Protocol\ActiveMq', $stomp->getProtocol(), 'Unexpected Protocol.');
        $this->assertEquals('your-session-id', $stomp->getSessionId(), 'Wrong session id.');
    }


    /**
     * @expectedException FuseSource\Stomp\Exception\StompException
     */
    public function testWaitForReceiptWillThrowExceptionOnIdMissmatch()
    {
        $receiptFrame = new Frame('RECEIPT');
        $receiptFrame->setHeader('receipt-id', 'not-matching-id');

        $stomp = $this->getStompWithInjectedMockedConnectionReadResult($receiptFrame);

        $waitForReceipt = new ReflectionMethod($stomp, '_waitForReceipt');
        $waitForReceipt->setAccessible(true);

        // expect a receipt for another id
        $waitForReceipt->invoke($stomp, 'your-id');
    }


    public function testWaitForReceiptWillReturnFalseIfConnectionReadTimeoutOccurs()
    {
        $stomp = $this->getStompWithInjectedMockedConnectionReadResult(false);

        $waitForReceipt = new ReflectionMethod($stomp, '_waitForReceipt');
        $waitForReceipt->setAccessible(true);

        // expect a receipt but get false
        $this->assertFalse(
            $waitForReceipt->invoke($stomp, 'your-id'),
            'If no frame was returned, wait for receipt should return false.'
        );
    }

    /**
     * Get stomp, configured to use a connection which will return the given result on read.
     *
     * @param mixed $readFrameResult
     * @return Stomp
     */
    protected function getStompWithInjectedMockedConnectionReadResult($readFrameResult)
    {
        $connection = $this->getMockBuilder('\FuseSource\Stomp\Connection')
            ->setMethods(array('readFrame', 'writeFrame'))
            ->disableOriginalConstructor()
            ->getMock();
        $connection
            ->expects($this->once())
            ->method('readFrame')
            ->will(
                $this->returnValue($readFrameResult)
            );

        return new Stomp($connection);
    }


    public function testSendWillAddDestinationAndHeaderToAnyFrameAndSetSyncState()
    {
        $stomp = $this->getStompMockWithSendFrameCatcher($lastSendFrame, $lastSyncState);

        // test default frame send
        $headers = array(
            'destination' => 'wrong-destination',
            'myheader' => 'myvalue',
        );
        $frame = new Frame('CMD', $headers, 'body');

        // MuT
        $stomp->send('correct-destination', $frame, $headers, true);

        // verify
        $this->assertInstanceOf('\FuseSource\Stomp\Frame', $lastSendFrame);
        $this->assertEquals($frame->command, $lastSendFrame->command, 'Send must not change frame command.');
        $this->assertEquals(
            'correct-destination',
            $lastSendFrame->headers['destination'], 'Send must override destination header.'
        );
        $this->assertEquals(
            'myvalue',
            $lastSendFrame->headers['myheader'], 'Send must keep headers from given frame.'
        );
        $this->assertTrue(
            $lastSyncState, 'Send must pass sync state.'
        );
    }

    public function testSendWillConvertStringToFrameBodyAndSetSyncState()
    {
        $stomp = $this->getStompMockWithSendFrameCatcher($lastSendFrame, $lastSyncState);

        // test data
        $headers = array(
            'destination' => 'wrong-destination',
            'myheader' => 'myvalue',
        );
        $framebody = 'body';

        // MuT
        $stomp->send('correct-destination', $framebody, $headers, false);

        // verify
        $this->assertInstanceOf('\FuseSource\Stomp\Frame', $lastSendFrame);
        $this->assertEquals('SEND', $lastSendFrame->command, 'Send must set SEND as frame command, if frame was text.');
        $this->assertEquals(
            'correct-destination',
            $lastSendFrame->headers['destination'], 'Send must override destination header.'
        );
        $this->assertEquals(
            'myvalue',
            $lastSendFrame->headers['myheader'], 'Send must keep headers from given frame.'
        );
        $this->assertEquals(
            'body',
            $lastSendFrame->body, 'Send must set message as Frame body.'
        );
        $this->assertFalse(
            $lastSyncState, 'Send must pass sync state.'
        );
    }


    /**
     * Get a stomp mock which will catch arguments passed to lasSendFrame and SyncState
     *
     * @param Frame $lastSendFrame reference to last send frame
     * @param mixed $lastSyncState reference to last syn argument
     * @return Stomp
     */
    protected function getStompMockWithSendFrameCatcher(&$lastSendFrame, &$lastSyncState)
    {
        $stomp = $this->getMockBuilder('\FuseSource\Stomp\Stomp')
            ->setMethods(array('sendFrame'))
            ->disableOriginalConstructor()
            ->getMock();

        $stomp->expects($this->any())
            ->method('sendFrame')
            ->will(
                $this->returnCallback(
                    function (Frame $frame, $sync) use (&$lastSendFrame, &$lastSyncState)
                    {
                        $lastSendFrame = $frame;
                        $lastSyncState = $sync;
                    }
                )
            );
        return $stomp;
    }



    public function testSendFrameWithSyncWillLeadToMessageWithReceiptHeader()
    {
        $connection = $this->getMockBuilder('\FuseSource\Stomp\Connection')
            ->setMethods(array('writeFrame', 'readFrame'))
            ->disableOriginalConstructor()
            ->getMock();

        $connection
            ->expects($this->once())
            ->method('readFrame')
            ->will(
                $this->returnValue(false)
            );

        $lastWriteFrame = null;
        $connection
            ->expects($this->once())
            ->method('writeFrame')
            ->will(
                $this->returnCallback(
                    function ($frame) use (&$lastWriteFrame) {
                        $lastWriteFrame = $frame;
                    }
                )
            );
        $stomp = $this->getMockBuilder('\FuseSource\Stomp\Stomp')
            ->setMethods(array('createConnection'))
            ->disableOriginalConstructor()
            ->getMock();
        $stomp->expects($this->once())->method('createConnection')->will($this->returnValue($connection));

        $stomp->__construct(null);



        $result = $stomp->sendFrame(new Frame(), true);

        $this->assertInstanceOf('\FuseSource\Stomp\Frame', $lastWriteFrame);
        $this->assertArrayHasKey('receipt', $lastWriteFrame->headers, 'Written frame should have a "receipt" header.');
        $this->assertFalse($result, 'SendFrame must return false if no receipt frame was returned!');
    }


    public function testWaitForReceiptWillQueueUpFramesWithNoReceiptCommand()
    {
        $connection = $this->getMockBuilder('\FuseSource\Stomp\Connection')
            ->setMethods(array('readFrame'))
            ->disableOriginalConstructor()
            ->getMock();

        $readFrames = array(
            new Frame('OTHER'),
            new Frame('OTHER-2'),
            new Frame('OTHER-3'),
        );

        $expectedFrames = array_values($readFrames);

        $readFrames[] = new Frame('RECEIPT', array('receipt-id' => 'my-id'));

        $connection
            ->expects($this->exactly(4))
            ->method('readFrame')
            ->will(
                $this->returnCallback(
                    function () use (&$readFrames) {
                        return array_shift($readFrames);
                    }
                )
            );

       $stomp = new Stomp($connection);


       $waitForReceipt = new ReflectionMethod($stomp, '_waitForReceipt');
       $waitForReceipt->setAccessible(true);

       $result = $waitForReceipt->invoke($stomp, 'my-id');

       $this->assertTrue($result, 'Wait for receipt must return true if correct receipt was received');

       $receivedFrames = array(
           $stomp->readFrame(),
           $stomp->readFrame(),
           $stomp->readFrame(),
       );

       foreach ($expectedFrames as $index => $frame) {
           $this->assertEquals(
               $frame, $receivedFrames[$index],
               'Frame must be the correct Frame from queued up frames in given order. (FIFO)'
            );
       }
    }
}
