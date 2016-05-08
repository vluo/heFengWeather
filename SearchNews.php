<?php
/**
 *
 * Created at: 10:03 AM 2/4/15
 */

namespace api\models;

use common\base\Helper;
use common\base\ImgSize;
use common\base\MyDate;
use common\models\News;
use Yii;

class SearchNews
{

    static function search($extraParams = [])
    {
        $thumbWidth = Helper::parseInt(Yii::$app->request->get('thumbWidth'), 0);
        $limit = Helper::parseInt(Yii::$app->request->get('limit'), 30);
        $page = Helper::parseInt(Yii::$app->request->get('page'), 1);
        $tsAId = Helper::parseInt(Yii::$app->request->get('tsAId'), 0);
        $q = rawurldecode(Yii::$app->request->get('q'));
        $filter = Yii::$app->request->get('filter', 'title');

        $platform = isset($extraParams['platform']) ? $extraParams['platform'] : '';
        $thumbWidth = ImgSize::thumb($platform, $thumbWidth);
        if ($page < 1) $page = 1;
        if ($limit > 100) $limit = 100;
        $days = '7';

        $memKey = md5('api.news.search' . $limit . $thumbWidth . $days . $tsAId . $page . $filter . $q);
        $res = Yii::$app->getCache()->get($memKey);
        if ($res) {
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


        $where = [
            'created_at' => ['$gte' => MyDate::mongoDate(strtotime('-' . $days . ' days'))],
            'status' => 1,
            'is_topic' => 0
        ];

        if (!empty($q)) {
            if ($filter == 'keyword') {
                $where['tags'] = $q;
            } else {
                $where['title'] = ['$regex' => $q];
            }
        }

        $query = News::find()
            ->select(News::listFields())
            ->where($where);

        $total = $query->count();

        $query->limit($limit)
            ->offset(($page - 1) * $limit)
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);

        $results = $query->all();


        $data = [];
        $topLists = [];
        foreach ($results as $news) {
            $tmp = $news->asList($thumbWidth, $extraParams);
            if (empty($tmp['aId'])) continue;

            $data[] = $tmp;

            if ($page == 1 && $tmp['aId'] > $tsAId) {
                $tsAId = $tmp['aId'];
            }
        }

        $nextUrl = "";
        if (count($data) > 0 && $page * $limit < $total) {
            // nextUrl
            $nextUrlParams = [
                'limit' => $limit,
                'page' => $page + 1,
                'thumbWidth' => $thumbWidth,
                'tsAId' => $tsAId,
                'q' => rawurlencode($q),
                'filter' => $filter
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

    public static function nextUrlSuffix()
    {
        return implode('/', [
            Yii::$app->params['apiHost'],
            Yii::$app->controller->module->id,
            Yii::$app->request->get('_app_id'),
            Yii::$app->request->get('_client_mode'),
            Yii::$app->controller->id,
            //Yii::$app->controller->action->id
        ]);
    }
} 