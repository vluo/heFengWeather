<?php

namespace  api\models;

use common\models\UserReadLog;
use Yii;


/*
 * API获取需要排除的新闻IDS
 * @param $uid stirng    用户id
 * @param $exIds, array, $_GET['exIds] 
 * @param $clickedIds, array, $_GET['clickIds] 
 * @param $page, int, 当前页码  
 * 
 *  如果首页: exIds并上clickIds（从$_GET或从缓存中获取）再并上已读ID（read_ids/read_aids）为需要排除地IDS；
 *  如果非首页： exIds再并上已读ID（read_ids/read_aids）为需要排除地IDS；
 *   */
trait NewsCommon {
	public static function getReadIds($uid, $exIds, $clickedIds, $page, $cateId) {
		$readIds = [];
		if($exIds || $clickedIds) {
			$exIds = array_flip($exIds);
			$clickedIds = array_flip($clickedIds);
			$readIds = $page == 1 ? ($exIds + $clickedIds) : $clickedIds;
			if($page == 1) {
				UserReadLog::updateLog($uid, ['ex_ids'=>['ex'=>$exIds, 'clicked'=>$clickedIds], ($cateId == 8 ? 'read_aid' : 'read_ids')=>[]], $cateId, false);
			}
		}	
		 
		$readLog = UserReadLog::getByUid($uid);
		if($readLog) {
			if(!$readIds) {
				if(isset($readLog->ex_ids) && isset($readLog->ex_ids[$cateId])) {					
					if(isset($readLog->ex_ids[$cateId]['ex']) && is_array($readLog->ex_ids[$cateId]['ex'])) {
						//Yii::error('ex_ids count()='.count($readLog->ex_ids[$cateId]['ex']));
						$readIds = $readLog->ex_ids[$cateId]['ex'];
					}
					if($page == 1 && isset($readLog->ex_ids[$cateId]['clicked']) && is_array($readLog->ex_ids[$cateId]['clicked'])) {
						//Yii::error('click count()='.count($readLog->ex_ids[$cateId]['clicked']));
						$readIds = $readIds + $readLog->ex_ids[$cateId]['clicked'];
					}
				}
			}
			if($cateId == 8) {
				if(isset($readLog->read_aids) && is_array($readLog->read_aids)) {
					$readIds = $readIds + $readLog->read_aids;
				}				
			} else {
				if(isset($readLog->read_ids) && isset($readLog->read_ids[$cateId]) && is_array($readLog->read_ids[$cateId])) {
					//Yii::error('read_ids count()='.count($readLog->read_ids[$cateId]));
					$readIds = $readIds + $readLog->read_ids[$cateId];
				}
			}
		}	
		return $readIds;
	}	
}