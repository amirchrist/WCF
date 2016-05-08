<?php
namespace wcf\data\user;
use wcf\data\language\Language;
use wcf\data\user\group\UserGroup;
use wcf\data\DatabaseObject;
use wcf\data\IUserContent;
use wcf\system\cache\builder\UserOptionCacheBuilder;
use wcf\system\language\LanguageFactory;
use wcf\system\request\IRouteController;
use wcf\system\request\LinkHandler;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\WCF;
use wcf\util\PasswordUtil;

/**
 * Represents a user.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2016 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	data.user
 * @category	Community Framework
 *
 * @property-read	integer		$userID
 * @property-read	string		$username
 * @property-read	string		$email
 * @property-read	string		$password
 * @property-read	string		$accessToken
 * @property-read	integer		$languageID
 * @property-read	string		$registrationDate
 * @property-read	integer		$styleID
 * @property-read	integer		$banned
 * @property-read	string		$banReason
 * @property-read	integer		$banExpires
 * @property-read	integer		$activationCode
 * @property-read	integer		$lastLostPasswordRequestTime
 * @property-read	string		$lostPasswordKey
 * @property-read	integer		$lastUsernameChange
 * @property-read	string		$newEmail
 * @property-read	string		$oldUsername
 * @property-read	integer		$quitStarted
 * @property-read	integer		$reactivationCode
 * @property-read	string		$registrationIpAddress
 * @property-read	integer|null	$avatarID
 * @property-read	integer		$disableAvatar
 * @property-read	string		$disableAvatarReason
 * @property-read	integer		$disableAvatarExpires
 * @property-read	integer		$enableGravatar
 * @property-read	string		$gravatarFileExtension
 * @property-read	string		$signature
 * @property-read	integer		$signatureEnableBBCodes
 * @property-read	integer		$signatureEnableHtml
 * @property-read	integer		$signatureEnableSmilies
 * @property-read	integer		$disableSignature
 * @property-read	string		$disableSignatureReason
 * @property-read	integer		$disableSignatureExpires
 * @property-read	integer		$lastActivityTime
 * @property-read	integer		$profileHits
 * @property-read	integer|null	$rankID
 * @property-read	string		$userTitle
 * @property-read	integer|null	$userOnlineGroupID
 * @property-read	integer		$activityPoints
 * @property-read	string		$notificationMailToken
 * @property-read	string		$authData
 * @property-read	integer		$likesReceived
 * @property-read	string		$socialNetworkPrivacySettings
 */
final class User extends DatabaseObject implements IRouteController, IUserContent {
	/**
	 * @inheritDoc
	 */
	protected static $databaseTableName = 'user';
	
	/**
	 * @inheritDoc
	 */
	protected static $databaseTableIndexName = 'userID';
	
	/**
	 * list of group ids
	 * @var integer[]
	 */
	protected $groupIDs = null;
	
	/**
	 * true, if user has access to the ACP
	 * @var	boolean
	 */
	protected $hasAdministrativePermissions = null;
	
	/**
	 * list of language ids
	 * @var	integer[]
	 */
	protected $languageIDs = null;
	
	/**
	 * date time zone object
	 * @var	\DateTimeZone
	 */
	protected $timezoneObj = null;
	
	/**
	 * list of user options
	 * @var	string[]
	 */
	protected static $userOptions = null;
	
	/**
	 * @inheritDoc
	 */
	public function __construct($id, $row = null, DatabaseObject $object = null) {
		if ($id !== null) {
			$sql = "SELECT		user_option_value.*, user_table.*
				FROM		wcf".WCF_N."_user user_table
				LEFT JOIN	wcf".WCF_N."_user_option_value user_option_value
				ON		(user_option_value.userID = user_table.userID)
				WHERE		user_table.userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([$id]);
			$row = $statement->fetchArray();
			
			// enforce data type 'array'
			if ($row === false) $row = [];
		}
		else if ($object !== null) {
			$row = $object->data;
		}
		
		$this->handleData($row);
	}
	
	/**
	 * Returns true if the given password is the correct password for this user.
	 * 
	 * @param	string		$password
	 * @return	boolean		password correct
	 */
	public function checkPassword($password) {
		$isValid = false;
		$rebuild = false;
		
		// check if password is a valid bcrypt hash
		if (PasswordUtil::isBlowfish($this->password)) {
			if (PasswordUtil::isDifferentBlowfish($this->password)) {
				$rebuild = true;
			}
			
			// password is correct
			if (PasswordUtil::secureCompare($this->password, PasswordUtil::getDoubleSaltedHash($password, $this->password))) {
				$isValid = true;
			}
		}
		else {
			// different encryption type
			if (PasswordUtil::checkPassword($this->username, $password, $this->password)) {
				$isValid = true;
				$rebuild = true;
			}
		}
		
		// create new password hash, either different encryption or different blowfish cost factor
		if ($rebuild && $isValid) {
			$userEditor = new UserEditor($this);
			$userEditor->update([
				'password' => $password
			]);
		}
		
		return $isValid;
	}
	
