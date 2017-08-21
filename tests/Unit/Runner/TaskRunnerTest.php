<?php

/*
 * This file is part of php-task library.
 *
 * (c) php-task
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Task\Tests\Unit\Runner;

use Prophecy\Argument;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Task\Event\Events;
use Task\Event\TaskExecutionEvent;
use Task\Execution\TaskExecution;
use Task\Executor\ExecutorInterface;
use Task\Handler\TaskHandlerInterface;
use Task\Runner\ExecutionFinderInterface;
use Task\Runner\TaskRunner;
use Task\Runner\TaskRunnerInterface;
use Task\Storage\TaskExecutionRepositoryInterface;
use Task\TaskInterface;
use Task\TaskStatus;

/**
 * Tests for TaskRunner.
 */
class TaskRunnerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var TaskExecutionRepositoryInterface
     */
    private $taskExecutionRepository;

    /**
     * @var ExecutionFinderInterface
     */
    private $executionFinder;

    /**
     * @var ExecutorInterface
     */
    private $executor;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var TaskRunnerInterface
     */
    private $taskRunner;

    protected function setUp()
    {
        $this->taskExecutionRepository = $this->prophesize(TaskExecutionRepositoryInterface::class);
        $this->executionFinder = $this->prophesize(ExecutionFinderInterface::class);
        $this->executor = $this->prophesize(ExecutorInterface::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $this->taskRunner = new TaskRunner(
            $this->taskExecutionRepository->reveal(),
            $this->executionFinder->reveal(),
            $this->executor->reveal(),
            $this->eventDispatcher->reveal()
        );
    }

    public function testRunTasks()
    {
        $task = $this->createTask();
        $executions = [
            $this->createTaskExecution($task, new \DateTime(), 'Test 1')->setStatus(TaskStatus::PLANNED),
            $this->createTaskExecution($task, new \DateTime(), 'Test 2')->setStatus(TaskStatus::PLANNED),
        ];

        $this->taskExecutionRepository->save($executions[0])->willReturnArgument(0)->shouldBeCalledTimes(2);
        $this->taskExecutionRepository->save($executions[1])->willReturnArgument(0)->shouldBeCalledTimes(2);

        $this->executor->execute($executions[0])->willReturn(strrev('Test 1'));
        $this->executor->execute($executions[1])->willReturn(strrev('Test 2'));

        $this->executionFinder->find()->willReturn($executions);

        $this->initializeDispatcher($this->eventDispatcher, $executions[0]);
        $this->initializeDispatcher($this->eventDispatcher, $executions[1]);

        $this->taskRunner->runTasks();

        $this->assertLessThanOrEqual(new \DateTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual(new \DateTime(), $executions[1]->getStartTime());
        $this->assertLessThanOrEqual($executions[1]->getStartTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual($executions[0]->getEndTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual($executions[1]->getEndTime(), $executions[1]->getStartTime());
        $this->assertGreaterThan(0, $executions[0]->getDuration());
        $this->assertGreaterThan(0, $executions[1]->getDuration());
        $this->assertEquals(strrev('Test 1'), $executions[0]->getResult());
        $this->assertEquals(strrev('Test 2'), $executions[1]->getResult());
        $this->assertEquals(TaskStatus::COMPLETED, $executions[0]->getStatus());
        $this->assertEquals(TaskStatus::COMPLETED, $executions[1]->getStatus());
    }

    public function testRunTasksFailed()
    {
        $task = $this->createTask();
        $executions = [
            $this->createTaskExecution($task, new \DateTime(), 'Test 1')->setStatus(TaskStatus::PLANNED),
            $this->createTaskExecution($task, new \DateTime(), 'Test 2')->setStatus(TaskStatus::PLANNED),
        ];

        $this->taskExecutionRepository->save($executions[0])->willReturnArgument(0)->shouldBeCalledTimes(2);
        $this->taskExecutionRepository->save($executions[1])->willReturnArgument(0)->shouldBeCalledTimes(2);

        $this->executor->execute($executions[0])->willThrow(new \Exception());
        $this->executor->execute($executions[1])->willReturn(strrev('Test 2'));

        $this->executionFinder->find()->willReturn($executions);

        $this->initializeDispatcher($this->eventDispatcher, $executions[0], Events::TASK_FAILED);
        $this->initializeDispatcher($this->eventDispatcher, $executions[1]);

        $this->taskRunner->runTasks();

        $this->assertLessThanOrEqual(new \DateTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual(new \DateTime(), $executions[1]->getStartTime());
        $this->assertLessThanOrEqual($executions[1]->getStartTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual($executions[0]->getEndTime(), $executions[0]->getStartTime());
        $this->assertLessThanOrEqual($executions[1]->getEndTime(), $executions[1]->getStartTime());
        $this->assertGreaterThan(0, $executions[0]->getDuration());
        $this->assertGreaterThan(0, $executions[1]->getDuration());
        $this->assertNull($executions[0]->getResult());
        $this->assertNotNull($executions[0]->getException());
        $this->assertEquals(strrev('Test 2'), $executions[1]->getResult());
        $this->assertNull($executions[1]->getException());
        $this->assertEquals(TaskStatus::FAILED, $executions[0]->getStatus());
        $this->assertEquals(TaskStatus::COMPLETED, $executions[1]->getStatus());
    }

    private function initializeDispatcher($eventDispatcher, $execution, $event = Events::TASK_PASSED)
    {
        $eventDispatcher->dispatch(
            Events::TASK_BEFORE,
            Argument::that(
                function (TaskExecutionEvent $event) use ($execution) {
                    return $event->getTaskExecution() === $execution;
                }
            )
        )->will(
            function () use ($eventDispatcher, $execution, $event) {
                $eventDispatcher->dispatch(
                    Events::TASK_AFTER,
                    Argument::that(
                        function (TaskExecutionEvent $event) use ($execution) {
                            return $event->getTaskExecution() === $execution;
                        }
                    )
                )->shouldBeCalled();
                $eventDispatcher->dispatch(
                    $event,
                    Argument::that(
                        function (TaskExecutionEvent $event) use ($execution) {
                            return $event->getTaskExecution() === $execution;
                        }
                    )
                )->shouldBeCalled();
                $eventDispatcher->dispatch(
                    Events::TASK_FINISHED,
                    Argument::that(
                        function (TaskExecutionEvent $event) use ($execution) {
                            return $event->getTaskExecution() === $execution;
                        }
                    )
                )->shouldBeCalled();
            }
        );
    }

    private function createTask()
    {
        $task = $this->prophesize(TaskInterface::class);

        return $task->reveal();
    }

    private function createTaskExecution(
        TaskInterface $task,
        $scheduleTime,
        $workload = 'Test-Workload',
        $handlerClass = TestHandler::class
    ) {
        $execution = new TaskExecution($task, $handlerClass, $scheduleTime, $workload);

        $this->taskExecutionRepository->findByUuid($execution->getUuid())->willReturn($execution);

        return $execution;
    }
}

class TestHandler implements TaskHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle($workload)
    {
        return strrev($workload);
    }
}
