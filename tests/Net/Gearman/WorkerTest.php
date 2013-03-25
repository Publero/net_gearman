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

    public function testWorker()
    {
        return $this->markTestSkipped('Skipped. You can try this test on your machine with gearman running.');

        $function = function($payload) {
            $result = str_replace('java', 'php', $payload);

            return str_replace('java', 'php', $payload);
        };

        $function2 = function($payload) {
            while (false !== strpos($payload, 'java')) {
                $payload = preg_replace('/java/', 'php', $payload, 1);
                sleep(1);
            }

            return $payload;
        };

        $worker = new Worker();
        $worker->addServer();
        $worker->addFunction('replace', $function);
        $worker->addFunction('long_task', $function2);

        $worker->work();
    }
}
