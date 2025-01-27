<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/
include_once 'modules/Settings/MailScanner/core/MailRecord.php';
include_once __DIR__ . '/../helpers/Utils.php';
require_once __DIR__ .'/../../config.inc.php';

class MailManager_Model_Message extends Vtiger_MailRecord {

	/**
	 * Sets the Imap connection
	 * @var String
	 */
	protected $mBox;

	/**
	 * Marks the mail Read/UnRead
	 * @var Boolean
	 */
	protected $mRead = false;

	/**
	 * Sets the Mail Message Number
	 * @var Integer
	 */
	protected $mMsgNo;

	/**
	 * Sets the Mail Unique Number
	 * @var Integer
	 */
	protected $mUid;

	/**
	 * Constructor which gets the Mail details from the server
	 * @param String $mBox - Mail Box Connection string
	 * @param Integer $msgno - Mail Message Number
	 * @param Boolean $fetchbody - Used to save the mail information to DB
	 */
	public function __construct($mBox = false, $msgno = false, $fetchbody = false) {
		if ($mBox && $msgno) {
			$this->mBox = $mBox;
			$this->mMsgNo = $msgno;
			$loaded = false;

			// Unique ID based on sequence number
			$this->mUid = imap_uid($mBox, $msgno);
			if ($fetchbody) {
				// Lookup if there was previous cached message
				$loaded = $this->readFromDB($this->mUid);
			}
			if (!$loaded) {
				parent::__construct($mBox, $msgno, $fetchbody);
				if ($fetchbody) {
					// Save for further use
					$loaded = $this->saveToDB($this->mUid);
				}
			}
			if ($loaded) {
				$this->setRead(true);
				$this->setMsgNo((int)$msgno);
			}
		}
	}

	/**
	 * Gets the Mail Body and Attachments
	 * @param String $imap - Mail Box connection string
	 * @param Integer $messageid - Mail Number
	 * @param Object $p
	 * @param Integer $partno
	 */
	// Modified: http://in2.php.net/manual/en/function.imap-fetchstructure.php#85685
	public function __getpart($imap, $messageid, $p, $partno) {
		// $partno = '1', '2', '2.1', '2.1.3', etc if multipart, 0 if not multipart

		if ($partno) {
			$maxDownLoadLimit = ConfigPrefs::get('MAXDOWNLOADLIMIT');
			if ($p->bytes < $maxDownLoadLimit) {
				$data = imap_fetchbody($imap, $messageid, $partno);  // multipart
			}
		} else {
			$data = imap_body($imap, $messageid); //not multipart
		}
		// Any part may be encoded, even plain text messages, so check everything.
		if ($p->encoding==4) {
			$data = quoted_printable_decode($data);
		} elseif ($p->encoding==3) {
			$data = base64_decode($data);
		}
		// no need to decode 7-bit, 8-bit, or binary

		// PARAMETERS
		// get all parameters, like charset, filenames of attachments, etc.
		$params = array();
		if (!empty($p->parameters)) {
			foreach ($p->parameters as $x) {
				$params[ strtolower($x->attribute) ] = $x->value;
			}
		}
		if (!empty($p->dparameters)) {
			foreach ($p->dparameters as $x) {
				$params[ strtolower($x->attribute) ] = $x->value;
			}
		}

		// ATTACHMENT
		// Any part with a filename is an attachment,
		// so an attached text file (type 0) is not mistaken as the message.
		if (!empty($params['filename']) || !empty($params['name'])) {
			// filename may be given as 'Filename' or 'Name' or both
			$filename = (!empty($params['filename'])) ? $params['filename'] : $params['name'];
			// filename may be encoded, so see imap_mime_header_decode()
			if (!$this->_attachments) {
				$this->_attachments = array();
			}
			$this->_attachments[$filename] = $data;  // this is a problem if two files have same name
		} elseif ($p->ifdisposition && $p->disposition == 'INLINE' && $p->bytes > 0 && $p->subtype != 'PLAIN' && $p->subtype != 'HTML') {
			// embedded images right now are treated as attachments
			$this->_attachments['noname'.$partno. '.' .$p->subtype] = $data;
		} elseif ($p->type==0 && $data) {
			// TEXT
			$this->_charset = substr($params['charset'], 0, 10);  // assume all parts are same charset
			$data = self::__convert_encoding($data, 'UTF-8', $this->_charset);

			// Messages may be split in different parts because of inline attachments,
			// so append parts together with blank row.
			if (strtolower($p->subtype)=='plain') {
				$this->_plainmessage .= trim($data) ."\n\n";
			} else {
				$this->_htmlmessage .= $data .'<br><br>';
			}
		} elseif ($p->type==2 && $data) {
			// EMBEDDED MESSAGE
			// Many bounce notifications embed the original message as type 2,
			// but AOL uses type 1 (multipart), which is not handled here.
			// There are no PHP functions to parse embedded messages,
			// so this just appends the raw source to the main message.
			$this->_plainmessage .= trim($data) ."\n\n";
		}

		// SUBPART RECURSION
		if (!empty($p->parts)) {
			foreach ($p->parts as $partno0 => $p2) {
				$this->__getpart($imap, $messageid, $p2, $partno.'.'.($partno0+1));  // 1.2, 1.2.1, etc.
			}
		}
	}

