<?php
/**
 *
 * Created at: 9:04 PM 7/30/14
 */

namespace api\models;

use common\base\Helper;
use common\base\ImgSize;
use common\base\MyDate;
use common\models\News;
use common\models\Region;
use common\models\Tag;
use common\models\Visitor;
use common\models\UserReadLog;
use Yii;

class LocalNews
{
	 use NewsCommon;

    public $geoCode = null;
    public $order;
    public $tagIds;
    public $thumbWidth;

    public $geoInfo = null;
    public $result = [];

    public $extraParams = [];

    /**
     * @var \common\models\Visitor
     */
    private $visitor;

    private $uid;

    // 以下4个数据相加需和 $maxListLength 一致
    private $districtNewsLen = 60; // 取最新的50条信息
    private $cityNewsLen = 180;
    private $provinceNewsLen = 90;
    private $cateNewsLen = 90;

    public static $maxListLength = 420;

    private $limitLocalNews = 30; // 返回 json.local 的 条数
    private $pType = 0;
    private $page = 0;
    private $exIds = [];
    
    private $cacheTime = 480; //缓存时间

    function initNewsParams()
    {
        $this->order = trim(Yii::$app->request->get('order', 'default'));
        $this->thumbWidth = Helper::parseInt(Yii::$app->request->get('thumbWidth'), 0);
        $this->tagIds = Helper::explode(',', Yii::$app->request->get('tagIds'), 20, true);
        $this->limitLocalNews = Helper::parseInt(Yii::$app->request->get('limit'), 30);
        $this->exIds = Helper::parseIds(Yii::$app->request->get('exIds', '')); //已存在则APPlist中的新闻
        $this->clickedIds = Helper::parseIds(Yii::$app->request->get('clickIds', '')); //已点击阅读过地新闻
        $this->pType = Helper::parseInt(Yii::$app->request->get('pType', 0), 0); // 分页类型
        $this->page = Helper::parseInt(Yii::$app->request->get('page'), 1);
        $this->uid = Yii::$app->request->get('uid', 0);
        if ($this->page < 1) $$this->page = 1;

        $this->_getGeoCode();
        $this->_setVisitor();
    }

