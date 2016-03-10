<?php

require_once 'Admin/dataobjects/AdminUser.php';
require_once 'Admin/dataobjects/AdminUserHistory.php';
require_once 'Admin/dataobjects/AdminUserWrapper.php';
require_once 'Admin/exceptions/AdminException.php';
require_once 'Site/SiteSessionModule.php';
require_once 'Site/SiteCookieModule.php';
require_once 'SwatDB/SwatDB.php';
require_once 'Swat/SwatDate.php';
require_once 'Swat/SwatForm.php';
require_once 'Swat/SwatString.php';

/**
 * Web application module for sessions
 *
 * @package   Admin
 * @copyright 2005-2016 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class AdminSessionModule extends SiteSessionModule
{
	// {{{ class constants

	/**
	 * How many days an admin user account is considered active
	 *
	 * If an account has no sign-in activity for this many days, it will be
	 * prevented from signing into the admin.
	 *
	 * @see AdminSessionModule::isActiveUser()
	 */
	const ACCOUNT_EXPIRY_DAYS = 90;

	// }}}
	// {{{ protected properties

	/**
	 * @var array
	 * @see AdminSessionModule::registerLoginCallback()
	 */
	protected $login_callbacks = array();

	// }}}
	// {{{ public function __construct()

	/**
	 * Creates a admin session module
	 *
	 * @param SiteApplication $app the application this module belongs to.
	 *
	 * @throws AdminException if there is no cookie module loaded the session
	 *                         module throws an exception.
	 *
	 * @throws AdminException if there is no database module loaded the session
	 *                         module throws an exception.
	 */
	public function __construct(SiteApplication $app)
	{
		$this->registerLoginCallback(
			array($this, 'regenerateAuthenticationToken'));

		parent::__construct($app);
	}

	// }}}
	// {{{ public function init()

	public function init()
	{
		parent::init();

		// always activate the session for an admin
		if (!$this->isActive())
			$this->activate();

		if (!isset($this->user)) {
			$this->user = null;
			$this->history = array();
		} elseif ($this->user !== null) {
			$this->app->cookie->setCookie('email', $this->getEmailAddress(),
				strtotime('+1 day'), '/');
		}
	}

	// }}}
	// {{{ public function depends()

	/**
	 * Gets the module features this module depends on
	 *
	 * The admin session module depends on the SiteCookieModule and
	 * SiteDatabaseModule features.
	 *
	 * @return array an array of {@link SiteModuleDependency} objects defining
	 *                        the features this module depends on.
	 */
	public function depends()
	{
		$depends = parent::depends();
		$depends[] = new SiteApplicationModuleDependency('SiteCookieModule');
		$depends[] = new SiteApplicationModuleDependency('SiteCryptModule');
		$depends[] = new SiteApplicationModuleDependency('SiteDatabaseModule');
		return $depends;
	}

	// }}}
	// {{{ public function login()

	/**
	 * Logs an admin user into an admin
	 *
	 * @param string $email
	 * @param string $password
	 *
	 * @return boolean true if the admin user was logged in is successfully and
	 *                  false if the admin user could not log in.
	 */
	public function login($email, $password)
	{
		$this->logout(); // make sure user is logged out before logging in

		$class_name = SwatDBClassMap::get('AdminUser');
		$user = new $class_name();
		$user->setDatabase($this->app->db);

		if ($user->loadFromEmail($email) && $this->isActiveUser($user)) {
			$password_hash = $user->password;
			$password_salt = $user->password_salt;

			$crypt = $this->app->getModule('SiteCryptModule');

			if ($crypt->verifyHash($password, $password_hash, $password_salt)) {
				// No Crypt?! Crypt!
				if ($crypt->shouldUpdateHash($password_hash)) {
					$user->setPasswordHash($crypt->generateHash($password));
					$user->save();
				}

				$this->user = $user;

				if ($user->isAuthenticated($this->app)) {
					$this->insertUserHistory($user);
					$this->runLoginCallbacks();
				}
			}
		}

		return $this->isLoggedIn();
	}

	// }}}
	// {{{ public function logout()

	/**
	 * Logs the current admin user out of an admin
	 */
	public function logout()
	{
		$this->clear();
		$this->user = null;
	}

	// }}}
	// {{{ public function isLoggedIn()

	/**
	 * Gets whether or not an admin user is logged in
	 *
	 * @return boolean true if an admin user is logged in and false if an
	 *                  admin user is not logged in.
	 */
	public function isLoggedIn()
	{
		return (isset($this->user) && $this->user !== null &&
			$this->user->isAuthenticated($this->app));
	}

	// }}}
	// {{{ public function getUser()

	/**
	 * Gets the current admin user
	 *
	 * @return AdminUser the current admin user object, or null if an
	 *                   admin user is not logged in.
	 */
	public function getUser()
	{
		if (!$this->isLoggedIn())
			return null;

		return $this->user;
	}

	// }}}
	// {{{ public function getUserID()

	/**
	 * Gets the current admin user's user identifier
	 *
	 * @return string the current admin user's user identifier, or null if an
	 *                 admin user is not logged in.
	 */
	public function getUserID()
	{
		if (!$this->isLoggedIn())
			return null;

		return $this->user->id;
	}

	// }}}
	// {{{ public function getEmailAddress()

	/**
	 * Gets the current admin user's email address
	 *
	 * @return string the current admin user's email address, or null if an
	 *                 admin user is not logged in.
	 */
	public function getEmailAddress()
	{
		if (!$this->isLoggedIn())
			return null;

		return $this->user->email;
	}

	// }}}
	// {{{ public function getName()

	/**
	 * Gets the current admin user's name
	 *
	 * @return string the current admin user's name, or null if an admin user
	 *                 is not logged in.
	 */
	public function getName()
	{
		if (!$this->isLoggedIn())
			return null;

		return $this->user->name;
	}

	// }}}
	// {{{ public function registerLoginCallback()

	/**
	 * Registers a callback function that is executed when a successful session
	 * login is performed
	 *
	 * @param callback $callback the callback to call when a successful login
	 *                            is performed.
	 * @param array $parameters optional. The paramaters to pass to the
	 *                           callback.
	 *
	 * @throws AdminException when the <i>$callback</i> parameter is not
	 *                        callable.
	 * @throws AdminException when the <i>$parameters</i> parameter is not an
	 *                        array.
	 */
	public function registerLoginCallback($callback, $parameters = array())
	{
		if (!is_callable($callback))
			throw new AdminException('Cannot register invalid callback.');

		if (!is_array($parameters))
			throw new AdminException('Callback parameters must be specified '.
				'in an array.');

		$this->login_callbacks[] = array(
			'callback' => $callback,
			'parameters' => $parameters
		);
	}

	// }}}
	// {{{ protected function startSession()

	protected function startSession()
	{
		parent::startSession();

		if (isset($this->user) && $this->user instanceof AdminUser) {
			$this->user->setDatabase($this->app->database->getConnection());
		}
	}

	// }}}
	// {{{ protected function insertUserHistory()

	/**
	 * Inserts login history for a user
	 *
	 * @param AdminUser $user_id the user to record login history for.
	 */
	protected function insertUserHistory(AdminUser $user)
	{
		$login_agent = (isset($_SERVER['HTTP_USER_AGENT'])) ?
			$_SERVER['HTTP_USER_AGENT'] : null;

		$remote_ip = $this->app->getRemoteIP();

		if (strlen($login_agent) > 255) {
			$login_agent = substr($login_agent, 0, 253).' …';
		}
		if (strlen($remote_ip) > 15) {
			$remote_ip = substr($remote_ip, 0, 13).' …';
		}

		$login_date = new SwatDate();
		$login_date->toUTC();

		$fields = array('integer:usernum','date:login_date',
			'text:login_agent', 'text:remote_ip', 'integer:instance');

		$values = array(
			'usernum'     => $user->id,
			'login_date'  => $login_date->getDate(),
			'login_agent' => $login_agent,
			'remote_ip'   => $remote_ip,
			'instance'    => $this->app->getInstanceId(),
		);

		SwatDB::insertRow($this->app->db, 'AdminUserHistory', $fields,
			$values);
	}

	// }}}
	// {{{ protected function runLoginCallbacks()

	protected function runLoginCallbacks()
	{
		foreach ($this->login_callbacks as $login_callback) {
			$callback = $login_callback['callback'];
			$parameters = $login_callback['parameters'];
			call_user_func_array($callback, $parameters);
		}
	}

	// }}}
	// {{{ protected function isActiveUser()

	/**
	 * Checks to see if the AdminUser is an active account.
	 *
	 * Users are inactive if they haven't logged in the last 90 days, or 90
	 * days from the creation of the account if the user has never logged in.
	 *
	 * @param AdminUser $user_id the user to record login history for.
	 *
	 * @return boolean
	 *
	 * @see AdminSessionModule::ACCOUNT_EXPIRY_DAYS
	 */
	protected function isActiveUser(AdminUser $user)
	{
		$active_user = false;

		$comparison_date = null;

		if ($user->most_recent_history instanceof AdminUserHistory) {
			$comparison_date = $user->most_recent_history->login_date;
		} elseif ($user->createdate instanceof SwatDate) {
			$comparison_date = $user->createdate;
		}

		$threshold = new SwatDate();
		$threshold->subtractDays(self::ACCOUNT_EXPIRY_DAYS);
		if ($comparison_date instanceof SwatDate &&
			$comparison_date->after($threshold)) {
			$active_user = true;
		}

		return $active_user;
	}

	// }}}
}

?>
