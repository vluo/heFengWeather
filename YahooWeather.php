<?php
/**
 * Yahoo 天气
 * Created at: 4:14 PM 5/28/14
 */

namespace api\models;

use console\spiders\Curl;
use yii\base\Object;
use yii\helpers\FileHelper;
use Yii;

class YahooWeather extends Object
{
    private $_cityId;
    private $_cityName;
    private $_cachedFile;
    private $_rssFeed;

    /**
     * @param string $cityId
     * @param string $cityName
     * @param array $config
     */
    function __construct($cityId, $cityName, $config = [])
    {
        parent::__construct($config);
        if (!empty($cityId)) {

            $this->_cityId = $cityId;
            $this->_cityName = $cityName;

            $weatherCachedPath = Yii::getAlias('@api/runtime/cache/weather');
            FileHelper::createDirectory($weatherCachedPath, 0775, true);

            $this->_cachedFile = $weatherCachedPath . '/' . $this->_cityId . '.json';
            $this->_rssFeed = 'http://weather.yahooapis.com/forecastrss?u=c&w=' . $this->_cityId; // Yahoo天气
        }
    }

    /**
     * 将Yahoo 风向度数转为文字
     * @param integer $degree
     * @return string
     */
    private function _getDirection($degree = 0)
    {
        if ($degree > 337 && $degree <= 360) {
            return '北风';
        } elseif ($degree >= 0 && $degree <= 22) {
            return '北风';
        } elseif ($degree > 22 && $degree <= 67) {
            return '东北风';
        } elseif ($degree > 67 && $degree <= 112) {
            return '东风';
        } elseif ($degree > 112 && $degree <= 157) {
            return '东南风';
        } elseif ($degree > 157 && $degree <= 202) {
            return '南风';
        } elseif ($degree > 202 && $degree <= 247) {
            return '西南风';
        } elseif ($degree > 247 && $degree <= 292) {
            return '西风';
        } elseif ($degree > 292 && $degree <= 337) {
            return '西北风';
        } else {
            return '';
        }
    }

    /**
     * Yahoo weather RSS 抓取天气
     * @return array
     */
    private function _fetch()
    {
        $resData = ['expireAt' => time() + 60];

        $result = null;
        $res = Curl::get($this->_rssFeed, [], 3);
        if ($res && !empty($res['text'])) {
            $result = $res['text'];
        }
        if (empty($result)) return $resData; // 60秒

        $current = [];
        $current['city'] = $this->_cityName;

        $result = preg_replace('/<yweather:([^>]*)/', '<$1', $result);
        try {
            $xml = new \SimpleXMLElement($result);

            $astronomy = $xml->channel->astronomy->attributes();

            $current['sunrise'] = date('H:i', strtotime((string)$astronomy->sunrise));
            $current['sunset'] = date('H:i', strtotime((string)$astronomy->sunset));

            $wine = $xml->channel->wind->attributes();
            $current['windSpeed'] = (float)$wine->speed; // km/h
            $current['windDirection'] = $this->_getDirection((integer)$wine->direction);

            $condition = $xml->channel->item->condition->attributes();
            $current['code'] = (integer)$condition->code;
            $current['text'] = (string)$condition->text;
            $current['name'] = Yii::t('model/weather', (string)$condition->code);
            $current['temperature'] = (float)$condition->temp; // 温度 ℃

            $datetime = \DateTime::createFromFormat('D, d M Y g:i a T', $condition->date);
            $current['date'] = date('Y-m-d H:i:s O', strtotime($datetime->format('Y-m-d')));
            $current['day'] = Yii::t('app', (string)$datetime->format('D'));

            $current['humidity'] = (integer)$xml->channel->atmosphere->attributes()->humidity; // 湿度 %
            $current['visibility'] = (float)$xml->channel->atmosphere->attributes()->visibility; // 可见度 km
            if ($current['visibility'] == 0) $current['visibility'] = '-';

            $forecast = [];
            foreach ($xml->channel->item->forecast as $obj) {
                $attr = $obj->attributes();
                $forecast[] = [
                    'low' => (integer)$attr->low,
                    'high' => (integer)$attr->high,
                    'day' => Yii::t('app', (string)$attr->day),
                    'date' => date('Y-m-d H:i:s O', strtotime($attr->date)),
                    'code' => (integer)$attr->code,
                    'text' => (string)$attr->text,
                    'name' => Yii::t('model/weather', (string)$attr->code)
                ];
            }

            $datetime = \DateTime::createFromFormat('D, d M Y g:i a T', $xml->channel->item->pubDate);
            $pubDate = $datetime->format('Y-m-d H:i:s');

            $expireAt = time() + intval($xml->channel->ttl) * 60;

            $resData = ['current' => $current, 'forecast' => $forecast, 'pubDate' => $pubDate, 'expireAt' => $expireAt];

        } catch (\Exception $e) {

        }

        return $resData;
    }

    function fetch($retry = 3)
    {
        if (empty($this->_rssFeed)) return [];

        $data = $this->fetchFromCache($retry);
        if ($retry == 0) return $data;

        if (empty($data)) {
            $data = $this->_fetch();
            file_put_contents($this->_cachedFile, json_encode($data)); // write cache
        }

        return count($data) == 1 ? [] : $data;
    }

    function fetchFromCache($retry = 3)
    {
        $data = [];

        if (is_file($this->_cachedFile) && !YII_ENV_TEST) { // read cache
            $cachedArr = json_decode(file_get_contents($this->_cachedFile), true);
            if ($retry == 0 || time() < $cachedArr['expireAt']) {
                $data = $cachedArr;
            }
        }

        return count($data) == 1 ? [] : $data;
    }
} 