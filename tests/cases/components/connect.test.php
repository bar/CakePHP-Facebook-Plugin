<?php
App::import('Component', 'Facebook.Connect');
App::import('Core', 'Controller');
App::import('Component', 'Auth');
App::import('Component', 'Session');
App::import('Lib', 'Facebook.FB');

class TestUser extends CakeTestModel {

	public $name = 'TestUser';
	public $data = null;
	public $useDbConfig = 'test_suite';
	public $useTable = false;

	public function save($data) {
		$this->data = $data;
		return $data;
	}

	public function hasField() {
		return true;
	}

	public function saveField($field, $facebook_id) {
		$this->facebookId = $facebook_id;
		return true;
	}

	public function findByFacebookId($id) {
		$this->facebookId = $id;
		return array();
	}
}

class TestUserHasOne extends CakeTestModel {

	public $name = 'TestUser';
	public $data = null;
	public $useDbConfig = 'test_suite';
	public $useTable = false;

	public function save($data) {
		$this->data = $data;
		return $data;
	}

	public function hasField() {
		return true;
	}

	public function field() {
		return false;
	}

	public function saveField($field, $facebook_id) {
		$this->facebookId = $facebook_id;
		return array(
			'TestUserHasOne' => array(
				'id' => 1,
				'facebook_id' => '12'
			)
		);
	}

	public function findByFacebookId($id) {
		$this->facebookId = $id;
		return array(
			'TestUserHasOne' => array(
				'id' => 1,
				'username' => 'test',
				'password' => 'password',
				'facebook_id' => ''
			)
		);
	}
}

class TestUserError extends CakeTestModel {

	public $name = 'TestUserError';
	public $data = null;
	public $useDbConfig = 'test_suite';
	public $useTable = false;

	public function save($data) {
		$this->data = $data;
		return $data;
	}

	public function hasField() {
		return false;
	}

	public function saveField($field, $facebook_id) {
		$this->facebookId = $facebook_id;
		return true;
	}

	public function findByFacebookId($id) {
		$this->facebookId = $id;
		return array();
	}
}

class TestCallbackController extends Controller {

	public function beforeFacebookSave() {
		return false;
	}

	public function beforeFacebookLogin() {
		return false;
	}

	public function afterFacebookLogin() {
		return false;
	}
}

class ConnectTest extends CakeTestCase {

	public $Connect = null;

/**
 * Used to test complex protected methods
 * @link http://stackoverflow.com/questions/105007/do-you-test-private-method
 * @link http://stackoverflow.com/questions/249664/best-practices-to-test-protected-methods-with-phpunit
 */
	protected static function getMethod($class, $name) {
		$reflectionClass = new ReflectionClass($class);
		$method = $reflectionClass->getMethod($name);
		$method->setAccessible(true);
		return $method;
	}

	public function startTest() {
		Mock::generate('Controller');
		Mock::generate('AuthComponent');
		Mock::generate('SessionComponent');
		$this->Connect = new ConnectComponent();
		$this->Connect->Controller = $this->mockController();
		$this->User = new TestUser();

		Mock::generate('FB');
		$this->Connect->FB = new MockFB();

		// Reverse engineer class to change protected method visibility
		$this->syncFacebookUser = self::getMethod('ConnectComponent', '_syncFacebookUser');
	}

	public function mockController($callback = false) {
		if ($callback) {
			Mock::generate('TestCallbackController');
			$Controller = new MockTestCallbackController();
		} else {
			$Controller = new MockController();
		}
		$Controller->Auth = new MockAuthComponent();
		$Controller->Session = new MockSessionComponent();

		return $Controller;
	}

	public function testInitialize() {
		Configure::write('Facebook.appId', '1234567890');
		Configure::write('Facebook.secret', '12345678901234567890123456789012');
		$this->Connect->initialize($this->Connect->Controller);
		$this->assertFalse($this->Connect->me);
		$this->assertFalse($this->Connect->uid);
		Configure::delete('Facebook');
	}

	public function testBeforeLoginCallback() {
		$this->Connect->Controller = $this->mockController(true);
		$this->Connect->Controller->Auth->userModel = 'TestUser';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->setReturnValue('user', false);
		$this->Connect->Controller->Auth->setReturnValue('password', 'password');
		$this->Connect->Controller->setReturnValue('beforeFacebookSave', true);
		$this->Connect->Controller->expectOnce('beforeFacebookLogin', array(array(
			'TestUser' => array(
				'facebook_id' => 12,
				'password' => 'password'
			)
		)));
		$this->Connect->Controller->Auth->expectOnce('login', array(array(
			'TestUser' => array(
				'facebook_id' => 12,
				'password' => 'password'
			)
		)));
		$this->Connect->Controller->Auth->setReturnValue('login', true);
		$this->assertTrue($this->syncFacebookUser->invoke($this->Connect));
		$this->assertTrue($this->Connect->hasAccount);
	}

