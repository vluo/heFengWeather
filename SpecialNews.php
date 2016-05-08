<?php
/**
 *
 * Created at: 6:18 PM 8/14/14
 */

namespace api\models;

use common\base\Helper;
use common\base\ImgSize;
use common\base\MyDate;
use common\models\Album;
use common\models\News;
use common\models\UserReadLog;
use Yii;
use common\models\common\models;

class SpecialNews
{
	use NewsCommon;

    public static function articles($extraParams = [], $enableTop = true)
    {
        $taskId = isset($extraParams['taskId']) ? $extraParams['taskId'] : 0;

        $cateIds = Helper::parseIds(Yii::$app->request->get('tagId'));
        if (empty($cateIds)) {
            $cateIds = Helper::parseIds(Yii::$app->request->get('tagIds'));
        }
        $subCateIds = Helper::parseIds(Yii::$app->request->get('subCateId'));
        $limit = Helper::parseInt(Yii::$app->request->get('limit'), 30);
        $thumbWidth = Helper::parseInt(Yii::$app->request->get('thumbWidth'), 0);
        $geoCode = trim(Yii::$app->request->get('geoCode', ''));
        $random = intval(Yii::$app->request->get('random', 0));          //第一页数据是否随机乱序
        $exIds = Helper::parseIds(Yii::$app->request->get('exIds', '')); //已读文字ID
        $sortBy = Yii::$app->request->get('sortBy', 'createTime');       //排序字段 

        $tsAId = Helper::parseInt(Yii::$app->request->get('tsAId'), 0);
        $page = Helper::parseInt(Yii::$app->request->get('page'), 1);
        if ($page < 1) $page = 1;

        $days = '14';

        if ($limit > 100) $limit = 100;

        $platform = isset($extraParams['platform']) ? $extraParams['platform'] : '';
        $thumbWidth = ImgSize::thumb($platform, $thumbWidth);

        $memKey = md5('api.news.special' . implode(',', $cateIds) . implode(',', $subCateIds) . $limit . $thumbWidth . $geoCode . $days . $tsAId . $page . $taskId . $random . $sortBy . implode(',', $exIds));
        $res = Yii::$app->getCache()->get($memKey);
        if (!$random && $res) { 
            $resp = json_decode($res, true);
            if (!empty($resp)) {

                if (!empty($resp['top'])) {
                    foreach ($resp['top'] as $index => $list) {
                        $resp['top'][$index]['rawUrl'] = News::getLatestRawUrl($list['aId'], $extraParams);
                    }
                }

                foreach ($resp['lists'] as $index => $list) {
                    $resp['lists'][$index]['rawUrl'] = News::getLatestRawUrl($list['aId'], $extraParams);
                }
            }
            if (YII_ENV_PROD) return $resp;
        }

        $query = null;
        $where = $orWhere = [];
        if ([8] == $cateIds) { //特殊处理美女图集
            $where = ['status' => 1];
            $query = Album::find();

        } elseif (!empty($cateIds) || !empty($subCateIds)) {
            $selectedFields = News::listFields();
            if ([9] == $cateIds) { //段子的
                $selectedFields[] = 'content';
            }

            if(!empty($cateIds)) {
            	$where = ['status' => 1, 'cate_id' => $cateIds,];
            	$orWhere = ['status' => 1, 'sub_cate_id' => $cateIds];
            	}
               //二级栏目 vluo
            //if(!empty($subCateIds)) $where = ['status' => 1, 'sub_cate_id'=>$subCateIds];            
            $query = News::find()->select($selectedFields);  
        } elseif ($taskId > 0) {
            $selectedFields = News::listFields();
            if ([22] == $cateIds) { //微博的
                $selectedFields[] = 'content';
            }

            $where = ['task_id' => $taskId, 'status' => 1];
            $query = News::find()->select($selectedFields);
        }

        if (null == $query) return [];

        if ($tsAId > 0) {
            $where['id'] = ['$lte' => $tsAId];
        }
        $where['created_at'] = ['$gt' => MyDate::mongoDate(strtotime('-' . $days . ' days'))];

        $query->where($where);
           //二级栏目 vluo
        if($orWhere) {
        	   $query->orWhere($orWhere);
           }
        if(!empty($exIds)) {
           	$query->andWhere(['not in', 'id', $exIds]);
           }
        $total = $query->count();

        $results = [];
        $sortFields = ['is_top' => SORT_DESC, 'created_at' => SORT_DESC, 'cate_id' => SORT_ASC, 'id' => SORT_DESC];
        if($sortBy == 'sortTime') {
        	   $sortFields = ['is_top' => SORT_DESC, 'sort_time' => SORT_DESC, 'created_at' => SORT_DESC, 'cate_id' => SORT_ASC, 'id' => SORT_DESC];
          }
          
        if($page == 1 && $random == 1) {
        		$resultList = $query->orderBy($sortFields)->limit($limit*2)->all();
	        	if($resultList) {
	        		  foreach($resultList as $key => $result) {
	        		  	   if($result->is_top == 1) {
	        		  	   	$results[] = $result;
	        		  	   	unset($resultList[$key]);
	        		  	   }
	        		  	   if(count($results) == $limit) break;	        		  	   
	        		  }
	        		  if(count($results) > 1) {
	        		  	   shuffle($results);
	        		  }	
	        		 if(count($resultList) && ($len = $limit - count($results)) > 0) {
	        		 	  shuffle($resultList);  
	        		 	  $results = array_merge($results, array_slice($resultList, 0, $len));
	        		 }        			
	        	}   		     		
          } 
        if(empty($results)) {
	        	$query->limit($limit)->offset(($page - 1) * $limit);	        	
	        	if ([8] == $cateIds) {
	        		$query->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
	        	} else {	
	        		$query->orderBy($sortFields);
	        	}
	        	$results = $query->all();
          }
        

        $data = [];
        $topLists = [];
        $maxTop = 5;
        $k = 0;
        foreach ($results as $news) {
            $tmp = $news->asList($thumbWidth, $extraParams);
            if (empty($tmp['aId'])) continue;

            // 当有图片时，并且不是专题时，才放在 top里
            // 由于Android有bug, 先用这个规则
            if ($enableTop && isset($tmp['isTop']) && $tmp['isTop'] == 1 && !empty($tmp['media']) && $k < $maxTop) {//&& $tmp['isTopic'] == 0 
            // 当有图片时，才放在 top里
            // if ($enableTop && isset($tmp['isTop']) && $tmp['isTop'] == 1 && !empty($tmp['media']) && $k < $maxTop) {
                $k++;
                $topLists[] = $tmp;
            } else {
                $data[] = $tmp;
            }

            if ($page == 1 && $tmp['aId'] > $tsAId) {
                $tsAId = $tmp['aId'];
            }
        }

        $nextUrl = "";
        if (count($data) > 0 && $page * $limit < $total) {
            // nextUrl
            $nextUrlParams = [
                'tagId' => implode(',', $cateIds),
                'taskId' => $taskId,
                'limit' => $limit,
                'thumbWidth' => $thumbWidth,
                'tsAId' => $tsAId,
                'page' => $page + 1
            ];
            foreach (['uid', 'geoCode', 'provinceName', 'cityName', 'districtName', 'platform'] as $name) {
                $val = Yii::$app->request->get($name);
                if (!empty($val)) $nextUrlParams[$name] = rawurlencode($val);
            }
            $nextUrl = static::nextUrlSuffix() . '?' . http_build_query($nextUrlParams);
        }

        $res = ['lists' => $data, 'nextUrl' => $nextUrl, 'currentPage' => $page, 'limit' => $limit, 'total' => $total];
        if (!empty($topLists)) {
            $res['top'] = $topLists;
        }
        Yii::$app->getCache()->add($memKey, json_encode($res), 300); // 5分钟
        return $res;
    }
    
