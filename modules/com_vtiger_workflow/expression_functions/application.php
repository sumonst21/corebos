<?php
/*************************************************************************************************
 * Copyright 2017 JPL TSolucio, S.L. -- This file is a part of TSOLUCIO coreBOS Customizations.
 * Licensed under the vtiger CRM Public License Version 1.1 (the "License"); you may not use this
 * file except in compliance with the License. You can redistribute it and/or modify it
 * under the terms of the License. JPL TSolucio, S.L. reserves all rights not expressly
 * granted by the License. coreBOS distributed by JPL TSolucio S.L. is distributed in
 * the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Unless required by
 * applicable law or agreed to in writing, software distributed under the License is
 * distributed on an "AS IS" BASIS, WITHOUT ANY WARRANTIES OR CONDITIONS OF ANY KIND,
 * either express or implied. See the License for the specific language governing
 * permissions and limitations under the License. You may obtain a copy of the License
 * at <http://corebos.org/documentation/doku.php?id=en:devel:vpl11>
 *************************************************************************************************/
require_once 'include/Webservices/GetRelatedRecords.php';

function __cbwf_setype($arr) {
	$ret = '';
	if (!empty($arr[0]) && strpos($arr[0], 'x') > 0) {
		list($wsid,$crmid) = explode('x', $arr[0]);
		$ret = getSalesEntityType($crmid);
	}
	return $ret;
}

function __cbwf_getimageurl($arr) {
	global $adb;
	$env = $arr[1];
	if (isset($env->moduleName)) {
		$module = $env->moduleName;
	} else {
		$module = $env->getModuleName();
	}
	$data = $env->getData();
	$recordid = $data['id'];
	list($wsid,$crmid) = explode('x', $recordid);
	if ($module == 'Contacts') {
		$imageattachment = 'Image';
	} else {
		$imageattachment = 'Attachment';
	}
	$sql = "select vtiger_attachments.*,vtiger_crmentity.setype
		from vtiger_attachments
		inner join vtiger_seattachmentsrel on vtiger_seattachmentsrel.attachmentsid = vtiger_attachments.attachmentsid
		inner join vtiger_crmentity on vtiger_crmentity.crmid = vtiger_attachments.attachmentsid
		where vtiger_crmentity.setype='$module $imageattachment' and vtiger_attachments.name = ? and vtiger_seattachmentsrel.crmid=?";
	$image_res = $adb->pquery($sql, array(str_replace(' ', '_', decode_html($arr[0])),$crmid));
	if ($adb->num_rows($image_res)==0) {
		$sql = 'select vtiger_attachments.*,vtiger_crmentity.setype
			from vtiger_attachments
			inner join vtiger_seattachmentsrel on vtiger_seattachmentsrel.attachmentsid = vtiger_attachments.attachmentsid
			inner join vtiger_crmentity on vtiger_crmentity.crmid = vtiger_attachments.attachmentsid
			where vtiger_attachments.name = ? and vtiger_seattachmentsrel.crmid=?';
		$image_res = $adb->pquery($sql, array(str_replace(' ', '_', $arr[0]),$crmid));
	}
	if ($adb->num_rows($image_res)>0) {
		$image_id = $adb->query_result($image_res, 0, 'attachmentsid');
		$image_path = $adb->query_result($image_res, 0, 'path');
		$image_name = decode_html($adb->query_result($image_res, 0, 'name'));
		if ($image_name != '') {
			$imageurl = $image_path . $image_id . '_' . urlencode($image_name);
		} else {
			$imageurl = '';
		}
	} else {
		$imageurl = '';
	}
	return $imageurl;
}

function __cb_globalvariable($arr) {
	$ret = null;
	if (!empty($arr[0])) {
		$ret = GlobalVariable::getVariable($arr[0], null);
	}
	return $ret;
}

function __cb_getcrudmode($arr) {
	$entity = $arr[0];
	if ($entity->isNew()) {
		return 'create';
	} else {
		return 'edit';
	}
}

function __cb_currentlyimporting($arr) {
	global $CURRENTLY_IMPORTING;
	return $CURRENTLY_IMPORTING;
}

function __cb_getrelatedids($arr) {
	global $current_user;
	$relids = array();
	if (count($arr)<2 || empty($arr[0])) {
		return $relids;
	}
	if (is_string($arr[1]) || is_numeric($arr[1])) {
		$recordid = $arr[1];
		$mainmodule = getSalesEntityType($arr[1]);
	} else {
		$env = $arr[1];
		$data = $env->getData();
		$recordid = $data['id'];
		if (isset($env->moduleName)) {
			$mainmodule = $env->moduleName;
		} else {
			$mainmodule = $env->getModuleName();
		}
	}
	$relmodule = $arr[0];
	try {
		$relrecords = getRelatedRecords($recordid, $mainmodule, $relmodule, ['columns' => 'id'], $current_user);
	} catch (\Throwable $th) {
		return $relids;
	}
	foreach ($relrecords['records'] as $record) {
		$relids[] = $record['id'];
	}
	return $relids;
}

function __cb_getidof($arr) {
	global $current_user, $adb;
	$qg = new QueryGenerator($arr[0], $current_user);
	$qg->setFields(array('id'));
	$qg->addCondition($arr[1], $arr[2], 'e');
	$rs = $adb->query($qg->getQuery(false, 1));
	if ($rs && $adb->num_rows($rs)>0) {
		return $adb->query_result($rs, 0, 0);
	} else {
		return 0;
	}
}

