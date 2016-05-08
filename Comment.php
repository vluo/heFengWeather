<?php
/**
 * Created by PhpStorm.
 * User: elvin
 * Date: 1/6/15
 * Time: 00:08
 */

namespace api\models;

use common\base\Helper;
use common\base\MyDate;
use Yii;

class Comment
{
    static function lists($newsId, $extraParams = [])
    {
        $limit = Helper::parseInt(Yii::$app->request->get('limit'), 20);
        $ts = Helper::parseInt(Yii::$app->request->get('ts'), 0);
        $page = Helper::parseInt(Yii::$app->request->get('page'), 1);

        if ($page < 1) $page = 1;
        if ($limit > 100) $limit = 100;

        $where = ['news_id' => $newsId, 'status' => 1];
        if ($ts > 0) {
            $where['created_at'] = ['$lte' => MyDate::mongoDate($ts)];
        }

        $query = \common\models\Comment::find()
            ->where($where);

        $total = $query->count();

        $query = $query->limit($limit)->offset(($page - 1) * $limit)->orderBy(['created_at' => SORT_DESC]);

        $results = $query->all();

        $comments = [];
        $nextUrl = "";

        $ts = 0;
        foreach ($results as $r) {
            $comments[] = $r->asJson();
            $tmpTs = MyDate::mongoDateToLocal($r->created_at, null);
            if ($tmpTs > $ts) {
                $ts = $tmpTs;
            }
        }

        if (count($comments) > 0 && $page * $limit < $total) {
            // nextUrl
            $nextUrlParams = [
                'limit' => $limit,
                'page' => $page + 1,
                'ts' => $ts
            ];

            $nextUrl = static::nextUrlSuffix($newsId) . '?' . http_build_query($nextUrlParams);
        }

        return ['lists' => $comments, 'nextUrl' => $nextUrl, 'total' => $total, 'limit' => $limit, 'currentPage' => $page];
    }

    public static function nextUrlSuffix($newsId)
    {
        return implode('/', [
            Yii::$app->params['apiHost'],
            Yii::$app->controller->module->id,
            Yii::$app->request->get('_app_id'),
            Yii::$app->request->get('_client_mode'),
            'news',
            $newsId,
            Yii::$app->controller->id
        ]);
    }

} 