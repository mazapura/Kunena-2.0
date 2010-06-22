<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.com
 **/

// Dont allow direct linking
defined( '_JEXEC' ) or die();

// Defines for moves
define ( 'KN_MOVE_MESSAGE', 0 );
define ( 'KN_MOVE_THREAD', 1 );
define ( 'KN_MOVE_NEWER', 2 );
define ( 'KN_MOVE_REPLIES', 3 );

//Defines for deletes
define ( 'KN_DEL_MESSAGE', 0 );
define ( 'KN_DEL_MESSAGE_PERMINANTLY', 1 );
define ( 'KN_DEL_THREAD', 2 );
define ( 'KN_DEL_ATTACH', 3 );

class CKunenaModeration {
	// Private data and functions
	protected $_db = null;
	protected $_my = null;
	protected $_session = null;
	protected $_errormsg = null;
	protected $_config = null;

	protected function __construct($db, $config) {
		$this->_db = $db;
		$this->_my = &JFactory::getUser ();
		$this->_session = KunenaFactory::getSession ();
		$this->_allowed = ($this->_session->allowed != '') ? explode ( ',', $this->_session->allowed ) : array();
		$this->_ResetErrorMessage ();
		$this->_config = $config;
	}

	public function &getInstance() {
		static $instance = NULL;
		if (! $instance) {
			$kunena_db = & JFactory::getDBO ();
			$kunena_config = KunenaFactory::getConfig ();

			$instance = new CKunenaModeration ( $kunena_db, $kunena_config );
		}
		return $instance;
	}

	protected function _ResetErrorMessage() {
		$this->_errormsg = '';
	}

