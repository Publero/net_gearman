<?php
namespace Net\Gearman\Tests;

use Net\Gearman\Worker;

class WorkerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Worker
     */
    protected $worker;

    public function setUp()
    {
        $this->worker = new Worker();
    }

    public function testAddFunction()
    {
        $gearmanFunctionName = 'reverse';
        $callback = function ($job) {
            return $job->workload();
        };

        $this->worker->addFunction($gearmanFunctionName, $callback);

        $expectedFunctions = array(
            $gearmanFunctionName => array(
                'callback' => $callback
            )
        );

        $this->assertEquals($expectedFunctions, $this->worker->getFunctions());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testAddFunctionThrowsExceptionIfFunctionIsAlreadyRegistered()
    {
        $this->worker->addFunction('gearmanFunction', 'echo');
        $this->worker->addFunction('gearmanFunction', 'var_dump');
    }

    public function testUnregister()
    {
        $gearmanFunctionName = 'reverse';
        $gearmanFunctionNameSecond = 'reverse2';
        $callback = function ($job) {
            return $job->workload();
        };

        $timeout = 10;
        $this->worker
            ->addFunction($gearmanFunctionName, $callback)
            ->addFunction($gearmanFunctionNameSecond, $callback, $timeout)
        ;

        $this->assertCount(2, $this->worker->getFunctions());

        $this->worker->unregister($gearmanFunctionName);
        $expectedFunctions = array(
            $gearmanFunctionNameSecond => array(
                'callback' => $callback,
                'timeout' => $timeout
            )
        );

        $this->assertCount(1, $this->worker->getFunctions());
        $this->assertEquals($expectedFunctions, $this->worker->getFunctions());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testUnregisterThrowsExceptionIfFunctionDoesNotExist()
    {
        $this->worker->unregister('gearmanFunction');
    }

    public function testUnregisterAll()
    {
        $gearmanFunctionName = 'reverse';
        $gearmanFunctionNameSecond = 'reverse2';

        $this->worker->addFunction($gearmanFunctionName, 'echo');
        $this->worker->addFunction($gearmanFunctionNameSecond, 'echo');

        $this->assertCount(2, $this->worker->getFunctions());

        $this->worker->unregisterAll();

        $this->assertCount(0, $this->worker->getFunctions());
    }

    /*public function testWorker()
    {
        $function = function($payload) {
            $result = str_replace('java', 'php', $payload);
            file_put_contents('/home/hlavac/result.txt', $result . "\n", FILE_APPEND);

            return str_replace('java', 'php', $payload);
        };

        $worker = new Worker();
        $worker->addServer();
        $worker->addFunction('replace', $function);

        $worker->work();
    }*/
}