    function asJsonWithNextUrl($extraParams = [])
    {
        $this->extraParams = $extraParams;
        $this->initNewsParams();

        $platform = isset($this->extraParams['platform']) ? $this->extraParams['platform'] : '';
        $this->thumbWidth = ImgSize::thumb($platform, $this->thumbWidth);

        $districtNews = $this->getDistrictNews();
        $cityNews = $this->getCityNews();
        $provinceNews = $this->getProvinceNews();

        // 市、区县的
        $allNews = array_merge($districtNews, $cityNews);
        if (!empty($newsInTopics)) {
            $allNews = array_merge($newsInTopics, $allNews);
        }

        // 过虑重复的
        $tmpAllNews = [];
        foreach ($allNews as $news) {
            $tmpAllNews[$news['aId']] = $news;
        }
        $allNews = array_values($tmpAllNews);

        // 排序
        if (!empty($allNews)) usort($allNews, '\api\models\LocalNews::rSort');

        // 省份的 添加到 市县的后面
        if (!empty($provinceNews)) {
            usort($provinceNews, '\api\models\LocalNews::rSort');
            $allNews = array_merge($allNews, $provinceNews);

            $tmpAllNews = [];
            foreach ($allNews as $news) {
                $tmpAllNews[$news['aId']] = $news;
            }
            $allNews = array_values($tmpAllNews);
        }

        $ts = Helper::parseInt(Yii::$app->request->get('ts'), 0); // 下一页timestamp
        $localNews = [];

        if ($this->pType == 1) {
            $localNews = array_slice($allNews, ($this->page - 1) * $this->limitLocalNews, $this->limitLocalNews);
        } else {
            if ($ts == 0) {
                $localNews = array_slice($allNews, 0, $this->limitLocalNews);
            } else {
                $i = -1;
                foreach ($allNews as $news) {
                    if ($i >= 0 && $i < $this->limitLocalNews) {
                        $localNews[] = $news;
                        $i++;
                    }
                    if ($i == $this->limitLocalNews) break;

                    if ($news['aId'] == $ts) {
                        $i++;
                    }
                }
            }
        }

        if ($this->pType != 1) {
            $endNews = end($localNews);
            $endAllNews = end($allNews);
            if (count($localNews) == $this->limitLocalNews && $endAllNews['aId'] != $endNews['aId']) {
                $ts = $endNews['aId'];
            } else {
                $ts = -1;
            }
        }

        if (!empty($localNews)) {
            foreach ($localNews as $index => $list) {
                $localNews[$index]['rawUrl'] = News::getLatestRawUrl($list['aId'], $extraParams);
            }
        }

        $res = ['local' => $localNews, 'news' => []];

        // geo info
        if ($this->_getGeoInfo()) {
            $tmpGeoInfo = $this->_getGeoInfo();
            unset($tmpGeoInfo['yahooGeoCode']);
            unset($tmpGeoInfo['bg']);
            $res['geo'] = $tmpGeoInfo;
        }

        // 天气
        $weather = $this->_getWeather();
        if (!empty($weather)) {
            $res['weather'] = $weather;
        }

        if ($this->pType == 1) {
            $res['currentPage'] = $this->page;
            $res['limit'] = $this->limitLocalNews;
            $res['total'] = count($allNews);

        } else {
            if ($ts > 0) $res['nextUrl'] = $this->nextUrl($ts);
        }

        // update last visited news ids
        if ($this->visitor) {
            $this->visitor->api_version = Yii::$app->controller->module->id;
            $this->visitor->app_id = Yii::$app->request->get('_app_id');
            $this->visitor->client_mode = Yii::$app->request->get('_client_mode');
            $this->visitor->region_code = $this->geoCode;
            $this->visitor->platform = $platform;
            $this->visitor->save();
        }

        return $res;
    }
    
    
    /*
     *  区别asJsonWithNextUrl
     *  实现首屏新闻每次刷新显示不同内容
     *   */
    function asJsonWithNextUrlV2($extraParams = [])
    {
    	$this->extraParams = $extraParams;
    	$this->initNewsParams();
    
    	$platform = isset($this->extraParams['platform']) ? $this->extraParams['platform'] : '';
    	$this->thumbWidth = ImgSize::thumb($platform, $this->thumbWidth);
    	
    	$memKey = md5('LocalNews.allNews' . $this->geoCode);
    	$cache = Yii::$app->getCache();
    	$allNews = $cache->get($memKey);
    	
    	if(empty($allNews)) {
	    	$districtNews = $this->getDistrictNews();
	    	$cityNews = $this->getCityNews();
	    	$provinceNews = $this->getProvinceNews();
	    
	    	// 市、区县的
	    	$allNews = array_merge($districtNews, $cityNews);
	    	if (!empty($newsInTopics)) {
	    		$allNews = array_merge($newsInTopics, $allNews);
	    	}
	    	
   
	    	// 过虑重复的
	    	$tmpAllNews = [];
	    	foreach ($allNews as $news) {
	    		$tmpAllNews[$news['aId']] = $news;
	    	}
	    	$allNews = array_values($tmpAllNews);
	    
	    	// 排序
	    	if (!empty($allNews)) usort($allNews, '\api\models\LocalNews::rSort');
	    
	    	// 省份的 添加到 市县的后面
	    	if (!empty($provinceNews)) {
	    		usort($provinceNews, '\api\models\LocalNews::rSort');
	    		$allNews = array_merge($allNews, $provinceNews);
	    
	    		$tmpAllNews = [];
	    		foreach ($allNews as $news) {
	    			$tmpAllNews[$news['aId']] = $news;
	    		}
	    		$allNews = array_values($tmpAllNews);
	    	}
	    	$cache->set($memKey, $allNews, $this->cacheTime);
    	}
    	
    	    	
    	$readIds = [];
    	/* $this->exIds = array_flip($this->exIds);
    	$this->clickedIds = array_flip($this->clickedIds);
    	if(($this->exIds || $this->clickedIds) && $this->page == 1) {
    		UserReadLog::updateLog($this->uid, ['ex_ids'=>['ex'=>$this->exIds, 'clicked'=>$this->clickedIds], 'read_ids'=>[]], 'local', false);
    	} */
    	$readIds = self::getReadIds($this->uid, $this->exIds, $this->clickedIds, $this->page, 'local');

  		/* $readLog = UserReadLog::getByUid($this->uid);
  		if($readLog) {
  			if(!$readIds && $this->page == 1) {
  				if(isset($readLog->ex_ids) && is_array($readLog->ex_ids) && isset($readLog->ex_ids['local'])) {
  					$readIds = $readLog->ex_ids['local'];
  				}
  			}
  			if(isset($readLog->read_ids) && is_array($readLog->read_ids) && isset($readLog->read_ids['local'])) {
  				$readIds = $readIds + $readLog->read_ids['local'];
   			}  			
   		} */

   	
    	/* if($this->page == 1) {
	    	 标记已读的新闻ID， 该已读为已通过API加载过新闻内容
	    	 * 这样保证已读新闻不会出现在APP第一屏
	    	 * 
	    	$readIdsArr = Yii::$app->getCache()->get('userReadLogArr:'.$uid);
	    	if($readIdsArr && is_array($readIdsArr)) {
	    		Yii::error(count($readIdsArr).' $readIdsArr ');
	    		$readIds = $readIds + $readIdsArr;
	    		Yii::error(count($readIds).' after merged ');
	    	}    
    	} */
    		
    	if($readIds && $allNews) {
    		$allNewsArr = [];
    		foreach($allNews as $news) {
    			$allNewsArr[$news['aId']] = $news;
    		}

    		//排除已读新闻
    		$allNews =  array_values(array_diff_key($allNewsArr, $readIds));
    		if($this->page == 1) { 
    			//UserReadLog::clear($this->uid);  //清空已读记录
    			if(count($allNews) > $this->limitLocalNews) {//缩小范围，保证较新地新闻出现则较前面
    				$allNews = array_slice($allNews, 0, $this->limitLocalNews*5);
    			}
    		}
    		//随机乱序
    		if(count($allNews)) shuffle($allNews);
    	}    	
    	
    
    	$ts = Helper::parseInt(Yii::$app->request->get('ts'), 0); // 下一页timestamp
    	$localNews = [];
      if(count($allNews)) {
      	$localNews = array_slice($allNews, ($this->page - 1) * $this->limitLocalNews, $this->limitLocalNews);        	
        }    	
    
    
    	$addReadIds = [];
    	if (!empty($localNews)) {
    		foreach ($localNews as $index => $list) {
    			$addReadIds[$list['aId']] = $list['aId'];
    			$localNews[$index]['rawUrl'] = News::getLatestRawUrl($list['aId'], $extraParams);
    		}
    	}
    	UserReadLog::updateLog($this->uid, ['read_ids'=>$addReadIds], 'local');
    
    	$res = ['local' => $localNews, 'news' => []];
    
    	if ($this->_getGeoInfo()) {
    		$tmpGeoInfo = $this->_getGeoInfo();
    		unset($tmpGeoInfo['yahooGeoCode']);
    		unset($tmpGeoInfo['bg']);
    		$res['geo'] = $tmpGeoInfo;
    	}
    
    	// 天气
    	$weather = $this->_getWeather();
    	if (!empty($weather)) {
    		$res['weather'] = $weather;
    	}
    
    	if ($this->pType == 1) {
    		$res['currentPage'] = $this->page;
    		$res['limit'] = $this->limitLocalNews;
    		$res['total'] = count($allNews);    
    	} else {
    		if(count($allNews) > $this->limitLocalNews) {
    			$res['nextUrl'] = $this->nextUrl($ts);
    		}
    	}
    
    	// update last visited news ids
    	if ($this->visitor) {
    		$this->visitor->api_version = Yii::$app->controller->module->id;
    		$this->visitor->app_id = Yii::$app->request->get('_app_id');
    		$this->visitor->client_mode = Yii::$app->request->get('_client_mode');
    		$this->visitor->region_code = $this->geoCode;
    		$this->visitor->platform = $platform;
    		$this->visitor->save();
    	}
    	return $res;
    }

