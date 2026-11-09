<?php

App::uses('QueueShell', 'Queue.Console/Command');
App::uses('CakeTestCase', 'TestSuite');

class QueueShellTest extends CakeTestCase {

	public $QueueShell;

/**
 * Fixtures to load
 *
 * @var array
 */
	public $fixtures = [
		'plugin.queue.queued_task',
		// QueuedTask reads its AWS keys from Symphosize.Setting when it is built; the app's fixture runs on MySQL 8.
		'app.setting',
	];

	public function setUp() {
		parent::setUp();

		$this->QueueShell = new TestQueueShell();
		$this->QueueShell->initialize();
		$this->QueueShell->loadTasks();

		Configure::write('Queue', [
			'sleeptime' => 2,
			'gcprob' => 10,
			'defaultworkertimeout' => 3,
			'defaultworkerretries' => 1,
			'workermaxruntime' => 5,
			'cleanuptimeout' => 10,
			'exitwhennothingtodo' => false,
			'pidfilepath' => TMP . 'queue' . DS,
			'log' => false,
		]);
	}

/**
 * QueueShellTest::testObject()
 *
 * @return void
 */
	public function testObject() {
		$this->assertTrue(is_object($this->QueueShell));
		$this->assertInstanceOf('QueueShell', $this->QueueShell);
	}

/**
 * QueueShellTest::testStats()
 *
 * @return void
 */
	public function testStats() {
		$result = $this->QueueShell->stats();
		//debug($this->QueueShell->out);
		$this->assertTrue(in_array('Total unfinished Jobs      : 0', $this->QueueShell->out));
	}

/**
 * QueueShellTest::testSettings()
 *
 * @return void
 */
	public function testSettings() {
		$result = $this->QueueShell->settings();
		$this->assertTrue(in_array('* cleanuptimeout: 10', $this->QueueShell->out));
	}

/**
 * QueueShellTest::testAddInexistent()
 *
 * @return void
 */
	public function testAddInexistent() {
		$this->QueueShell->args[] = 'Foo';
		$result = $this->QueueShell->add();
		$this->assertTrue(in_array('Error: Task not Found: Foo', $this->QueueShell->out));
	}

/**
 * QueueShellTest::testAdd()
 *
 * @return void
 */
	public function testAdd() {
		$this->QueueShell->args[] = 'Example';
		$result = $this->QueueShell->add();
		//debug($this->QueueShell->out);
		$this->assertEmpty($this->QueueShell->out);

		$result = $this->QueueShell->runworker();
		//debug($this->QueueShell->out);
		$this->assertTrue(in_array('Running Job of type "Example"', $this->QueueShell->out));
	}

	/**
	 * QueueShellTest::testRetry()
	 *
	 * @return void
	 */
	public function testRetry() {
		$this->QueueShell->args[] = 'RetryExample';
		$result = $this->QueueShell->add();
		$this->assertEmpty($this->QueueShell->out);

		$result = $this->QueueShell->runworker();
		//debug($this->QueueShell->out);
		$this->assertTrue(in_array('Job did not finish, requeued.', $this->QueueShell->out));
	}

/**
 * A task that throws an Error, and a message for a task this image does not have,
 * each fail their own job and the SQS worker moves on instead of exiting.
 * A failureMessage left on a reused task by an earlier job never reaches a later job.
 *
 * @return void
 */
	public function testRunworkersqsFailsJobOnThrowableAndKeepsRunning() {
		$this->QueueShell->QueueThrowsTypeError = new ThrowsTypeErrorTestTask();
		$this->QueueShell->QueueReturnsFalse = new ReturnsFalseTestTask();
		$throwing = ['id' => 1, 'jobtype' => 'ThrowsTypeError', 'data' => null, 'sqsReceiptHandle' => 'r1'];
		$missing = ['id' => 2, 'jobtype' => 'NotInThisImage', 'data' => null, 'sqsReceiptHandle' => 'r2'];
		$returnsFalse = ['id' => 3, 'jobtype' => 'ReturnsFalse', 'data' => null, 'sqsReceiptHandle' => 'r3'];

		$QueuedTask = $this->getMock('QueuedTask', ['requestSqsJob', 'markJobFailedSqs', 'markJobDoneSqs', 'cleanOldJobs']);
		$QueuedTask->expects($this->exactly(4))->method('requestSqsJob')->will($this->returnCallback(
			function () use ($QueuedTask, $throwing, $missing, $returnsFalse) {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					return $throwing;
				}
				if ($calls === 2) {
					return $missing;
				}
				if ($calls === 3) {
					return $returnsFalse;
				}
				$QueuedTask->exit = true;
				return [];
			}
		));
		$QueuedTask->expects($this->never())->method('markJobDoneSqs');
		$failed = [];
		$QueuedTask->expects($this->exactly(3))->method('markJobFailedSqs')->will($this->returnCallback(
			function ($job, $queueUrl, $failureMessage) use (&$failed) {
				$failed[] = [$job['id'], $queueUrl, $failureMessage];
			}
		));
		$this->QueueShell->QueuedTask = $QueuedTask;
		$this->QueueShell->args = ['queue-url'];

		$this->QueueShell->runworkersqs();

		$this->assertSame([1, 'queue-url'], array_slice($failed[0], 0, 2));
		// The leftover failureMessage was cleared before run(), so the thrown error is stored.
		$this->assertStringStartsWith('TypeError: ', $failed[0][2]);
		$this->assertSame([2, 'queue-url'], array_slice($failed[1], 0, 2));
		$this->assertNotEmpty($failed[1][2]);
		$this->assertSame([3, 'queue-url', null], $failed[2]);
		$this->assertSame(3, count(array_keys($this->QueueShell->out, 'Job failed.')));
	}

}

class ThrowsTypeErrorTestTask {

	public $autoUnserialize = false;

	public $failureMessage = 'reason left over from an earlier job';

	public function run($data, $id) {
		$takesInt = function (int $value) {
		};
		$takesInt('not an int');
		return true;
	}

}

class ReturnsFalseTestTask {

	public $autoUnserialize = false;

	public $failureMessage = 'reason left over from an earlier job';

	public function run($data, $id) {
		return false;
	}

}

class TestQueueShell extends QueueShell {

	public $out = [];

	public function out($message = null, $newlines = 1, $level = Shell::NORMAL) {
		$this->out[] = $message;
	}

	public function hr($newlines = 0, $width = 63) {
	}

	protected function _getTaskConf() {
		parent::_getTaskConf();
		foreach ($this->_taskConf as &$conf) {
			$conf['timeout'] = 5;
			$conf['retries'] = 1;
		}

		return $this->_taskConf;
	}

}