	public function testSaveHaultedByBeforeFacebookSave() {
		$this->Connect->Controller = $this->mockController(true);
		$this->Connect->Controller->Auth->userModel = 'TestUser';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->setReturnValue('user', false);
		$this->Connect->Controller->Auth->setReturnValue('password', 'password');
		$this->Connect->Controller->setReturnValue('beforeFacebookSave', false);
		$this->Connect->Controller->Auth->expectNever('login', false);
		$this->assertFalse($this->syncFacebookUser->invoke($this->Connect));
		$this->assertFalse($this->Connect->hasAccount);
	}

	public function testFacebookSyncShouldDoNothingIfAuthIsNotDetected() {
		unset($this->Connect->Controller->Auth);
		$this->assertFalse($this->syncFacebookUser->invoke($this->Connect));
	}

	public function testFacebookSyncShouldLoginAlreadyLinkedUser() {
		$this->Connect->Controller->Auth->userModel = 'TestUserHasOne';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->setReturnValue('user', false);
		$this->Connect->Controller->Auth->expectOnce('login', array(array(
			'TestUserHasOne' => array(
				'id' => 1,
				'username' => 'test',
				'password' => 'password',
				'facebook_id' => ''
			)
		)));
		$this->Connect->Controller->Auth->setReturnValue('login', true);
		$this->assertTrue($this->syncFacebookUser->invoke($this->Connect));
		$this->assertTrue($this->Connect->hasAccount);
	}

	public function testFacebookSyncShouldUpdateTheFacebookIdIfNotFound() {
		$this->Connect->Controller->Auth->userModel = 'TestUserHasOne';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->setReturnValue('user', 1);
		$this->Connect->Controller->Auth->expectNever('login');
		$this->assertTrue($this->syncFacebookUser->invoke($this->Connect));
		$this->assertEqual(1, $this->Connect->User->id);
		$this->assertEqual(12, $this->Connect->User->facebookId);
	}

	public function testFacebookSyncShouldReturnFalseIfWeDontHaveFacebookIDInTable() {
		$this->Connect->Controller->Auth->userModel = 'TestUserError';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->expectNever('user');
		$this->assertFalse($this->syncFacebookUser->invoke($this->Connect));
		$this->assertEqual('Facebook.Connect handleFacebookUser Error. facebook_id not found in TestUserError table.', $this->Connect->errors[0]);
	}

	public function testFacebookSyncShouldNotCreateUser() {
		$this->Connect->Controller->Auth->userModel = 'TestUser';
		$this->Connect->session['uid'] = 12;
		$this->Connect->createUser = false;
		$this->Connect->Controller->Auth->setReturnValue('user', false);
		$this->Connect->Controller->Auth->expectNever('login');
		$this->assertFalse($this->syncFacebookUser->invoke($this->Connect));
		$this->assertFalse($this->Connect->hasAccount);
	}

	public function testFacebookSyncShouldCreateUser() {
		$this->Connect->Controller = $this->mockController(true);
		$this->Connect->Controller->Auth->userModel = 'TestUser';
		$this->Connect->session['uid'] = 12;
		$this->Connect->Controller->Auth->setReturnValue('user', false);
		$this->Connect->Controller->Auth->setReturnValue('password', 'password');
		$this->Connect->Controller->setReturnValue('beforeFacebookSave', true);
		$this->Connect->Controller->expectOnce('beforeFacebookLogin', array(array(
			'TestUser' => array(
				'facebook_id' => 12,
				'password' => 'password'
			)
		)));
		$this->Connect->Controller->Auth->expectOnce('login', array(array(
			'TestUser' => array(
				'facebook_id' => 12,
				'password' => 'password'
			)
		)));
		$this->Connect->Controller->Auth->setReturnValue('login', true);
		$this->assertTrue($this->syncFacebookUser->invoke($this->Connect));
		$this->assertTrue($this->Connect->hasAccount);
		$this->assertEqual(array('TestUser' => array('facebook_id' => 12, 'password' => 'password')), $this->Connect->User->data);
	}

	public function testUserIfLoggedIn() {
		$this->Connect->me = array('email' => 'test@example.com', 'id' => '12');

		$results = $this->Connect->user();
		$this->assertEqual(array('email' => 'test@example.com', 'id' => '12'), $results);

		$results = $this->Connect->user('email');
		$this->assertEqual('test@example.com', $results);

		$results = $this->Connect->user('id');
		$this->assertEqual('12', $results);
	}

	public function testUserIfLoggedOut() {
		$this->Connect->me = null;

		$results = $this->Connect->user();
		$this->assertEqual(null, $results);

		$results = $this->Connect->user('email');
		$this->assertEqual(null, $results);

		$results = $this->Connect->user('id');
		$this->assertEqual(null, $results);
	}

	public function endTest() {
		unset($this->Connect);
	}
}