    private function nextUrl($ts)
    {
        $nextUrlParams = [
            'limit' => $this->limitLocalNews,
            'thumbWidth' => $this->thumbWidth,
            'ts' => $ts,
        ];

        foreach (['uid', 'geoCode', 'provinceName', 'cityName', 'districtName', 'platform'] as $name) {
            $val = Yii::$app->request->get($name);
            if (!empty($val)) $nextUrlParams[$name] = rawurlencode($val);
        }

        $nextUrlParams['page']= $this->page +1; 
        
        $urlPrefix = implode('/', [
            Yii::$app->params['apiHost'],
            Yii::$app->controller->module->id,
            Yii::$app->request->get('_app_id'),
            Yii::$app->request->get('_client_mode'),
            Yii::$app->controller->id,
            Yii::$app->controller->action->id
        ]);

        return $urlPrefix . '?' . http_build_query($nextUrlParams);
    }

    // 清除在 topic中已经存在的news
    private function removeTopicNews(&$news, $ids)
    {
        if (empty($ids) || empty($news)) return;
        foreach ($news as $k => $v) {
            if (in_array($v['aId'], $ids)) unset($news[$k]);
        }
    }

    public static function rSort($news1, $news2)
    {
        $date1 = strtotime($news1['date']);
        $date2 = strtotime($news2['date']);
        if ($date1 == $date2) return 0;
        elseif ($date1 < $date2) return 1;
        else return 0;
    }