    /*
     * 区别“function articles”
     * 主要算法
     * 1, 缓存前$cachePageNum（默认10）页的新闻内容（以下称缓冲池），用户已读新闻$readIds
     * 2，一次请求过来，如果缓存存在，继续step3, 如果不存在或请求页超出缓存页范围，继续step4
     * 3，如果是第一页，清空该用户之前地阅读记录$readIds，获取置顶新闻$results，如果置顶新闻多于1条对他进行随机乱序排列，(第一页和非第一页均)对非置顶新闻排除已读新闻$readIds并随机排序后从中取$limit-count($result)条新闻，如果缓存的新闻数量足够继续step6， 否组执行step4
     * 4, 如果当前页在缓存页数范围外则尝试读取缓存结果$res，如果缓存不存在或页数超出缓存页数范围则继续step 5
     * 5，按照旧的规则从数据库中查找数据$cacheList，如果缓冲池失效则把$cacheList写入缓冲池， 如果请求页在缓存页范围内则对$cacheList按照step 3的规则获取$limit条新闻，否则严格按照旧规则返回$limit-count($result)条数据且如果请求页超出缓存页范围把这数据写入缓存；
     * 6， 如果$results非空则对它进行格式化$res，包括获取nextUrl等
     * 7, 更新用户已读新闻$readIds
     * 8, 返回$res
     *   */
   public static function articlesV2($extraParams = [], $enableTop = true)
    {
    	$taskId = isset($extraParams['taskId']) ? $extraParams['taskId'] : 0;
    
    	$cateIds = Helper::parseIds(Yii::$app->request->get('tagId'));
    	if (empty($cateIds)) {
    		$cateIds = Helper::parseIds(Yii::$app->request->get('tagIds'));
    	}
    	$uid = Yii::$app->request->get('uid', '');
    	$subCateIds = Helper::parseIds(Yii::$app->request->get('subCateId'));
    	$limit = Helper::parseInt(Yii::$app->request->get('limit'), 10);
    	$thumbWidth = Helper::parseInt(Yii::$app->request->get('thumbWidth'), 0);
    	$geoCode = trim(Yii::$app->request->get('geoCode', ''));
    	$random = intval(Yii::$app->request->get('random', 0));          //第一页数据是否随机乱序
    	$exIds = Helper::parseIds(Yii::$app->request->get('exIds', '')); //已存在则APP list中的新闻
    	$clickedIds = Helper::parseIds(Yii::$app->request->get('clickIds', '')); //已点击阅读过地新闻
    
    	$tsAId = Helper::parseInt(Yii::$app->request->get('tsAId'), 0); 
    	$page = Helper::parseInt(Yii::$app->request->get('page'), 1);
    	if ($page < 1) $page = 1;
    
    	$days = '15';
    	$cachePageNum = 20;//缓冲池的页数，  包含新闻数 ($limit * $cacePageNum)
    	$albumList = ([8] == $cateIds);
    	$cateIdsInt = intval(implode('', $cateIds));
    
    	if ($limit > 100) $limit = 100;
    
    	$platform = isset($extraParams['platform']) ? $extraParams['platform'] : '';
    	$thumbWidth = ImgSize::thumb($platform, $thumbWidth);
    
    	$memKey = md5('api.news.special' . implode(',', $cateIds) . implode(',', $subCateIds) . $days . $tsAId . $taskId);
    	$memKeyWithPage = $memKey.$page.$limit;
    	$resultList = Yii::$app->getCache()->get($memKey);
    	
    	$results = $res = $readIds = $addReadIds = [];
    	$fetchFromDB = TRUE;
    	$maxTop = 5;
    	$total = 0;
     
	   $readIds = self::getReadIds($uid, $exIds, $clickedIds, $page, $cateIdsInt);
	
    	$noCache = empty($resultList['list']) || empty($resultList['total']);
    	$outOfCache = FALSE;//(($page-1)*$limit) >= $total;
    	
    	//从缓存的10页数据中曲数据
    	if(!$noCache) { 
    		$total = $resultList['total'];
    		$resultList = $resultList['list'];
    		$cacheNum = count($resultList);

    		if($page <= $cachePageNum) {
    			$results = self::_fetchFromCache($uid, $page, $limit, $readIds, $maxTop, $resultList);
    			if(count($results) < $limit && $total > $cacheNum) {
    				$outOfCache = TRUE; 
    				foreach($results as $aid=>$r) {
    					$readIds[$aid] = $aid;
    				}
    			}
    		} else {
    			$outOfCache = TRUE;
    		}    		
    	} 
    	
    	if($noCache || $outOfCache) { 
    		if($page > $cachePageNum) {  
    			$res = Yii::$app->getCache()->get($memKeyWithPage);
    		}
    		
    		if(empty($res)) { 
		    	$query = null;
		    	$where = $orWhere = [];
		    	$sortFields = ['is_top' => SORT_DESC, 'created_at' => SORT_DESC, 'cate_id' => SORT_ASC, 'id' => SORT_DESC];
		    	
		    	if ($albumList) { //特殊处理美女图集
		    		$where = ['status' => 1];
		    		$query = Album::find();    
		    	} elseif (!empty($cateIds) || !empty($subCateIds)) {
		    		$selectedFields = News::listFields();
		    		if ([9] == $cateIds) { //段子的
		    			$selectedFields[] = 'content';
		    		}	    
		    		if(!empty($cateIds)) {
		    			$where = ['status' => 1, 'cate_id' => $cateIds,];
		    			$orWhere = ['status' => 1, 'sub_cate_id' => $cateIds];
		    		}
		    		//二级栏目 vluo
		    		//if(!empty($subCateIds)) $where = ['status' => 1, 'sub_cate_id'=>$subCateIds];
		    		$query = News::find()->select($selectedFields);
		    	} elseif ($taskId > 0) {
		    		$selectedFields = News::listFields();
		    		if ([22] == $cateIds) { //微博的
		    			$selectedFields[] = 'content';
		    		}	    
		    		$where = ['task_id' => $taskId, 'status' => 1];
		    		$query = News::find()->select($selectedFields);
		    	}	    
		    	if (null == $query) return [];   	
		    	
		    
		    	if ($tsAId > 0) {
		    		$where['id'] = ['$lte' => $tsAId];
		    	}
		    	$where['created_at'] = ['$gt' => MyDate::mongoDate(strtotime('-' . $days . ' days'))];    
		    	$query->where($where);
		    	//二级栏目 vluo
		    	if($orWhere) {
		    		 $query->orWhere($orWhere);
		    	}
		    	
		    	//获取缓存数据		    	
		    	if($noCache) {
		    	    $total = $query->count();
			    	 $cacheData = ['total' => $total, 'list'=>[]];
			    	 $cacheList = $query->orderBy($sortFields)->limit(10*$cachePageNum)->all();
			    	 if($cacheList) {
			    		  foreach($cacheList as $news) {
			    		      $cacheData['list'][$news->id] = $news;
			    		  }
			    	 }  
				    Yii::$app->getCache()->set($memKey, $cacheData, 300);
				    if($page <= $cachePageNum) {
				    	$results = self::_fetchFromCache($uid, $page, $limit, $readIds, $maxTop, $cacheData['list']);				    
				    }
		    	} 
		    	if(empty($results) || $outOfCache) {		
		    		$readIds = array_keys($readIds);    	
		    		if(count($results)) {
		    			$readIds = array_merge($readIds, array_keys($results));
		    		}
		    		
		    		if(!empty($readIds) && is_array($readIds)) {
		    			$query->andWhere(['not in', 'id', $readIds]);
		    		}
			    	
			    	$query->limit($limit-count($results));//->offset(($page - 1) * $limit);
			    	if ([8] == $cateIds) {
			    		$query->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
			    	} else {
			    		$query->orderBy($sortFields);
			    	}
			    	$resultAddon = $query->all();
			    	!is_array($resultAddon) && $resultAddon = [];
			   	$results = array_merge($results, $resultAddon);
	    	  }    	
    		}
    	}
    	
    	
    	if(empty($res) && !empty($results)) {
    		$data = [];
    		$topLists = [];
    		$k = 0;    		
    		foreach ($results as $news) {
    			if(in_array($news->id, $addReadIds)) {
    				continue;
    			} else {
    				$addReadIds[$news->id] = $news->id;
    			}
    			
    			$tmp = $news->asList($thumbWidth, $extraParams);
    			if (empty($tmp['aId'])) continue;
    			 
    			// 当有图片时，并且不是专题时，才放在 top里
    			// 由于Android有bug, 先用这个规则
    			if ($enableTop && isset($tmp['isTop']) && $tmp['isTop'] == 1 && !empty($tmp['media']) && $k < $maxTop) {//&& $tmp['isTopic'] == 0
    				// 当有图片时，才放在 top里
    				// if ($enableTop && isset($tmp['isTop']) && $tmp['isTop'] == 1 && !empty($tmp['media']) && $k < $maxTop) {
    				$k++;
    				$topLists[] = $tmp;
    			} else {
    				$data[] = $tmp;
    			}
    			 
    			if ($page == 1 && $tmp['aId'] > $tsAId) {
    				$tsAId = $tmp['aId'];
    			}
    		}
    		
    		$nextUrl = "";
    		if (count($data) > 0 && $page * $limit < $total) {
    		//if(count($results) == $limit && (count($readIds) + count($results)) < $total) {
    			// nextUrl
    			$nextUrlParams = [
    					'tagId' => implode(',', $cateIds),
    					'taskId' => $taskId,
    					'limit' => $limit,
    					'thumbWidth' => $thumbWidth,
    					'tsAId' => $tsAId,
    					'page' => $page + 1
    			];
    			foreach (['uid', 'geoCode', 'provinceName', 'cityName', 'districtName', 'platform'] as $name) {
    				$val = Yii::$app->request->get($name);
    				if (!empty($val)) $nextUrlParams[$name] = rawurlencode($val);
    			}
    			$nextUrl = static::nextUrlSuffix() . '?' . http_build_query($nextUrlParams);
    		}
    		
    		$res = ['lists' => $data, 'nextUrl' => $nextUrl, 'currentPage' => $page, 'limit' => $limit, 'total' => $total];
    		if (!empty($topLists)) {
    			$res['top'] = $topLists;
    		}    		
    	} elseif(!empty($res)) {
    		isset($res['top']) && $res['lists'] = array_merge($res['lists'], $res['top']);
    		foreach($res['lists'] as $news) {
    			$aid = is_array($news) ? $news['aId'] : $news->aId;
    			$addReadIds[$aid] = $aid;
    		}   
    	} elseif(empty($results)) {
    		$res = ['lists' => [], 'nextUrl' => null, 'currentPage' => $page, 'limit' => $limit, 'total' => $total];
    	}
  
   	UserReadLog::updateLog($uid, $albumList ? ['read_aids'=>$addReadIds] : ['read_ids'=>$addReadIds], $cateIdsInt);
     	
   	if($page > $cachePageNum && !empty($res)) {
    		Yii::$app->getCache()->add($memKeyWithPage, $res, 300); // 5分钟
   		}

    	return $res;
    }
  
    
    public static function ranking($extraParams = [])
    {
        $cateId = Helper::parseInt(Yii::$app->request->get('tagId'), 0);
        $cateIds = Helper::parseIds(Yii::$app->request->get('tagIds'));
        $thumbWidth = Helper::parseInt(Yii::$app->request->get('thumbWidth'), 0);
        $geoCode = trim(Yii::$app->request->get('geoCode', ''));
        $filter = Helper::parseInt(Yii::$app->request->get('filter'), 0);
        $page = Helper::parseInt(Yii::$app->request->get('page'), 1);
        if ($page < 1) $page = 1;

        $limit = Helper::parseInt(Yii::$app->request->get('limit'), 30);
        if ($limit > 100) $limit = 100;

        $days = Helper::parseInt(Yii::$app->request->get('day'), 1);
        if ($days < 1) $days = 1;
        if ($days > 30) $days = 30;

        $platform = isset($extraParams['platform']) ? $extraParams['platform'] : '';
        $thumbWidth = ImgSize::thumb($platform, $thumbWidth);

        $memKey = md5('api.news.ranking' . $cateId . $limit . $thumbWidth . $geoCode . $days . $page . implode(',', $cateIds) . $filter);

        $res = Yii::$app->getCache()->get($memKey);
        if ($res) return json_decode($res, true);

        $query = null;

        if (empty($cateIds)) {
            if (8 == $cateId) { //特殊处理美女图集
                $query = Album::find()->where(['status' => 1]);
            } elseif ($cateId > 0) {
                $query = News::find()->where(['cate_id' => $cateId, 'status' => 1]);
            } else {
                $query = News::find()->where(['status' => 1]);
            	}
        } else {
            $query = News::find()->where(['cate_id' => $cateIds, 'status' => 1]);
        }

        if (null == $query) return [];

        $query->andWhere(['created_at' => ['$gt' => MyDate::mongoDate(strtotime('-' . $days . ' days'))]]);

        if ($filter == 1) { // 仅显示有图片的资讯的排行(精选)
            $query->andWhere(['has_media' => 1]);
        }

        if (!empty($geoCode) && 10 == $cateId) { // 本地资讯
            $geoCode = substr($geoCode, 0, 4) . '00';
            $query->andWhere(['city_code' => $geoCode]);
        }

        $total = $query->count();

        $query->limit($limit)->offset(($page - 1) * $limit);
        $query->orderBy(['read_count' => SORT_DESC, 'id' => SORT_DESC]);
        $results = $query->all();

        $data = [];
        foreach ($results as $news) {
            $tmp = $news->asList($thumbWidth, $extraParams);
            if (empty($tmp['aId'])) continue;
            $data[] = $tmp;
        }

        $nextUrl = '';
        if (($page - 1) * $limit + $limit < $total) {
            $nextUrlParams = [
                'tagId' => $cateId,
                'tagIds' => implode(',', $cateIds),
                'filter' => $filter,
                'limit' => $limit,
                'page' => $page + 1,
                'thumbWidth' => $thumbWidth
            ];
            foreach (['uid', 'geoCode', 'provinceName', 'cityName', 'districtName', 'platform'] as $name) {
                $val = Yii::$app->request->get($name);
                if (!empty($val)) $nextUrlParams[$name] = $val;
            }
            $nextUrl = static::nextUrlSuffix() . '?' . http_build_query($nextUrlParams);
        }

        $res = ['lists' => $data, 'currentPage' => $page, 'limit' => $limit, 'total' => $total, 'nextUrl' => $nextUrl];
        Yii::$app->getCache()->add($memKey, json_encode($res), 600); // 10分钟
        
        return $res;
    }