	protected function _Move($MessageID, $TargetCatID, $TargetSubject = '', $TargetMessageID = 0, $mode = KN_MOVE_MESSAGE, $GhostThread = false) {
		// Private move function
		// $mode
		// KN_MOVE_MESSAGE ... move current message only
		// KN_MOVE_THREAD  ... move entire thread
		// KN_MOVE_NEWER   ... move current message and all newer in current thread
		// KN_MOVE_REPLIES ... move current message and replies and quotes - 1 level deep
		//
		// if $TargetMessagID is a valid message ID, the messages will be appended to that thread


		// Reset error message
		$this->_ResetErrorMessage ();

		// Sanitize parameters!
		$MessageID = intval ( $MessageID );
		$TargetCatID = intval ( $TargetCatID );
		$TargetMessageID = intval ( $TargetMessageID );
		$mode = intval ( $mode );
		// no need to check $GhostThread as we only test for true

		// Always check security clearance before taking action!

		// Assumption: only moderators can move messages
		// This test is made to prevent user to guess existing message ids
		if ( !CKunenaTools::isModerator($this->_my->id) ) {
			$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_NOT_MODERATOR');
			return false;
		}

		$query = 'SELECT `id`, `catid`, `parent`, `thread`, `subject` FROM #__kunena_messages WHERE `id`='.$MessageID;
		$this->_db->setQuery ( $query );
		$currentMessage = $this->_db->loadObject ();
		if (KunenaError::checkDatabaseError()) return false;

		// Check that message to be moved actually exists
		if ( !is_object($currentMessage) ) {
			$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_MESSAGE_NOT_FOUND', $MessageID);
			return false;
		}

		if ($mode == KN_MOVE_THREAD && $currentMessage->parent != 0) {
			// When moving a thread, message has to point into first message
			$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_NOT_TOPIC', $currentMessage->id);
			return false;
		}

		// Check that thread can't be move into a section
		$query = 'SELECT `parent` FROM #__kunena_categories WHERE `id`='.$TargetCatID;
		$this->_db->setQuery ( $query );
		$catParent = $this->_db->loadResult ();
		if (KunenaError::checkDatabaseError()) return false;
		if ( $catParent == '0' ) {
			$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_NOT_MOVE_SECTION');
			return false;
		}

		// Check that user has moderator permissions in source category
		if ( !CKunenaTools::isModerator($this->_my->id, $currentMessage->catid) ) {
			$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_NOT_MODERATOR_IN_CATEGORY', $currentMessage->id, $currentMessage->catid);
			return false;
		}

		// Check that we have target category or message
		if ($TargetCatID == 0 && $TargetMessageID == 0) {
			$this->_errormsg = JText::printf('COM_KUNENA_MODERATION_ERROR_NO_TARGET', $currentMessage->id);
			return false;
		}

		if ($TargetMessageID != 0) {
			// Check that target message actually exists
			$this->_db->setQuery ( "SELECT `id`, `catid`, `parent`, `thread`, `subject` FROM #__kunena_messages WHERE `id`='$TargetMessageID'" );
			$targetMessage = $this->_db->loadObject ();
			if (KunenaError::checkDatabaseError()) return false;

			if ( !is_object( $targetMessage )) {
				// Target message not found. Cannot proceed with move
				$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_TARGET_MESSAGE_NOT_FOUND', $currentMessage->id, $TargetMessageID);
				return false;
			}

			if ($targetMessage->thread == $currentMessage->thread) {
				// Recursive self moves not supported
				$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_SAME_TARGET_THREAD', $currentMessage->id, $currentMessage->thread);
				return false;
			}

			// If $TargetMessageID has been specified and is valid,
			// overwrite $TargetCatID with the category ID of the target message
			$TargetCatID = $targetMessage->catid;
		}

		// Check that target category exists and is visible to our moderator
		if (! in_array ( $TargetCatID, $this->_allowed ) ) {
			//the user haven't moderator permissions in target category
			$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_TARGET_CATEGORY_NOT_FOUND', $currentMessage->id, $TargetCatID);
			return false;
		}

		// Special case if the first message is moved in case 2 or 3
		if ($mode != KN_MOVE_MESSAGE && $currentMessage->parent == 0)
			$mode = KN_MOVE_THREAD;

		// Moving first message is a special case: handle it separately to other messages
		if ($TargetMessageID == 0) {
			$TargetThreadID = $MessageID;
			$TargetParentID = 0;
		} else {
			$TargetThreadID = $targetMessage->thread;
			$TargetParentID = $currentMessage->parent ? $currentMessage->parent : $TargetMessageID;
		}
		// partial logic to update target subject if specified
		$subjectupdatesql = !empty($TargetSubject) ? ",`subject`={$this->_db->quote($TargetSubject)}" : "";

		$sql = "UPDATE #__kunena_messages SET `catid`='{$TargetCatID}', `thread`='{$TargetThreadID}', `parent`='{$TargetParentID}' {$subjectupdatesql} WHERE `id`='{$MessageID}'";
		$this->_db->setQuery ( $sql );
		$this->_db->query ();
		if (KunenaError::checkDatabaseError()) return false;

		// Assemble move logic based on $mode
		switch ($mode) {
			case KN_MOVE_MESSAGE : // Move Single message only
				// If we are moving the first message of a thread only - make the second post the new thread header
				if ( $currentMessage->parent == 0 ) {
					// We are about to pull the thread starter from the original thread.
					// Need to promote the second post of the original thread as the new starter.
					$sqlnewparent = "SELECT `id` FROM #__kunena_messages WHERE `id`!={$MessageID} AND `thread`='{$currentMessage->thread}' ORDER BY `id` ASC";
					$this->_db->setQuery ( $sqlnewparent, 0, 1 );
					$newParentID = $this->_db->loadResult ();
					if (KunenaError::checkDatabaseError()) return false;

					if ( $newParentID ) {
						$this->_Move ( $newParentID, $currentMessage->catid, '', 0, KN_MOVE_NEWER );
					}
				}

				break;
			case KN_MOVE_THREAD :
				// Move entire Thread
				$sql = "UPDATE #__kunena_messages SET `catid`='{$TargetCatID}', `thread`='{$TargetThreadID}' WHERE `thread`='{$currentMessage->thread}'";

				// Create ghost thread if requested
				if ($GhostThread == true) {
					$this->createGhostThread($MessageID,$currentMessage);
				}

				break;
			case KN_MOVE_NEWER :
				// Move message and all newer messages of thread
				$sql = "UPDATE #__kunena_messages SET `catid`='{$TargetCatID}', `thread`='{$TargetThreadID}' WHERE `thread`='{$currentMessage->thread}' AND `id`>'{$MessageID}'";

				break;
			case KN_MOVE_REPLIES :
				// Move message and all replies and quotes - 1 level deep for now
				$sql = "UPDATE #__kunena_messages SET `catid`='{$TargetCatID}', `thread`='{$TargetThreadID}' WHERE `thread`='{$currentMessage->thread}' AND `parent`='{$MessageID}'";

				break;
			default :
				// Unsupported mode - Error!
				$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_UNSUPPORTED_MODE');

				return false;
		}

		// Execute move
		if (isset($sql)) {
			$this->_db->setQuery ( $sql );
			$this->_db->query ();
			if (KunenaError::checkDatabaseError()) return false;
		}

		// When done log the action
		$this->_Log ( 'Move', $MessageID, $TargetCatID, $TargetSubject, $TargetMessageID, $mode );

		// Last but not least update forum stats
		CKunenaTools::reCountBoards ();

		return true;
	}