    // =================================

    private function _setVisitor()
    {
        $this->uid = trim(Yii::$app->request->get('uid', ''));
        if (!empty($this->uid)) {
            $this->visitor = Visitor::find()->where(['uid' => $this->uid])->one();
            if (empty($this->visitor)) {
                $visitor = new Visitor();
                $visitor->setAttributes(['uid' => $this->uid]);
                $visitor->save();
                $this->visitor = $visitor;
            }
        }
    }

    //  GEO
    function _getGeoInfo()
    {
        if (null === $this->geoInfo && empty($this->geoCode)) {
            $this->geoInfo = Region::getCodesByNames(Yii::$app->request->get('provinceName'), Yii::$app->request->get('cityName'), Yii::$app->request->get('districtName'));
        }
        return $this->geoInfo;
    }

    function _getGeoCode()
    {
        if (null === $this->geoCode) {
            $geoCode = trim(Yii::$app->request->get('geoCode', ''));

            if (!empty($geoCode)) {
                $geoCode = substr($geoCode, 0, 6);
            }

            if (empty($geoCode) || $geoCode == '000000') {
                $geoInfo = $this->_getGeoInfo();
                if (!empty($geoInfo)) {
                    $code = end($geoInfo);
                    $geoCode = $code['code'];
                }
            }

            $this->geoCode = $geoCode;
        }

        // 如果找不到地理信息，默认返回北京的
        if (empty($this->geoCode)) {
            $this->geoCode = '110000';
        }

        return $this->geoCode;
    }

