<?php
namespace wcf\data\trophy;
use wcf\data\user\trophy\UserTrophyAction;
use wcf\data\user\trophy\UserTrophyList;
use wcf\data\user\UserAction;
use wcf\data\AbstractDatabaseObjectAction;
use wcf\data\IToggleAction;
use wcf\data\IUploadAction;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\IllegalLinkException;
use wcf\system\exception\UserInputException;
use wcf\system\image\ImageHandler;
use wcf\system\upload\TrophyImageUploadFileValidationStrategy;
use wcf\system\upload\UploadFile;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\WCF;

/**
 * Trophy related actions. 
 *
 * @author	Joshua Ruesweg
 * @copyright	2001-2017 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\Data\Trophy
 * @since	3.1
 *
 * @method	TrophyEditor[]		getObjects()
 * @method	TrophyEditor		getSingleObject()
 */
class TrophyAction extends AbstractDatabaseObjectAction implements IToggleAction, IUploadAction {
	/**
	 * @inheritDoc
	 */
	protected $permissionsDelete = ['admin.trophy.canManageTrophy'];
	
	/**
	 * @inheritDoc
	 */
	protected $requireACP = ['toggle', 'delete'];
	
	/**
	 * @inheritDoc
	 */
	public function create() {
		$trophy = parent::create();
		
		if (isset($this->parameters['tmpHash']) && $this->parameters['data']['type'] === Trophy::TYPE_IMAGE) {
			$this->updateTrophyImage($trophy);
		}
		
		return $trophy;
	}
	
	/**
	 * @inheritDoc
	 */
	public function delete() {
		// update trophy points 
		$userTrophyList = new UserTrophyList();
		if (!empty($userTrophyList->sqlJoins)) $userTrophyList->sqlJoins .= ' ';
		$userTrophyList->sqlJoins .= 'LEFT JOIN wcf'.WCF_N.'_trophy trophy ON user_trophy.trophyID = trophy.trophyID';
		$userTrophyList->sqlJoins .= ' LEFT JOIN wcf'.WCF_N.'_category category ON trophy.categoryID = category.categoryID';
		
		$userTrophyList->getConditionBuilder()->add('trophy.isDisabled = ?', [0]);
		$userTrophyList->getConditionBuilder()->add('category.isDisabled = ?', [0]);
		$userTrophyList->getConditionBuilder()->add('user_trophy.trophyID IN (?)', [$this->getObjectIDs()]);
		$userTrophyList->readObjects();
		
		$userTrophyAction = new UserTrophyAction($userTrophyList->getObjects(), 'delete');
		$userTrophyAction->executeAction();
		
		$returnValues = parent::delete();
		
		UserStorageHandler::getInstance()->resetAll('specialTrophies');
		
		return $returnValues;
	}
	