	/**
	 * Clears the cache data
	 * @global PearDataBase Instance $adb
	 * @global Users Instance $current_user
	 * @param Integer $waybacktime
	 */
	public static function pruneOlderInDB($waybacktime) {
		global $adb, $current_user;

		//remove the saved attachments
		self::removeSavedAttachmentFiles($waybacktime);

		$adb->pquery('DELETE FROM vtiger_mailmanager_mailrecord WHERE userid=? AND lastsavedtime < ?', array($current_user->id, $waybacktime));
		$adb->pquery('DELETE FROM vtiger_mailmanager_mailattachments WHERE userid=? AND lastsavedtime < ?', array($current_user->id, $waybacktime));
	}

	/**
	 * Used to remove the saved attachments
	 * @global Users Instance $current_user
	 * @global PearDataBase Instance $adb
	 * @param Integer $waybacktime - timestamp
	 */
	public static function removeSavedAttachmentFiles($waybacktime) {
		global $current_user, $adb;

		$mailManagerAttachments = $adb->pquery(
			'SELECT attachid, aname, path FROM vtiger_mailmanager_mailattachments WHERE userid=? AND lastsavedtime < ?',
			array($current_user->id, $waybacktime)
		);

		for ($i=0; $i<$adb->num_rows($mailManagerAttachments); $i++) {
			$atResultRow = $adb->raw_query_result_rowdata($mailManagerAttachments, $i);

			$adb->pquery('UPDATE vtiger_crmentity set deleted = 1 WHERE crmid = ?', array($atResultRow['attachid']));

			$filepath = $atResultRow['path'] .'/'. $atResultRow['attachid'] .'_'. $atResultRow['aname'];
			if (file_exists($filepath)) {
				unlink($filepath);
			}
		}
	}

	/**
	 * Reads the Mail information from the Database
	 * @global PearDataBase Instance $adb
	 * @global User Instance $current_user
	 * @param Integer $uid
	 * @return Boolean
	 */
	public function readFromDB($uid) {
		global $adb, $current_user;
		$result = $adb->pquery('SELECT * FROM vtiger_mailmanager_mailrecord WHERE userid=? AND muid=?', array($current_user->id, $uid));
		if ($adb->num_rows($result)) {
			$resultrow = $adb->fetch_array($result);
			$this->mUid  = decode_html($resultrow['muid']);

			$this->_from = json_decode(decode_html($resultrow['mfrom']));
			$this->_to   = json_decode(decode_html($resultrow['mto']));
			$this->_reply_to = json_decode(decode_html($resultrow['mreplyto']));
			$this->_cc   = json_decode(decode_html($resultrow['mcc']));
			$this->_bcc  = json_decode(decode_html($resultrow['mbcc']));

			$this->_date	= decode_html($resultrow['mdate']);
			$this->_subject = str_replace('_', ' ', decode_html($resultrow['msubject']));
			$this->_body    = decode_html($resultrow['mbody']);
			$this->_charset = substr(decode_html($resultrow['mcharset']), 0, 10); // to cut -i in case it is there

			$this->_isbodyhtml   = (int)$resultrow['misbodyhtml'] ? true : false;
			$this->_plainmessage = $resultrow['mplainmessage'];
			$this->_htmlmessage  = $resultrow['mhtmlmessage'];
			$this->_uniqueid     = decode_html($resultrow['muniqueid']);
			$this->_bodyparsed   = (int)$resultrow['mbodyparsed'] ? true : false;

			return true;
		}
		return false;
	}