function __cb_getfieldsof($arr) {
	global $current_user, $adb;
	$qg = new QueryGenerator($arr[1], $current_user);
	if (isset($arr[2])) {
		$fields = explode(',', $arr[2]);
		$qg->setFields($fields);
	} else {
		$qg->setFields(array('*'));
	}
	$qg->addCondition('id', $arr[0], 'e');
	$rs = $adb->query($qg->getQuery(false));
	if ($rs && $adb->num_rows($rs)>0) {
		return array_filter($rs->FetchRow(), 'is_string', ARRAY_FILTER_USE_KEY);
	} else {
		return array();
	}
}

function __cb_getfromcontext($arr) {
	$str_arr = explode(',', $arr[0]);
	$variableArr = array();
	foreach ($str_arr as $vname) {
		if (strpos($vname, '.')) {
			$variableArr[$vname] = __cb_getfromcontextvalueinarrayobject($arr[1]->WorkflowContext, $vname);
		} elseif (empty($arr[1]->WorkflowContext[$vname])) {
			$variableArr[$vname] = '';
		} else {
			$variableArr[$vname] = $arr[1]->WorkflowContext[$vname];
		}
	}
	if (count($variableArr)==1) {
		return $variableArr[$arr[0]];
	} else {
		return json_encode($variableArr);
	}
}

function __cb_getfromcontextsearching($arr) {
	$str_arr = explode(',', $arr[0]);
	$variableArr = array();
	foreach ($str_arr as $vname) {
		$array = false;
		if (strpos($vname, '.')) {
			$array = __cb_getfromcontextvalueinarrayobject($arr[4]->WorkflowContext, $vname);
		} elseif (empty($arr[4]->WorkflowContext[$vname])) {
			$variableArr[$vname] = '';
		} else {
			$array = $arr[4]->WorkflowContext[$vname];
		}
		if (is_array($array)) {
			$key = array_search($arr[2], array_column($array, $arr[1]));
			if ($key!==false && !empty($array[$key])) {
				$variableArr[$vname] = __cb_getfromcontextvalueinarrayobject($array[$key], $arr[3]);
			} else {
				$variableArr[$vname] = '';
			}
		}
	}
	if (count($variableArr)==1) {
		return $variableArr[$arr[0]];
	} else {
		return json_encode($variableArr);
	}
}

function __cb_getfromcontextvalueinarrayobject($aORo, $vname) {
	$value = '';
	$levels = explode('.', $vname);
	foreach ($levels as $key) {
		if (is_array($aORo)) {
			if (!empty($aORo[$key])) {
				$value = $aORo[$key];
				$aORo = $aORo[$key];
			} else {
				$value = '';
			}
		} elseif (is_object($aORo)) {
			if (!empty($aORo->$key)) {
				$value = $aORo->$key;
				$aORo = $aORo->$key;
			} else {
				$value = '';
			}
		} else {
			$value = '';
		}
	}
	return $value;
}

function __cb_setfromcontext($arr) {
	$arr[2]->WorkflowContext[$arr[0]] = $arr[1];
	return $arr[1];
}

function __cb_getsetting($arr) {
	if (empty($arr[0])) {
		return '';
	}
	$default = (empty($arr[1]) ? '' : $arr[1]);
	return coreBOS_Settings::getSetting($arr[0], $default);
}

function __cb_setsetting($arr) {
	if (empty($arr[0]) || !isset($arr[1])) {
		return '';
	}
	coreBOS_Settings::setSetting($arr[0], $arr[1]);
	return $arr[1];
}

function __cb_delsetting($arr) {
	if (empty($arr[0])) {
		return '';
	}
	coreBOS_Settings::delSetting($arr[0]);
	return '';
}

function __cb_sendMessage($arr) {
	if (empty($arr[0])) {
		return '';
	}
	$channel = (empty($arr[1]) ? 'workflowMessageChannel' : $arr[1]);
	$time = (empty($arr[2]) ? 30 : $arr[2]);
	$cbmq = coreBOS_MQTM::getInstance();
	$cbmq->sendMessage($channel, 'workflow', 'wfmessagereader', 'Message', '1:M', 1, $time, 0, 0, $arr[0]);
	return '';
}

function __cb_readMessage($arr) {
	$channel = (empty($arr[0]) ? 'workflowMessageChannel' : $arr[0]);
	$cbmq = coreBOS_MQTM::getInstance();
	$msg = $cbmq->getMessage($channel, 'wfmessagereader', 'workflow');
	return $msg['information'];
}

function __cb_evaluateRule($arr) {
	global $logbg;
	if (count($arr)<2 || empty($arr[0])) {
		return 0;
	}
	if (!is_object($arr[1])) {
		return 0;
	}
	$env = $arr[1];
	$data = $env->getData();
	$context = array_merge((array)$env->WorkflowContext, $data);
	if (!empty($data['id'])) {
		list($wsid,$crmid) = explode('x', $data['id']);
		if (!empty($crmid)) {
			$context['record_id'] = $crmid;
		}
	}
	$result = 0;
	try {
		$result = coreBOS_Rule::evaluate($arr[0], $context);
	} catch (\Exception $e) {
		$logbg->debug(array(
			'Rule: '.$arr[0],
			$e->getCode(),
			$e->getMessage(),
			$context
		));
	}
	return $result;
}

function __cb_executesql($arr) {
	global $adb;
	$rdo = array();
	if (empty($arr) || empty($arr[0])) {
		return $rdo;
	}
	$rs = $adb->pquery($arr[0], array_slice($arr, 1));
	if ($rs) {
		while ($row = $adb->fetchByAssoc($rs, -1, false)) {
			$rdo[] = $row;
		}
	}
	return $rdo;
}
?>