	protected function _Delete($MessageID, $DeleteAttachments = false, $mode = KN_DEL_MESSAGE) {
		// Private delete function
		// $mode
		// KN_DEL_MESSAGE ... delete current message only
		// KN_DEL_THREAD  ... delete entire thread
		// KN_DEL_ATTACH  ... delete Attachments of message

		// Reset error message
		$this->_ResetErrorMessage ();

		// Sanitize parameters!
		$MessageID = intval ( $MessageID );
		$mode = intval ( $mode );
		// no need to check $DeleteAttachments as we only test for true

		// Always check security clearance before taking action!
		// Only moderators can delete messages by using this function
		if ( !CKunenaTools::isModerator($this->_my->id) ) {
			$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_NOT_MODERATOR');
			return false;
		}

		$this->_db->setQuery ( "SELECT `id`, `userid`, `catid`, `parent`, `thread`, `subject`, `time` AS timestamp FROM #__kunena_messages WHERE `id`='$MessageID'" );
		$currentMessage = $this->_db->loadObject ();
		if (KunenaError::checkDatabaseError()) return false;

		// Check that message to be moved actually exists
		if ( !is_object($currentMessage) ) {
			$this->_errormsg = JText::sprintf('COM_KUNENA_MODERATION_ERROR_MESSAGE_NOT_FOUND', $MessageID);
			return false;
		}

		// Check that user has moderator permissions in the category
		if ( !CKunenaTools::isModerator($this->_my->id, $currentMessage->catid) ) {
			$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_NOT_MODERATOR_IN_CATEGORY', $currentMessage->id, $currentMessage->catid);
			return false;
		}

		// Assemble delete logic based on $mode
		switch ($mode) {
			case KN_DEL_MESSAGE : //Delete only the actual message
				$sql = "UPDATE #__kunena_messages SET `hold`=2 WHERE `id`='$MessageID';";
				if ( $currentMessage->parent == 0 ) {
					$this->_setSecondMessageParent ($MessageID, $currentMessage);
				}
				break;
			case KN_DEL_MESSAGE_PERMINANTLY : // Delete the message from the database
				// FIXME: if only admins are allowed to do this, add restriction (and make it general/changeble)
				$sql = "DELETE FROM #__kunena_messages WHERE `id`='$MessageID';";

				$query = "DELETE FROM #__kunena_messages_text WHERE `mesid`='$MessageID'; ";
				$this->_db->setQuery ($query);
				$this->_db->query ();
				if (KunenaError::checkDatabaseError()) return false;

				if ( $currentMessage->parent == 0 ) {
					$this->_setSecondMessageParent ($MessageID, $currentMessage);
				}

				if ( $currentMessage->userid > 0) {
					$query = "UPDATE #__kunena_users SET posts=posts-1 WHERE `userid`='$MessageID'; ";
					$this->_db->setQuery ($query);
					$this->_db->query ();
					if (KunenaError::checkDatabaseError()) return false;
				}
				break;
			case KN_DEL_THREAD : //Delete a complete thread
				$sql1 = "UPDATE #__kunena_messages SET `hold`=2 WHERE `id`='$MessageID';";
				$this->_db->setQuery ( $sql1 );
				$this->_db->query ();
				if (KunenaError::checkDatabaseError()) return false;
				$sql = "UPDATE #__kunena_messages SET `hold`=3 WHERE hold IN (0,1) AND `thread`='{$currentMessage->thread}' AND `id`!='$MessageID' ;";
				break;
			case KN_DEL_ATTACH : //Delete only the attachments
				require_once (KUNENA_PATH_LIB.DS.'kunena.attachments.class.php');
				$attachments = CKunenaAttachments::getInstance();
				$attachments->deleteMessage($MessageID);
				break;
			default :
				// Unsupported mode - Error!
				$this->_errormsg = JText::_('COM_KUNENA_MODERATION_ERROR_UNSUPPORTED_MODE');
				return false;
		}

		// Execute delete
		if (isset($sql)) {
			$this->_db->setQuery ( $sql );
			$this->_db->query ();
			if (KunenaError::checkDatabaseError()) return false;
		}

		// Remember to delete ghost post
		// FIXME: replies may have ghosts, too. What to do with them?
		$this->_db->setQuery ( "SELECT m.id FROM #__kunena_messages AS m INNER JOIN #__kunena_messages_text AS t ON m.`id`=t.`mesid`
			WHERE `moved`=1;" );
		$ghostMessageID = $this->_db->loadResult ();
		if (KunenaError::checkDatabaseError()) return false;

		if ( !empty($ghostMessageID) ) {
			$this->_db->setQuery ( "UPDATE #__kunena_messages SET `hold`=2 WHERE `id`='$ghostMessageID' AND `moved`=1;" );
			$this->_db->query ();
			if (KunenaError::checkDatabaseError()) return false;
		}

		// Check result to see if we need to abord and set error message


		// When done log the action
		$this->_Log ( 'Delete', $MessageID, 0, '', 0, $mode );

		// Last but not least update forum stats
		CKunenaTools::reCountBoards();

		return true;
	}

	protected function _Log($Task, $MessageID = 0, $TargetCatID = 0, $TargetSubject = '', $TargetMessageID = 0, $mode = 0) {
		// Implement logging utilizing CKunenaLogger class
	}

	// Public interface


	public function move($ThreadID, $TargetCatID, $TargetSubject = '', $TargetMessageID = 0, $mode = KN_MOVE_MESSAGE, $GhostThread = false) {
		return $this->_Move ( $ThreadID, $TargetCatID, $TargetSubject, $TargetMessageID, $mode, $GhostThread );
	}

	public function moveThread($ThreadID, $TargetCatID, $GhostThread = false) {
		return $this->_Move ( $ThreadID, $TargetCatID, '', 0, KN_MOVE_THREAD, $GhostThread );
	}

	public function moveMessage($ThreadID, $TargetCatID, $TargetSubject = '', $TargetThreadID = 0) {
		return $this->_Move ( $ThreadID, $TargetCatID, $TargetSubject, $TargetThreadID, KN_MOVE_MESSAGE );
	}

	public function moveMessageAndNewer($ThreadID, $TargetCatID, $TargetSubject = '', $TargetThreadID = 0) {
		return $this->_Move ( $ThreadID, $TargetCatID, $TargetSubject, $TargetThreadID, KN_MOVE_NEWER );
	}

	public function moveMessageAndReplies($ThreadID, $TargetCatID, $TargetSubject = '', $TargetThreadID = 0) {
		return $this->_Move ( $ThreadID, $TargetCatID, $TargetSubject, $TargetThreadID, KN_MOVE_REPLIES );
	}

	public function deleteThread($ThreadID, $DeleteAttachments = false) {
		return $this->_Delete ( $ThreadID, $DeleteAttachments, KN_DEL_THREAD );
	}

	public function deleteMessagePerminantly($MessageID, $DeleteAttachments = false) {
		return $this->_Delete ( $MessageID, $DeleteAttachments, KN_DEL_MESSAGE_PERMINANTLY );
	}

	public function deleteMessage($MessageID, $DeleteAttachments = false) {
		return $this->_Delete ( $MessageID, $DeleteAttachments, KN_DEL_MESSAGE );
	}

	public function deleteAttachments($MessageID) {
		return $this->_Delete ( $MessageID, true, KN_DEL_ATTACH );
	}

	// If a function failed - a detailed error message can be requested
	public function getErrorMessage() {
		return $this->_errormsg;
	}

	protected function _setSecondMessageParent ($MessageID, $currentMessage){
		// We are about to pull the thread starter from the original thread.
		// Need to promote the second post of the original thread as the new starter.
		$sqlnewparent = "SELECT `id` FROM #__kunena_messages WHERE `id`!={$MessageID} AND `thread`='{$currentMessage->thread}' ORDER BY `id` ASC";
		$this->_db->setQuery ( $sqlnewparent, 0, 1 );
		$newParent = $this->_db->loadObject ();
		if (KunenaError::checkDatabaseError()) return false;

		if ( is_object( $newParent ) ) {
			$sql1 = "UPDATE #__kunena_messages SET `thread`='$newParent->id', `parent`=0 WHERE `id`='$newParent->id';";
			$this->_db->setQuery ( $sql1 );
			$this->_db->query ();
			// TODO: leave parent alone after checking that it's possible in our code..
			$sql2 = "UPDATE #__kunena_messages SET `thread`='$newParent->id', `parent`='$newParent->id' WHERE `thread`='{$currentMessage->thread}' AND `id`!='$newParent->id';";
			$this->_db->setQuery ( $sql2 );
			$this->_db->query ();
		}
		return true;
	}

	protected function _createGhostThread($MessageID,$currentMessage) {
		// Post time in ghost message is the same as in the last message of the thread
		$sql="SELECT `time` AS timestamp FROM #__kunena_messages WHERE `thread`='$MessageID' ORDER BY id DESC";
		$this->_db->setQuery ( $sql, 0, 1 );
		$lastTimestamp = $this->_db->loadResult ();
		if (KunenaError::checkDatabaseError()) return false;
		if ($lastTimestamp == "") {
			$lastTimestamp = $currentMessage->timestamp;
		}

		// TODO: what do we do with ghost message title? JText::_('COM_KUNENA_MOVED_TOPIC') was used before
		// @Oliver: I'd like to get rid of it and add it while rendering..
		$myname = $this->_config->username ? $this->_my->username : $this->_my->name;

		$sql = "INSERT INTO #__kunena_messages (`parent`, `subject`, `time`, `catid`, `moved`, `userid`, `name`) VALUES ('0','{$currentMessage->subject}','$lastTimestamp','{$currentMessage->catid}','1', '{$this->_my->id}', " . $this->_db->Quote ( $myname ) . ")";
		$this->_db->setQuery ( $sql );
		$this->_db->query ();
		if (KunenaError::checkDatabaseError()) return false;

		//determine the new location for link composition
		$newId = $this->_db->insertid ();

		// and update the thread id on the 'moved' post for the right ordering when viewing the forum..
		$sql = "UPDATE #__kunena_messages SET `thread`='$newId' WHERE `id`='$newId'";
		$this->_db->setQuery ( $sql );
		$this->_db->query ();
		if (KunenaError::checkDatabaseError()) return false;

		// TODO: we need to fix all old ghost messages and change behaviour of them
		$newURL = "id=" . $currentMessage->id;
		$sql = "INSERT INTO #__kunena_messages_text (`mesid`, `message`) VALUES ('$newId', '$newURL')";
		$this->_db->setQuery ( $sql );
		$this->_db->query ();
		if (KunenaError::checkDatabaseError()) return false;

		return true;
	}

	public function createGhostThread($MessageID,$currentMessage) {
		return $this->_createGhostThread($MessageID,$currentMessage);
	}

	public function getUserIPs ($UserID) {
		// Sanitize parameters!
		$UserID = intval ( $UserID );

		$this->_db->setQuery ( "SELECT ip FROM #__kunena_messages WHERE userid=$UserID GROUP BY ip" );
		$ipslist = $this->_db->loadObjectList ();
		KunenaError::checkDatabaseError();

		return $ipslist;
	}

	public function getUsernameMatchingIPs ($UserID) {
		// Sanitize parameters!
		$UserID = intval ( $UserID );

		$ipslist = $this->getUserIPs ($UserID);

		$useridslist = array();
		foreach ($ipslist as $ip) {
			$this->_db->setQuery ( "SELECT name,userid FROM #__kunena_messages WHERE ip='$ip->ip' GROUP BY name" );
			$useridslist[$ip->ip] = $this->_db->loadObjectList ();
			KunenaError::checkDatabaseError();
		}

		return $useridslist;
	}
}
?>