	/**
	 * Returns true if the given password hash from a cookie is the correct password for this user.
	 * 
	 * @param	string		$passwordHash
	 * @return	boolean		password correct
	 */
	public function checkCookiePassword($passwordHash) {
		if (PasswordUtil::isBlowfish($this->password) && PasswordUtil::secureCompare($this->password, PasswordUtil::getSaltedHash($passwordHash, $this->password))) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Returns an array with all the groups in which the actual user is a member.
	 * 
	 * @param	boolean		$skipCache
	 * @return	integer[]
	 */
	public function getGroupIDs($skipCache = false) {
		if ($this->groupIDs === null || $skipCache) {
			if (!$this->userID) {
				// user is a guest, use default guest group
				$this->groupIDs = UserGroup::getGroupIDsByType([UserGroup::GUESTS, UserGroup::EVERYONE]);
			}
			else {
				// get group ids
				$data = UserStorageHandler::getInstance()->getField('groupIDs', $this->userID);
				
				// cache does not exist or is outdated
				if ($data === null || $skipCache) {
					$sql = "SELECT	groupID
						FROM	wcf".WCF_N."_user_to_group
						WHERE	userID = ?";
					$statement = WCF::getDB()->prepareStatement($sql);
					$statement->execute([$this->userID]);
					$this->groupIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);
					
					// update storage data
					if (!$skipCache) {
						UserStorageHandler::getInstance()->update($this->userID, 'groupIDs', serialize($this->groupIDs));
					}
				}
				else {
					$this->groupIDs = unserialize($data);
				}
			}
			
			sort($this->groupIDs, SORT_NUMERIC);
		}
		
		return $this->groupIDs;
	}
	
	/**
	 * Returns a list of language ids for this user.
	 * 
	 * @return	integer[]
	 */
	public function getLanguageIDs() {
		if ($this->languageIDs === null) {
			$this->languageIDs = [];
			
			if ($this->userID) {
				// get language ids
				$data = UserStorageHandler::getInstance()->getField('languageIDs', $this->userID);
				
				// cache does not exist or is outdated
				if ($data === null) {
					$sql = "SELECT	languageID
						FROM	wcf".WCF_N."_user_to_language
						WHERE	userID = ?";
					$statement = WCF::getDB()->prepareStatement($sql);
					$statement->execute([$this->userID]);
					$this->languageIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);
					
					// update storage data
					UserStorageHandler::getInstance()->update($this->userID, 'languageIDs', serialize($this->languageIDs));
				}
				else {
					$this->languageIDs = unserialize($data);
				}
			}
			else if (!WCF::getSession()->spiderID) {
				$this->languageIDs[] = WCF::getLanguage()->languageID;
			}
		}
		
