<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace Test\Encryption;


use OC\Encryption\DecryptAll;
use OC\Encryption\Exceptions\DecryptionFailedException;
use OC\Encryption\Manager;
use OC\Files\FileInfo;
use OC\Files\View;
use OCA\Files_Sharing\SharedStorage;
use OCP\Encryption\IEncryptionModule;
use OCP\ILogger;
use OCP\IUserManager;
use OCP\UserInterface;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;

/**
 * Class DecryptAllTest
 *
 * @group DB
 *
 * @package Test\Encryption
 */
class DecryptAllTest extends TestCase {

	/** @var \PHPUnit_Framework_MockObject_MockObject | IUserManager */
	protected $userManager;

	/** @var \PHPUnit_Framework_MockObject_MockObject | Manager */
	protected $encryptionManager;

	/** @var \PHPUnit_Framework_MockObject_MockObject | View */
	protected $view;

	/** @var \PHPUnit_Framework_MockObject_MockObject | ILogger */
	protected $logger;

	/** @var  \PHPUnit_Framework_MockObject_MockObject | \Symfony\Component\Console\Input\InputInterface */
	protected $inputInterface;

	/** @var  \PHPUnit_Framework_MockObject_MockObject | \Symfony\Component\Console\Output\OutputInterface */
	protected $outputInterface;

	/** @var  \PHPUnit_Framework_MockObject_MockObject | \OCP\UserInterface */
	protected $userInterface;

	/** @var  DecryptAll */
	protected $instance;

	public function setUp() {
		parent::setUp();

		$this->userManager = $this->getMockBuilder(IUserManager::class)
			->disableOriginalConstructor()->getMock();
		$this->encryptionManager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()->getMock();
		$this->view = $this->getMockBuilder(View::class)
			->disableOriginalConstructor()->getMock();
		$this->logger = $this->getMockBuilder(ILogger::class)
			->disableOriginalConstructor()->getMock();
		$this->inputInterface = $this->getMockBuilder(InputInterface::class)
			->disableOriginalConstructor()->getMock();
		$this->outputInterface = $this->getMockBuilder(OutputInterface::class)
			->disableOriginalConstructor()->getMock();
		$this->userInterface = $this->getMockBuilder(UserInterface::class)
			->disableOriginalConstructor()->getMock();

		$this->outputInterface->expects($this->any())->method('getFormatter')
			->willReturn($this->createMock(OutputFormatterInterface::class));

		$this->instance = new DecryptAll($this->encryptionManager, $this->userManager, $this->view, $this->logger);

		$this->invokePrivate($this->instance, 'input', [$this->inputInterface]);
		$this->invokePrivate($this->instance, 'output', [$this->outputInterface]);
	}

	public function dataDecryptAll() {
		return [
			[true, 'user1', true],
			[false, 'user1', true],
			[true, '0', true],
			[false, '0', true],
			[true, '', false],
		];
	}

	/**
	 * @dataProvider dataDecryptAll
	 * @param bool $prepareResult
	 * @param string $user
	 * @param bool $userExistsChecked
	 */
	public function testDecryptAll($prepareResult, $user, $userExistsChecked) {

		if ($userExistsChecked) {
			$this->userManager->expects($this->once())->method('userExists')->willReturn(true);
		} else {
			$this->userManager->expects($this->never())->method('userExists');
		}
		/** @var DecryptAll | \PHPUnit_Framework_MockObject_MockObject |  $instance */
		$instance = $this->getMockBuilder(DecryptAll::class)
			->setConstructorArgs(
				[
					$this->encryptionManager,
					$this->userManager,
					$this->view,
					$this->logger
				]
			)
			->setMethods(['prepareEncryptionModules', 'decryptAllUsersFiles'])
			->getMock();


		\OC::$server->getAppConfig()->setValue('encryption', 'useMasterKey', '1');

		$instance->expects($this->once())
			->method('prepareEncryptionModules')
			->with($user)
			->willReturn($prepareResult);

		if ($prepareResult) {
			$instance->expects($this->once())
				->method('decryptAllUsersFiles')
				->with($user);
		} else {
			$instance->expects($this->never())->method('decryptAllUsersFiles');
		}

		$instance->decryptAll($this->inputInterface, $this->outputInterface, $user);

		\OC::$server->getAppConfig()->deleteKey('encryption', 'useMasterKey');
	}

	/**
	 * test decrypt all call with a user who doesn't exists
	 */
	public function testDecryptAllWrongUser() {
		$this->userManager->expects($this->once())->method('userExists')->willReturn(false);
		$this->outputInterface->expects($this->once())->method('writeln')
			->with('User "user1" does not exist. Please check the username and try again');

		$this->assertFalse(
			$this->instance->decryptAll($this->inputInterface, $this->outputInterface, 'user1')
		);
	}

	public function dataTrueFalse() {
		return [
			[true],
			[false],
		];
	}

	/**
	 * @dataProvider dataTrueFalse
	 * @param bool $success
	 */
	public function testPrepareEncryptionModules($success) {

		$user = 'user1';

		$dummyEncryptionModule = $this->getMockBuilder(IEncryptionModule::class)
			->disableOriginalConstructor()->getMock();

		$dummyEncryptionModule->expects($this->once())
			->method('prepareDecryptAll')
			->with($this->inputInterface, $this->outputInterface, $user)
			->willReturn($success);

		$callback = function() use ($dummyEncryptionModule) {return $dummyEncryptionModule;};
		$moduleDescription = [
			'id' => 'id',
			'displayName' => 'displayName',
			'callback' => $callback
		];

		$this->encryptionManager->expects($this->once())
			->method('getEncryptionModules')
			->willReturn([$moduleDescription]);

		$this->assertSame($success,
			$this->invokePrivate($this->instance, 'prepareEncryptionModules', [$user])
		);
	}