    //------------ weather ------------
    function _getWeather()
    {
        //获取geo记录
        $yahooInfo = $this->getYahooGeoInfo();
        if (empty($yahooInfo)) return [];

        //获取天气数据
        $res = (new YahooWeather($yahooInfo['yahooGeoCode'], $yahooInfo['name']))->fetch(0);
        if (!empty($res)) {
            $bg = $yahooInfo['bg'];
            if (empty($bg)) {
                if (MyDate::isNight()) {
                    $bg = Yii::$app->params['newsImageWebUrl'] . '/img/weather_bg_2.jpg';
                } else {
                    $bg = Yii::$app->params['newsImageWebUrl'] . '/img/weather_bg_1.jpg';
                }
            } else {
                $platform = isset($this->extraParams['platform']) ? $this->extraParams['platform'] : '';
                $size = ImgSize::big($platform);
                $exp = explode('.', $bg);
                $bg .= '_' . $size . 'x' . $size . '.' . end($exp);
            }
            $res['bg'] = $bg;
        }
        return $res;
    }

    function getYahooGeoInfo()
    {
        $geoInfo = $this->_getGeoInfo();// 通过地区名称获取 geo记录[province=>array(), city=>array, district=>array()]
        if (empty($geoInfo) && !empty($this->geoCode)) {//根据geo code获取geo记录
            $geoInfo = Region::getInfoByCode($this->geoCode);

            if (!empty($geoInfo) && (empty($geoInfo['yahooGeoCode']) || empty($geoInfo['bg']))) { // 如果县一级的没有 yahooGeoCode,则取市一级的
                $code = substr($this->geoCode, 0, 4) . '00';
                if ($code == '500100') $code = '500001'; // 重庆的特殊处理
                if ($code != $this->geoCode) {
                    $res = Region::getInfoByCode($code);

                    if (empty($geoInfo['yahooGeoCode'])) {
                        if (!empty($geoInfo['bg'])) {
                            $bg = $geoInfo['bg'];
                            $geoInfo = $res;
                            $geoInfo['bg'] = $bg;
                        } else {
                            $geoInfo = $res;
                        }
                    } elseif (empty($geoInfo['bg'])) {
                        $geoInfo['bg'] = $res ? $res['bg'] : '';
                    }
                }
            }
        } else if (!empty($geoInfo)) { //
            $districtFound = false;
            $bg = '';
            if (!empty($geoInfo['district'])) {
                if (!empty($geoInfo['district']['bg'])) {
                    $bg = $geoInfo['district']['bg'];
                } elseif (!empty($geoInfo['city']) && !empty($geoInfo['city']['bg'])) {
                    $bg = $geoInfo['city']['bg'];
                }

                if (!empty($geoInfo['district']['yahooGeoCode'])) {
                    $geoInfo = $geoInfo['district'];
                    $geoInfo['bg'] = $bg;
                    $districtFound = true;
                }
            }

            if (!$districtFound) {
                if (!empty($geoInfo['city']) && !empty($geoInfo['city']['yahooGeoCode'])) {
                    $geoInfo = $geoInfo['city'];
                    if ($bg != '') $geoInfo['bg'] = $bg;

                } elseif (!empty($geoInfo['province'])) {
                    $geoInfo = $geoInfo['province'];
                    if ($bg != '') $geoInfo['bg'] = $bg;

                } else {
                    $geoInfo = [];
                }
            }
        }
        return $geoInfo;
    }

    //------------ 新闻 ------------
    function getDistrictNews()
    {
        if (empty($this->geoCode) || '000000' == $this->geoCode) return [];
        if (in_array($this->geoCode, Region::specialCityCodes())) return [];

        if (substr($this->geoCode, 4, 2) == '00') return []; // 最后两位不为00 即为 区县
        return $this->_getNews($this->districtNewsLen, $this->geoCode, Tag::localNewsId());
    }

    function getCityNews()
    {
        if (empty($this->geoCode) || '000000' == $this->geoCode) return [];

        $code = $this->geoCode;
        if (!in_array($code, Region::specialCityCodes())) {
            $code = substr($this->geoCode, 0, 4) . '00';
            if (substr($code, 2, 4) == '0000' && !in_array($code, Region::specialCode())) return []; // 最后四位不为0000 即为 市级
        }

        $cateId = Tag::localNewsId();
        return $this->_getNews($this->cityNewsLen, Region::formatCityCode($code), $cateId, 'city');
    }