		return $this->languageIDs;
	}
	
	/**
	 * Returns the value of the user option with the given name.
	 * 
	 * @param	string		$name		user option name
	 * @return	mixed				user option value
	 */
	public function getUserOption($name) {
		$optionID = self::getUserOptionID($name);
		if ($optionID === null) {
			return null;
		}
		
		if (!isset($this->data['userOption'.$optionID])) return null;
		return $this->data['userOption'.$optionID];
	}
	
	/**
	 * Gets all user options from cache.
	 */
	protected static function getUserOptionCache() {
		self::$userOptions = UserOptionCacheBuilder::getInstance()->getData([], 'options');
	}
	
	/**
	 * Returns the id of a user option.
	 * 
	 * @param	string		$name
	 * @return	integer		id
	 */
	public static function getUserOptionID($name) {
		// get user option cache if necessary
		if (self::$userOptions === null) {
			self::getUserOptionCache();
		}
		
		if (!isset(self::$userOptions[$name])) {
			return null;
		}
		
		return self::$userOptions[$name]->optionID;
	}
	
	/**
	 * @inheritDoc
	 */
	public function __get($name) {
		$value = parent::__get($name);
		if ($value === null) $value = $this->getUserOption($name);
		return $value;
	}
	
	/**
	 * Returns the user with the given username.
	 * 
	 * @param	string		$username
	 * @return	User
	 */
	public static function getUserByUsername($username) {
		$sql = "SELECT		user_option_value.*, user_table.*
			FROM		wcf".WCF_N."_user user_table
			LEFT JOIN	wcf".WCF_N."_user_option_value user_option_value
			ON		(user_option_value.userID = user_table.userID)
			WHERE		user_table.username = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([$username]);
		$row = $statement->fetchArray();
		if (!$row) $row = [];
		
		return new User(null, $row);
	}
	
	/**
	 * Returns the user with the given email.
	 * 
	 * @param	string		$email
	 * @return	User
	 */
	public static function getUserByEmail($email) {
		$sql = "SELECT		user_option_value.*, user_table.*
			FROM		wcf".WCF_N."_user user_table
			LEFT JOIN	wcf".WCF_N."_user_option_value user_option_value
			ON		(user_option_value.userID = user_table.userID)
			WHERE		user_table.email = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([$email]);
		$row = $statement->fetchArray();
		if (!$row) $row = [];
		
		return new User(null, $row);
	}

	/**
	 * Returns the user with the given authData.
	 *
	 * @param	string		$authData
	 * @return	User
	 */
	public static function getUserByAuthData($authData) {
		$sql = "SELECT		user_option_value.*, user_table.*
			FROM		wcf".WCF_N."_user user_table
			LEFT JOIN	wcf".WCF_N."_user_option_value user_option_value
			ON		(user_option_value.userID = user_table.userID)
			WHERE		user_table.authData = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([$authData]);
		$row = $statement->fetchArray();
		if (!$row) $row = [];

		return new User(null, $row);
	}
	
	/**
	 * Returns true if this user is marked.
	 * 
	 * @return	boolean
	 */
	public function isMarked() {
		$markedUsers = WCF::getSession()->getVar('markedUsers');
		if ($markedUsers !== null) {
			if (in_array($this->userID, $markedUsers)) return 1;
		}
		
		return 0;
	}
	
	/**
	 * Returns the time zone of this user.
	 * 
	 * @return	\DateTimeZone
	 */
	public function getTimeZone() {
		if ($this->timezoneObj === null) {
			if ($this->timezone) {
				$this->timezoneObj = new \DateTimeZone($this->timezone);
			}
			else {
				$this->timezoneObj = new \DateTimeZone(TIMEZONE);
			}
		}
		
		return $this->timezoneObj;
	}
	
	/**
	 * Returns a list of users.
	 * 
	 * @param	array		$userIDs
	 * @return	User[]
	 */
	public static function getUsers(array $userIDs) {
		$userList = new UserList();
		$userList->setObjectIDs($userIDs);
		$userList->readObjects();
		
		return $userList->getObjects();
	}
	
	/**
	 * Returns username.
	 * 
	 * @return	string
	 */
	public function __toString() {
		return $this->username;
	}
	
	/**
	 * @inheritDoc
	 */
	public static function getDatabaseTableAlias() {
		return 'user_table';
	}
	
	/**
	 * @inheritDoc
	 */
	public function getTitle() {
		return $this->username;
	}
	
	/**
	 * Returns the language of this user.
	 * 
	 * @return	Language
	 */
	public function getLanguage() {
		$language = LanguageFactory::getInstance()->getLanguage($this->languageID);
		if ($language === null) {
			$language = LanguageFactory::getInstance()->getLanguage(LanguageFactory::getInstance()->getDefaultLanguageID());
		}
		
		return $language;
	}
	
	/**
	 * Returns true if the active user can edit this user.
	 * 
	 * @return	boolean
	 */
	public function canEdit() {
		return (WCF::getSession()->getPermission('admin.user.canEditUser') && UserGroup::isAccessibleGroup($this->getGroupIDs()));
	}
	
	/**
	 * Returns true, if this user has access to the ACP.
	 * 
	 * @return	boolean
	 */
	public function hasAdministrativeAccess() {
		if ($this->hasAdministrativePermissions === null) {
			$this->hasAdministrativePermissions = false;
			
			if ($this->userID) {
				foreach ($this->getGroupIDs() as $groupID) {
					$group = UserGroup::getGroupByID($groupID);
					if ($group->isAdminGroup()) {
						$this->hasAdministrativePermissions = true;
						break;
					}
				}
			}
		}
		
		return $this->hasAdministrativePermissions;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getUserID() {
		return $this->userID;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getUsername() {
		return $this->username;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getTime() {
		return $this->registrationDate;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getLink() {
		return LinkHandler::getInstance()->getLink('User', [
			'application' => 'wcf',
			'object' => $this,
			'forceFrontend' => true
		]);
	}
	
	/**
	 * Returns the social network privacy settings of the user.
	 * 
	 * @return	boolean[]
	 */
	public function getSocialNetworkPrivacySettings() {
		$settings = false;
		if ($this->userID && WCF::getUser()->socialNetworkPrivacySettings) {
			$settings = @unserialize(WCF::getUser()->socialNetworkPrivacySettings);
		}
		
		if ($settings === false) {
			$settings = [
				'facebook' => false,
				'google' => false,
				'reddit' => false,
				'twitter' => false
			];
		}
		
		return $settings;
	}
}