	/**
	 * @dataProvider dataTestDecryptAllUsersFiles
	 */
	public function testDecryptAllUsersFiles($user) {

		/** @var DecryptAll | \PHPUnit_Framework_MockObject_MockObject |  $instance */
		$instance = $this->getMockBuilder(DecryptAll::class)
			->setConstructorArgs(
				[
					$this->encryptionManager,
					$this->userManager,
					$this->view,
					$this->logger
				]
			)
			->setMethods(['decryptUsersFiles'])
			->getMock();

		$this->invokePrivate($instance, 'input', [$this->inputInterface]);
		$this->invokePrivate($instance, 'output', [$this->outputInterface]);

		if (empty($user)) {
			$this->userManager->expects($this->once())
				->method('getBackends')
				->willReturn([$this->userInterface]);
			$this->userInterface->expects($this->any())
				->method('getUsers')
				->willReturn(['user1', 'user2']);
			$instance->expects($this->at(0))
				->method('decryptUsersFiles')
				->with('user1');
			$instance->expects($this->at(1))
				->method('decryptUsersFiles')
				->with('user2');
		} else {
			$instance->expects($this->once())
				->method('decryptUsersFiles')
				->with($user);
		}

		$this->invokePrivate($instance, 'decryptAllUsersFiles', [$user]);
	}

	public function dataTestDecryptAllUsersFiles() {
		return [
			['user1'],
			['']
		];
	}

	public function testDecryptUsersFiles() {
		$storage = $this->getMockBuilder(SharedStorage::class)
			->disableOriginalConstructor()
			->getMock();

		/** @var DecryptAll | \PHPUnit_Framework_MockObject_MockObject  $instance */
		$instance = $this->getMockBuilder(DecryptAll::class)
			->setConstructorArgs(
				[
					$this->encryptionManager,
					$this->userManager,
					$this->view,
					$this->logger
				]
			)
			->setMethods(['decryptFile'])
			->getMock();

		$this->view->expects($this->at(0))->method('getDirectoryContent')
			->with('/user1/files')->willReturn(
				[
					new FileInfo('path', $storage, 'intPath', ['name' => 'foo', 'type'=>'dir'], null),
					new FileInfo('path', $storage, 'intPath', ['name' => 'bar', 'type'=>'file', 'encrypted'=>true], null)
				]
			);

		$this->view->expects($this->at(3))->method('getDirectoryContent')
			->with('/user1/files/foo')->willReturn(
				[
					new FileInfo('path', $storage, 'intPath', ['name' => 'subfile', 'type'=>'file', 'encrypted'=>true], null)
				]
			);

		$this->view->expects($this->any())->method('is_dir')
			->willReturnCallback(
				function($path) {
					if ($path === '/user1/files/foo') {
						return true;
					}
					return false;
				}
			);

		$instance->expects($this->at(0))
			->method('decryptFile')
			->with('/user1/files/bar');
		$instance->expects($this->at(1))
			->method('decryptFile')
			->with('/user1/files/foo/subfile');

		$progressBar = $this->getMockBuilder(ProgressBar::class)
			->disableOriginalConstructor()->getMock();

		$this->invokePrivate($instance, 'decryptUsersFiles', ['user1', $progressBar, '']);

	}

	public function testDecryptFile() {

		$path = 'test.txt';

		/** @var DecryptAll | \PHPUnit_Framework_MockObject_MockObject  $instance */
		$instance = $this->getMockBuilder(DecryptAll::class)
			->setConstructorArgs(
				[
					$this->encryptionManager,
					$this->userManager,
					$this->view,
					$this->logger
				]
			)
			->setMethods(['getTimestamp'])
			->getMock();

		$instance->expects($this->any())->method('getTimestamp')->willReturn(42);

		$this->view->expects($this->once())
			->method('copy')
			->with($path, $path . '.decrypted.42');
		$this->view->expects($this->once())
			->method('rename')
			->with($path . '.decrypted.42', $path);

		$this->assertTrue(
			$this->invokePrivate($instance, 'decryptFile', [$path])
		);
	}

	public function testDecryptFileFailure() {
		$path = 'test.txt';

		/** @var DecryptAll | \PHPUnit_Framework_MockObject_MockObject  $instance */
		$instance = $this->getMockBuilder(DecryptAll::class)
			->setConstructorArgs(
				[
					$this->encryptionManager,
					$this->userManager,
					$this->view,
					$this->logger
				]
			)
			->setMethods(['getTimestamp'])
			->getMock();

		$instance->expects($this->any())->method('getTimestamp')->willReturn(42);

		$this->view->expects($this->once())
			->method('copy')
			->with($path, $path . '.decrypted.42')
			->willReturnCallback(function() { throw new DecryptionFailedException();});

		$this->view->expects($this->never())->method('rename');
		$this->view->expects($this->once())
			->method('file_exists')
			->with($path . '.decrypted.42')
			->willReturn(true);
		$this->view->expects($this->once())
			->method('unlink')
			->with($path . '.decrypted.42');

		$this->assertFalse(
			$this->invokePrivate($instance, 'decryptFile', [$path])
		);
	}

}