	/**
	 * Loads the Saved Attachments from the DB
	 * @global PearDataBase Instance$adb
	 * @global Users Instance $current_user
	 * @global Array $upload_badext - List of bad extensions
	 * @param Boolean $withContent - Used to load the Attachments with/withoud content
	 * @param String $aName - Attachment Name
	 */
	protected function loadAttachmentsFromDB($withContent, $aName = false) {
		global $adb, $current_user, $upload_badext;

		if (empty($this->_attachments)) {
			$this->_attachments = array();

			$params = array($current_user->id, $this->muid());

			$filteredColumns = 'aname, attachid';
			if ($withContent) {
				$filteredColumns = 'aname, attachid, path';
			}

			$whereClause = '';
			if ($aName) {
				$whereClause = ' AND aname=?';
				$params[] = $aName;
			}

			$atResult = $adb->pquery("SELECT {$filteredColumns} FROM vtiger_mailmanager_mailattachments WHERE userid=? AND muid=? $whereClause", $params);

			if ($adb->num_rows($atResult)) {
				for ($atIndex = 0; $atIndex < $adb->num_rows($atResult); ++$atIndex) {
					$atResultRow = $adb->raw_query_result_rowdata($atResult, $atIndex);
					if ($withContent) {
						$binFile = sanitizeUploadFileName($atResultRow['aname'], $upload_badext);
						$saved_filename = $atResultRow['path'] . $atResultRow['attachid']. '_' .$binFile;
						if (file_exists($saved_filename)) {
							$fileContent = @fread(fopen($saved_filename, 'r'), filesize($saved_filename));
						} else {
							$fileContent = '';
						}
					}
					$this->_attachments[$atResultRow['aname']] = ($withContent? $fileContent: false);
					unset($fileContent); // Clear immediately
				}

				$atResult->free();
				unset($atResult); // Indicate cleanup
			}
		}
	}

	/**
	 * Save the Mail information to DB
	 * @global PearDataBase Instance $adb
	 * @global Users Instance $current_user
	 * @param Integer $uid - Mail Unique Number
	 * @return Boolean
	 */
	protected function saveToDB($uid) {
		global $adb, $current_user;

		$savedtime = strtotime('now');

		$params = array($current_user->id);
		$params[] = $uid;
		$params[] = json_encode($this->_from);
		$params[] = json_encode($this->_to);
		$params[] = json_encode($this->_reply_to);
		$params[] = json_encode($this->_cc);
		$params[] = json_encode($this->_bcc);
		$params[] = $this->_date;
		$params[] = $this->_subject;
		$params[] = $this->_body;
		$params[] = substr($this->_charset, 0, 10);
		$params[] = $this->_isbodyhtml;
		$params[] = $this->_plainmessage;
		$params[] = $this->_htmlmessage;
		$params[] = $this->_uniqueid;
		$params[] = $this->_bodyparsed;
		$params[] = $savedtime;

		$adb->pquery(
			'INSERT INTO vtiger_mailmanager_mailrecord (userid, muid, mfrom, mto, mreplyto, mcc, mbcc,
				mdate, msubject, mbody, mcharset, misbodyhtml, mplainmessage, mhtmlmessage, muniqueid,
				mbodyparsed, lastsavedtime) VALUES ('.generateQuestionMarks($params).')',
			$params
		);