    function getProvinceNews()
    {
        if (empty($this->geoCode) || '000000' == $this->geoCode) return [];
        $code = substr($this->geoCode, 0, 2) . '0000';

        if ($code == '000000') return []; // 最后四位不为0000 即为 省

        return $this->_getNews($this->provinceNewsLen, $code, Tag::localNewsId(), 'province');
    }

    function getNewsByCateIds()
    {
        if (empty($this->tagIds)) return [];
        return $this->_getNews($this->cateNewsLen, null, $this->tagIds);
    }

    public function _getNews($limit = 0, $geoCode = null, $cateIds = [], $region = null)
    {
        $memKey = md5('LocalNes._getNews' . $limit . $geoCode . (is_array($cateIds) ? implode(',', $cateIds) : $cateIds) . $region . $this->thumbWidth);

        $cache = Yii::$app->getCache()->get($memKey);
        if (!empty($cache)) {
            if (YII_ENV_PROD) return json_decode($cache, true);
        }

        $days = 15;
        $pubDate = strtotime("-{$days} days");//MyDate::beginLast7days();

        $where = [
            'created_at' => ['$gte' => MyDate::mongoDate($pubDate)],
            'status' => 1,
        ];

        if (!empty($cateIds)) $where['cate_id'] = is_array($cateIds) ? ['$in' => array_unique($cateIds)] : $cateIds;

        if (!empty($geoCode) && '000000' != $geoCode) { //全国的
            if (substr($geoCode, 2, 4) == '0000' && (empty($region) || 'province' == $region)) {
                $where['province_code'] = $geoCode;
                $where['$or'] = [['city_code' => ['$exists' => false]], ['city_code' => '']];

            } elseif (in_array($geoCode, Region::specialCityCodes()) || (substr($geoCode, 4, 2) == '00' && (empty($region) || 'city' == $region))) {
                $where['city_code'] = $geoCode;
                $where['$or'] = [['district_code' => ['$exists' => false]], ['district_code' => ""]];

            } else {
                $where['district_code'] = $geoCode;
            }
        }

        $query = News::find()
            ->select(News::listFields())
            ->where($where)
            ->orderby($this->getNewsOrder())
            ->limit($limit)->offset(0);

        $news = $query->all();

        $res = $this->formatNews($news);

        Yii::$app->getCache()->set($memKey, json_encode($res), $this->cacheTime); // 8分钟

        return $res;
    }

    /**
     * 获取指定Id的资讯
     * @param array $ids
     * @return array
     */
    public function getNewsByIds($ids = [])
    {
        if (empty($ids)) return [];

        $memKey = md5('LocalNews.' . implode(',', $ids));
        $cache = Yii::$app->getCache()->get($memKey);
        if ($cache) {
            return json_decode($cache, true);
        }

        $ids = Helper::str2int($ids);

        $query = News::find()->where(['id' => $ids, 'status' => 1])->limit(count($ids))->offset(0);
        $result = $query->all();

        $res = $this->formatNews($result);

        Yii::$app->getCache()->set($memKey, json_encode($res), 300);

        return $res;
    }

    private function getNewsOrder()
    {
        if ($this->order == 'time') $order = ['created_at' => SORT_ASC];
        else if ($this->order == '-time') $order = (['created_at' => SORT_DESC]);
        else $order = (['id' => SORT_DESC, 'created_at' => SORT_DESC]);
        return $order;
    }

    private function formatNews(&$news)
    {
        $res = [];
        if (empty($news)) return $res;

        foreach ($news as $item) {
            $res[] = $item->asList($this->thumbWidth, $this->extraParams);
        }
        return $res;
    }
}