    public static function nextUrlSuffix()
    {
        return implode('/', [
            Yii::$app->params['apiHost'],
            Yii::$app->controller->module->id,
            Yii::$app->request->get('_app_id'),
            Yii::$app->request->get('_client_mode'),
        		Yii::$app->controller->id,
        		Yii::$app->controller->action->id
        ]);
    }
    
   private static function _fetchFromCache($uid, $page, $limit, $readIds, $maxTop, $resultList) { 	
   	Yii::error(count($readIds).'>>'.count($resultList).'<br>');
   	if($readIds) {
   		$resultList = array_diff_key($resultList, $readIds);
   		}  	
   	if($resultList) Yii::error(count($resultList).' >> checked');
   		
   	$results = [];   	 
   	if($page == 1) {   		
   		foreach($resultList as $key => $result) {
   			if(isset($result->is_top) && $result->is_top == 1) {
   				$results[$result->id] = $result;
   				unset($resultList[$key]);
   				}
   			if(count($results) == $maxTop) break;
   			}
   		if(count($results) > 1) {
   			shuffle($results);
   			}
   		}
   	if(count($resultList) && ($len = $limit - count($results)) > 0) {
   		shuffle($resultList);
   		$results = array_merge($results, array_slice($resultList, 0, $len));
   		}

   	return $results;
    }

}