		// Take care of attachments...
		if (!empty($this->_attachments)) {
			foreach ($this->_attachments as $aName => $aValue) {
				$attachInfo = $this->__SaveAttachmentFile($aName, $aValue);

				if (is_array($attachInfo) && !empty($attachInfo)) {
					$adb->pquery(
						'INSERT INTO vtiger_mailmanager_mailattachments (userid, muid, attachid, aname, path, lastsavedtime) VALUES (?, ?, ?, ?, ?, ?)',
						array($current_user->id, $uid, $attachInfo['attachid'], $attachInfo['name'], $attachInfo['path'], $savedtime)
					);

					unset($this->_attachments[$aName]);					// This is needed first when we save attachment with invalid file extension,
					$this->_attachments[$attachInfo['name']] = $aValue; // so the file name has to renamed.
				}
				unset($aValue);
			}
		}
		return true;
	}

	/**
	 * Save the Mail Attachments to DB
	 * @global PearDataBase Instance $adb
	 * @global Users Instance $current_user
	 * @global Array $upload_badext
	 * @param String $filename - name of the file
	 * @param Text $filecontent
	 * @return Array with attachment information
	 */
	public function __SaveAttachmentFile($filename, $filecontent) {
		require_once 'modules/Settings/MailScanner/core/MailAttachmentMIME.php';

		global $adb, $current_user, $upload_badext;

		$dirname = decideFilePath();
		$usetime = $adb->formatDate(date('ymdHis'), true);
		$binFile = sanitizeUploadFileName($filename, $upload_badext);

		$attachid = $adb->getUniqueId('vtiger_crmentity');
		$saveasfile = $dirname . '/' . $attachid. '_' .$binFile;

		$fh = fopen($saveasfile, 'wb');
		fwrite($fh, $filecontent);
		fclose($fh);

		$mimetype = MailAttachmentMIME::detect($saveasfile);

		$adb->pquery(
			'INSERT INTO vtiger_crmentity(crmid,smcreatorid,smownerid,modifiedby,setype,description,createdtime,modifiedtime,presence,deleted) VALUES (?,?,?,?,?,?,?,?,?,?)',
			array($attachid, $current_user->id, $current_user->id, $current_user->id, 'MailManager Attachment', $binFile, $usetime, $usetime, 1, 0)
		);

		$adb->pquery(
			'INSERT INTO vtiger_attachments SET attachmentsid=?, name=?, description=?, type=?, path=?',
			array($attachid, $binFile, $binFile, $mimetype, $dirname)
		);

		return array('attachid'=>$attachid, 'path'=>$dirname, 'name'=>$binFile, 'type'=>$mimetype, 'size'=>filesize($saveasfile));
	}

	/**
	 * Gets the Mail Attachments
	 * @param Boolean $withContent
	 * @param String $aName
	 * @return List of Attachments
	 */
	public function attachments($withContent = true, $aName = false) {
		$this->loadAttachmentsFromDB($withContent, $aName);
		return $this->_attachments;
	}

	/**
	 * Gets the Mail Subject
	 * @param Boolean $safehtml
	 * @return String
	 */
	public function subject($safehtml = true) {
		if ($safehtml) {
			return MailManager_Utils::safe_html_string($this->_subject);
		}
		return $this->_subject;
	}

	/**
	 * Sets the Mail Subject
	 * @param String $subject
	 */
	public function setSubject($subject) {
		$this->_subject = @self::__mime_decode($subject);
	}

	/**
	 * Gets the Mail Body
	 * @param Boolean $safehtml
	 * @return String
	 */
	public function body($safehtml = true) {
		return $this->getBodyHTML($safehtml);
	}

	/**
	 * Gets the Mail Body
	 * @param Boolean $safehtml
	 * @return String
	 */
	public function getBodyHTML($safehtml = true) {
		$bodyhtml = parent::getBodyHTML();
		if ($safehtml) {
			$bodyhtml = MailManager_Utils::safe_html_string($bodyhtml);
		}
		return $bodyhtml;
	}

	/**
	 * Gets the Mail From
	 * @param Integer $maxlen
	 * @return string
	 */
	public function from($maxlen = 0) {
		$fromString = $this->_from;
		if ($maxlen && strlen($fromString) > $maxlen) {
			$fromString = substr($fromString, 0, $maxlen-3).'...';
		}
		return $fromString;
	}

	/**
	 * Sets the Mail From Email Address
	 * @param Email $from
	 */
	public function setFrom($from) {
		$mailFrom = str_replace('_', ' ', $from);
		$this->_from = @self::__mime_decode($mailFrom);
	}

	/**
	 * Gets the Mail To Email Addresses
	 * @return Email(s)
	 */
	public function to() {
		return $this->_to;
	}

	/**
	 * Gets the Mail To Email Addresses
	 * @return Email(s)
	 */
	public function replyto() {
		return $this->_reply_to;
	}

	/**
	 * Gets the Mail CC Email Addresses
	 * @return Email(s)
	 */
	public function cc() {
		return $this->_cc;
	}

	/**
	 * Gets the Mail BCC Email Addresses
	 * @return Email(s)
	 */
	public function bcc() {
		return $this->_bcc;
	}

	/**
	 * Gets the Mail Unique Identifier
	 * @return String
	 */
	public function uniqueid() {
		return $this->_uniqueid;
	}

	/**
	 * Gets the Mail Unique Number
	 * @return Integer
	 */
	public function muid() {
		// unique message sequence id = imap_uid($msgno)
		return $this->mUid;
	}

	/**
	 * Gets the Mail Date
	 * @param Boolean $format
	 * @return Date
	 */
	public function date($format = false) {
		$date = $this->_date;
		if (preg_match('#[^0-9\-\/]#', $date)) {
			// date is in textual format we try to put it into ISO
			$date = date('Y-m-d', strtotime($date));
		}
		if ($format) {
			if (preg_match(sprintf('/%s ([^ ]+)/', date('D, d M Y')), $date, $m)) {
				$date = $m[1]; // Pick only time part for today
			} elseif (preg_match('/[a-zA-Z]{3}, ([0-9]{1,2} [a-zA-Z]{3} [0-9]{4})/', $date, $m)) {
				$date = $m[1]; // Pick only date part
			}
			return str_replace('--', '', getValidDisplayDate($date));
		} else {
			$dateWithTime = new DateTimeField(date('Y-m-d H:i:s', $date));
			return $dateWithTime->getDisplayDateTimeValue();
		}
	}

	/**
	 * Sets the Mail Date
	 * @param Date $date
	 */
	public function setDate($date) {
		$this->_date = $date;
	}

	/**
	 * Checks if the Mail is read
	 * @return Boolean
	 */
	public function isRead() {
		return $this->mRead;
	}

	/**
	 * Sets if the Mail is read
	 * @param Boolean $read
	 */
	public function setRead($read) {
		$this->mRead = $read;
	}

	/**
	 * Gets the Mail Message Number
	 * @param Integer $offset
	 * @return Integer
	 */
	public function msgNo($offset = 0) {
		return $this->mMsgNo + $offset;
	}

	/**
	 * Sets the Mail Message Number
	 * @param Integer $msgno
	 */
	public function setMsgNo($msgno) {
		$this->mMsgNo = $msgno;
	}

	/**
	 * Sets the Mail Headers
	 * @param Object $result
	 * @return self
	 */
	public static function parseOverview($result) {
		$instance = new self();
		$instance->setSubject(isset($result->subject) ? $result->subject : '');
		$instance->setFrom((isset($result->from) ? $result->from : ''));
		$instance->setDate($result->date);
		$instance->setRead($result->seen);
		$instance->setMsgNo($result->msgno);
		return $instance;
	}
}
?>