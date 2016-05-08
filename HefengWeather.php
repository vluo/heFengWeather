<?php
/**
 * 和风天气
 * Created at: 4:14 PM 5/28/14
 */

/*namespace api\models;

use console\spiders\Curl;
use yii\base\Object;
use yii\helpers\FileHelper;
use Yii;*/

class HefengWeather //extends Object
{
    private static $_appKey = '594058588d1e4a32b773e032a362486d';
    private static $_requestURL = 'https://api.heweather.com/x3/weather';
    private static $_cacheDir = 'log/';
    private static $_geoDataFile = 'geoData.json';

    /*
     * 根据地区数据获取天气现象
     * @param $p  省
     * @param $c  市
     * @param $d  县
     * */
    public static function _fetchWeather($province, $city, $district) {
        $geoData = self::_geoData();

        $geoName = '';
        if(isset($geoData[$province][$city][$district])) {
            $geo = $geoData[$province][$city][$district];
            $geoName = $district;
        } elseif(isset($geoData['cities'][$city])) {
            $geo = $geoData['cities'][$city];
            $geoName = $city;
        }
        if(isset($geo)) { //var_dump($geo);die;
            $code = $geo['code'];
        }

        if(!empty($code)) {
            $data = self::_weatherFromCache($code);
            if(empty($data)) {
                $data = self::_weatherFromAPI($code);
                $data['current']['city'] = $geoName;
            }
            return $data;
        } else {
            return null;
        }
    }

    //
    public static function regName2Id($name) {

    }

    public static function regCode2Id($geoCode) {

    }


    private static function _utf8Coding($str) {
        return mb_convert_encoding($str,  'UTF-8', 'GBK');
    }

    private static function _weatherFromAPI($code) {
        $ch = curl_init();
        $url = self::$_requestURL . "?cityid={$code}&key=" . self::$_appKey;//=XXXXXXXXXXXXX';

        // 执行HTTP请求
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $res = curl_exec($ch);
        $json = json_decode($res, true);
        $weatherInfo = isset($json["HeWeather data service 3.0"]) ? $json["HeWeather data service 3.0"][0] : $json;

        $forecast = $current = array();
        if ($weatherInfo['daily_forecast']) {
            $weatherInfo['daily_forecast'] = $weatherInfo['daily_forecast'][0];
            $weatherInfo['hourly_forecast'] = $weatherInfo['hourly_forecast'][0];

            $current['sunrise'] = $weatherInfo['daily_forecast']['astro']['sr'];
            $current['sunSet'] = $weatherInfo['daily_forecast']['astro']['ss'];

            $current['windSpeed'] = $weatherInfo['hourly_forecast']['wind']['spd'];
            $current['windDirection'] = $weatherInfo['hourly_forecast']['wind']['dir'];
            if (date('H') < 18) {
                $current['code'] = $weatherInfo['daily_forecast']['cond']['code_d'];
                $current['txt'] = $weatherInfo['daily_forecast']['cond']['txt_d'];
            } else {
                $current['code'] = $weatherInfo['daily_forecast']['cond']['code_n'];
                $current['txt'] = $weatherInfo['daily_forecast']['cond']['txt_n'];
            }
            $current['name'] = $current['txt'];
            $current['temperature'] = $weatherInfo['hourly_forecast']['tmp'];
            $current['date'] = $weatherInfo['daily_forecast']['date'];
            $current['day'] = date('D');
            $current['humidity'] = $weatherInfo['hourly_forecast']['hum'];
            $current['visibility'] = $weatherInfo['daily_forecast']['vis'];

            $forecast['low'] = $weatherInfo['daily_forecast']['tmp']['min'];
            $forecast['high'] = $weatherInfo['daily_forecast']['tmp']['max'];
            $forecast['day'] = date('D');
            $forecast['date'] = $weatherInfo['hourly_forecast']['date'];
            $forecast['code'] = $current['code'];
            $forecast['text'] = $current['txt'];
            $forecast['name'] = $current['txt'];
        }

        $temperature = array('current'=>$current, 'forecast'=>array($forecast), 'pubDate'=>date('Y-m-d H:i:s'), 'expireAt'=>time()+3600*8);
        $data = json_encode($temperature);
        file_put_contents(self::_cacheFile($code), $data);

        return $temperature;
    }

    private static function _weatherFromCache($code) {
        $cacheFile = self::_cacheDir().'/'.$code.'.json';
        $data = null; //from cache
        $curTime = time();
        if(file_exists($cacheFile)) {
            $string = file_get_contents($cacheFile);
            $data = json_decode($string, true);
            if(isset($data['expireAt']) && $data['expireAt']<$curTime) {
                $data = null;
            }
        }
        return $data;
    }

    private static function _cacheFile($code) {
        return self::_cacheDir().'/'.$code.'.json';
    }

    private static function _cacheDir() {
        $path = __DIR__.'/'.self::$_cacheDir;
        if(!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }

    private static function _geoFile() {
        return self::_cacheDir().'/'.self::$_geoDataFile;
    }

    /*
     * 获取地区缓存
     * */
    private static function _geoData() {
        static $data;
        if(!$data) {
            $fileName = self::_geoFile();
            if(file_exists($fileName)) {
                $data = json_decode(file_get_contents($fileName), true);
            } else {
                return self::_initGeoData();
            }
        }
        return $data;
    }

    /*
     * 初始化地区数据缓存
     * */
    private static function _initGeoData() {
        $file = __DIR__.'/thinkpage_cities.csv';
        $string = file_get_contents($file);
        $rows = explode("\r\n", $string);
        $data = array();
        foreach($rows as $row) {
            if(empty($row)) continue;
            $columns = explode(',', $row);
            $district = self::_utf8Coding($columns[2]);
            $city = self::_utf8Coding($columns[3]);
            $province = self::_utf8Coding($columns[4]);
            $data[$province][$city][$district] = array('piny'=>$columns[1], 'code'=>$columns[0]);
            if(!isset($data['cities'][$city])) {
                $data['cities'][$city] = array('piny'=>$columns[1], 'code'=>$columns[0]);
            }
        }

        $content = json_encode($data);
        file_put_contents(self::_geoFile(), $content);
        return $data;
    }

} 