	/**
	 * @inheritDoc
	 */
	public function update() {
		parent::update();
		
		if (isset($this->parameters['data']['type']) && $this->parameters['data']['type'] === Trophy::TYPE_IMAGE) {
			foreach ($this->getObjects() as $trophy) {
				if (isset($this->parameters['tmpHash'])) {
					$this->updateTrophyImage($trophy);
				}
			}
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function toggle() {
		$enabledTrophyIDs = [];
		$disabledTrophyIDs = [];
		
		foreach ($this->getObjects() as $trophy) {
			$trophy->update(['isDisabled' => $trophy->isDisabled ? 0 : 1]);
			
			if (!$trophy->isDisabled) {
				
				$disabledTrophyIDs[] = $trophy->trophyID;
			}
			else {
				$enabledTrophyIDs[] = $trophy->trophyID;
			}
		}
		
		if (!empty($disabledTrophyIDs)) {
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('trophyID IN (?)', [$disabledTrophyIDs]);
			$sql = "DELETE FROM wcf". WCF_N ."_user_special_trophy ".$conditionBuilder;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			
			// update trophy points
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('trophyID IN (?)', [$disabledTrophyIDs]);
			$sql = "SELECT		COUNT(*) as count, userID
				FROM		wcf".WCF_N."_user_trophy
				".$conditionBuilder."
				GROUP BY	userID";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			
			while ($row = $statement->fetchArray()) {
				$userAction = new UserAction([$row['userID']], 'update', [
					'counters' => [
						'trophyPoints' => $row['count'] * -1
					]
				]);
				$userAction->executeAction();
			}
		}
		
		if (!empty($enabledTrophyIDs)) {
			// update trophy points
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('trophyID IN (?)', [$enabledTrophyIDs]);
			$sql = "SELECT		COUNT(*) as count, userID
				FROM		wcf".WCF_N."_user_trophy
				".$conditionBuilder."
				GROUP BY	userID";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			
			while ($row = $statement->fetchArray()) {
				$userAction = new UserAction([$row['userID']], 'update', [
					'counters' => [
						'trophyPoints' => $row['count']
					]
				]);
				$userAction->executeAction();
			}
		}
		
		UserStorageHandler::getInstance()->resetAll('specialTrophies');
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateToggle() {
		WCF::getSession()->checkPermissions(['admin.trophy.canManageTrophy']);
		
		// read objects
		if (empty($this->objects)) {
			$this->readObjects();
			
			if (empty($this->objects)) {
				throw new UserInputException('objectIDs');
			}
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateUpload() {
		WCF::getSession()->checkPermissions(['admin.trophy.canManageTrophy']);
		
		$this->readString('tmpHash');
		$this->readInteger('trophyID', true);
		
		if ($this->parameters['trophyID']) {
			$this->parameters['trophy'] = new Trophy($this->parameters['trophyID']);
			
			if (!$this->parameters['trophy']->trophyID) {
				throw new IllegalLinkException(); 
			}
		}
		
		$this->parameters['__files']->validateFiles(new TrophyImageUploadFileValidationStrategy());
		
		/** @var UploadFile[] $files */
		$files = $this->parameters['__files']->getFiles();
		
		// only one file is allowed
		if (count($files) !== 1) {
			throw new UserInputException('file');
		}
		
		$this->parameters['file'] = reset($files);
		
		if ($this->parameters['file']->getValidationErrorType()) {
			throw new UserInputException('file', $this->parameters['file']->getValidationErrorType());
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function upload() {
		$fileName = WCF_DIR.'images/trophy/tmp_'.$this->parameters['tmpHash'].'.'.$this->parameters['file']->getFileExtension(); 
		if ($this->parameters['file']->getImageData()['height'] > 128) {
			$adapter = ImageHandler::getInstance()->getAdapter();
			$adapter->loadFile($this->parameters['file']->getLocation());
			$adapter->resize(0, 0, $this->parameters['file']->getImageData()['height'], $this->parameters['file']->getImageData()['height'], 128, 128);
			$adapter->writeImage($adapter->getImage(), $fileName);
		} 
		else {
			copy($this->parameters['file']->getLocation(), $fileName);
		}
		
		// remove old image
		@unlink($this->parameters['file']->getLocation());
		
		// store extension within session variables
		WCF::getSession()->register('trophyImage-'.$this->parameters['tmpHash'], $this->parameters['file']->getFileExtension());
		
		if ($this->parameters['trophyID']) {
			$this->updateTrophyImage($this->parameters['trophy']);
			
			return [
				'url' => WCF::getPath().'images/trophy/trophyImage-'.$this->parameters['trophyID'].'.'.$this->parameters['file']->getFileExtension()
			];
		}
		
		return [
			'url' => WCF::getPath() . 'images/trophy/'. basename($fileName)
		];
	}
	
	/**
	 * Updates style preview image.
	 *
	 * @param	Trophy		$trophy
	 */
	protected function updateTrophyImage(Trophy $trophy) {
		if (!isset($this->parameters['tmpHash'])) {
			return;
		}
		
		$fileExtension = WCF::getSession()->getVar('trophyImage-'.$this->parameters['tmpHash']);
		if ($fileExtension !== null) {
			$oldFilename = WCF_DIR.'images/trophy/tmp_'.$this->parameters['tmpHash'].'.'.$fileExtension;
			if (file_exists($oldFilename)) {
				$filename = 'trophyImage-'.$trophy->trophyID.'.'.$fileExtension;
				if (@rename($oldFilename, WCF_DIR.'images/trophy/'.$filename)) {
					// delete old file if it has a different file extension
					if ($trophy->iconFile != $filename) {
						@unlink(WCF_DIR.'images/trophy/'.$trophy->iconFile);
						
						$trophyEditor = new TrophyEditor($trophy);
						$trophyEditor->update([
							'iconFile' => $filename
						]);
					}
				}
				else {
					// remove temp file
					@unlink($oldFilename);
				}
			}
		}
	}
}
