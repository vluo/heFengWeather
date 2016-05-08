<?php
/**
 *
 * Created at: 12:23 PM 11/26/14
 */

namespace common\models;

use common\base\Helper;
use common\base\MyDate;
use common\helpers\Pinyin;
use yii\helpers\FileHelper;
use Yii;

/**
 * This is the model class for table "geo".
 *
 * @property \MongoId|string $_id
 * @property integer $id
 * @property integer $parent_id
 * @property string $name
 * @property string $pinyin
 * @property string $code 行政代号
 * @property string $zone 区号
 * @property string $yahoo_geo_code
 * @property string $day_pic
 * @property string $night_pic
 * @property \MongoDate $created_at
 * @property \MongoDate $updated_at
 */
class Geo extends \common\base\MongoDbActiveRecord
{

    /**
     * @inheritdoc
     */
    public static function collectionName()
    {
        return 'geo';
    }

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        return [
            '_id',
            'id',
            'parent_id',
            'name',
            'pinyin',
            'code',
            'zone',
            'yahoo_geo_code',
            'day_pic',
            'night_pic',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['parent_id', 'name'], 'required'],
            [['parent_id'], 'integer'],
            [['name', 'pinyin'], 'string', 'max' => 255],
            [['code', 'zone', 'yahoo_geo_code', 'day_pic', 'night_pic'], 'safe']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('model', 'ID'),
            'parent_id' => Yii::t('model', 'Parent ID'),
            'name' => Yii::t('model', 'Name'),
            'pinyin' => Yii::t('model', 'Pinyin'),
            'code' => Yii::t('model', 'Region Code'),
            'zone' => Yii::t('model', 'Region Zone'),
            'yahoo_geo_code' => Yii::t('model', 'Yahoo Geo Code'),
            'day_pic' => Yii::t('model', 'Day Picture'),
            'night_pic' => Yii::t('model', 'Night Picture'),
            'created_at' => Yii::t('model', 'Created At'),
            'updated_at' => Yii::t('model', 'Updated At'),
        ];
    }

    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            $this->parent_id = intval($this->parent_id);

            if ($insert) {
                $this->id = Counter::incId(static::collectionName(), Yii::$app->id);
            }

            return true;
        }
        return false;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        @unlink(self::cacheFile());
    }

    /**
     * 常用的城市，区县
     */
    public static function usual()
    {
        return [];
    }

    public static function cacheFile()
    {
        FileHelper::createDirectory(Yii::getAlias('@api/runtime/cache'));
        return Yii::getAlias('@api/runtime/cache/provinces.json');
    }

    public static function checksum()
    {
        return MyDate::mongoDateToLocal(static::find()->max('updated_at'), null);
    }

    /**
     * @return array
     */
    public static function asTree()
    {
        $cacheFile = self::cacheFile();

        if (is_file($cacheFile)) {
            $content = file_get_contents($cacheFile);
            return json_decode($content, true);
          } 

        $provinces = [];
        $regions = static::find()->all();
        foreach ($regions as $province) {

            if ($province->parent_id == 0) {
                $cities = [];

                foreach ($regions as $city) {
                    if ($city->parent_id != $province->id) continue;
                    $districts = [];

                    foreach ($regions as $dist) {
                        if ($dist->parent_id != $city->id) continue;
                        $districts[] = [
                            'name' => $dist->name,
                        	 'abbreviation'=>self::_pinyin($dist->name),
                            'geoCode' => $dist->code,
                        ];
                    }

                    $cities[] = [
                        'name' => $city->name,
                    		'abbreviation'=>self::_pinyin($city->name),
                        'geoCode' => $city->code,
                        'subArea' => $districts
                    ];
                }

                $provinces[] = [
                    'name' => $province->name,
                	  'abbreviation'=>self::_pinyin($province->name),
                    'geoCode' => $province->code,
                    'subArea' => $cities
                ];
            }
        }

        // write cache
        file_put_contents($cacheFile, json_encode($provinces));

        return $provinces;
    }
    
    private static function _pinyin($name) {
    	//这类地区则获取拼音异常，偏僻字或者多音字， 单独处理
    	$odd = ['重庆'=>'CQ', '重庆市'=>'CQS', '东莞'=>'DG', '东莞市'=>'DGS'];
    	if(!array_key_exists($name, $odd)) {
    		$pinyin = PinYin::getFirst($name);
    	} else {
    		$pinyin = $odd[$name];
    	}
    	return $pinyin;//PinYin::getFirst($province->name);
    }

    /**
     * 输入省市区，返回其名称及行政代号
     * @param $provinceName
     * @param $cityName
     * @param $districtName
     * @return array
     */
    public static function getCodesByNames($provinceName, $cityName, $districtName)
    {
        $memKey = md5('region.getCodesByNames' . $provinceName . $cityName . $districtName);

        $cache = Yii::$app->getCache()->get($memKey);
        if ($cache) {
            $res = json_decode($cache, true);

        } else {
            $res = [];

            $province = null;
            if (!empty($provinceName)) {
                $provinceName = Helper::subStr($provinceName, 0, 2);
                //$province = static::find()->where('parent_id = 0 AND name LIKE :name', [':name' => $provinceName . '%'])->one();
                $province = static::find()->where(['parent_id' => 0, "name" => ['$regex' => $provinceName]])->one();
                if ($province) $res['province'] = $province->toArr();
            }

            $city = null;
            if (!empty($cityName)) {
                $cityName = self::formatRegionName($cityName);
                $query = static::find();
                if ($province) $query->andWhere(['parent_id' => $province->id]);
                //$query->andWhere('name LIKE :name', [':name' => $cityName . '%']);
                $query->andWhere(["name" => ['$regex' => $cityName]]);
                $city = $query->one();
                if ($city) $res['city'] = $city->toArr();
            }

            if (!empty($provinceName) && empty($city)) {

            } else if (!empty($districtName)) {
                $districtName = self::formatRegionName($districtName);
                $query = static::find();
                if ($city) $query->andWhere(['parent_id' => $city->id]);
                //$query->andWhere('name LIKE :name', [':name' => $districtName . '%']);
                $query->andWhere(['name' => ['$regex' => $districtName]]);
                $district = $query->one();
                if ($district) $res['district'] = $district->toArr();

            }

            Yii::$app->getCache()->set($memKey, json_encode($res), 86400);
        }

        // 添加背景图
        if (!empty($res)) {
            foreach ($res as $k => $val)
                $res[$k] = self::_formatBg($val);
        }
        return $res;
    }

    /**
     * 获取 某个code的详情
     * @param $code
     * @return array
     */
    public static function getInfoByCode($code)
    {
        $code = static::formatCityCode($code);

        $memKey = 'Region.getInfoByCode.' . $code;

        $cache = Yii::$app->getCache()->get($memKey);
        if ($cache) {
            $res = json_decode($cache, true);

        } else {
            $res = [];

            $region = static::find()->where(['code' => $code])->orderBy(['id' => SORT_DESC])->one();
            if ($region) $res = $region->toArr();

            Yii::$app->getCache()->set($memKey, json_encode($res), 86400 * 7);
        }

        if (!empty($res)) $res = self::_formatBg($res);

        return $res;
    }

    public static function getNameByCode($code)
    {
        $info = self::getInfoByCode($code);
        return empty($info) ? '' : $info['name'];
    }

    /**
     * @param array $info
     * @return array
     */
    private static function _formatBg($info)
    {
        if (is_array($info) && (isset($info['dayPic']) || isset($info['nightPic']))) {
            if (MyDate::isNight()) {
                $info['bg'] = $info['nightPic'];
            }
            if (empty($info['bg'])) $info['bg'] = $info['dayPic'];
            unset($info['dayPic']);
            unset($info['nightPic']);
        }
        return $info;
    }

    /**
     * 为了查找兼容，需要简化一个名称
     * @param $name
     * @return mixed
     */
    public static function formatRegionName($name)
    {
        // 一些简称
        $short = [
            '达茂旗' => '达尔罕茂明安联合旗'
        ];

        if (isset($short[$name])) $name = $short[$name];

        $len = Helper::strlen($name);
        if ($len >= 5) {
            $suffix = Helper::subStr($name, $len - 3, 3);
            if (in_array($suffix, ['自治区', '自治州', '自治县', '自治旗', '联合旗', '直辖市'])) {
                $name = Helper::subStr($name, 0, -3);
            }

        } else if ($len > 2) {
            $suffix = Helper::subStr($name, $len - 1, 1);
            if (in_array($suffix, ['省', '市', '区', '县', '旗'])) {
                $name = Helper::subStr($name, 0, -1);
            }
        }
        return $name;
    }

    /**
     * 省、市一样的code
     */
    public static function specialCode()
    {
        return [
            '110000', // 北京
            '120000', // 天津
            '310000', // 上海
        ];
    }

    /**
     * 市级不以00结尾的代号
     * @return array
     */
    public static function specialCityCodes()
    {
        return [
            "419001", "429004", "429005", "429006", "429021", "469001", "469002", "469003", "469005", "469006",
            "469007", "469021", "469022", "469023", "469024", "469025", "469026", "469027", "469028", "469029",
            "469030", "500001", "719001", "719002", "719003", "719004", "719005", "719006", "719007", "719008",
            "719009", "719010", "719011", "719012", "719013", "719014", "719015", "719016"
        ];
    }

    // 北京, 天津, 上海，省份与城市的code是一样的
    public static function formatCityCode($code)
    {
        if (in_array($code, static::specialCityCodes($code))) return $code;
        if (substr($code, 4, 2) == '00') {
            $tmpCode = substr($code, 0, 2) . '0000';
            if (in_array($tmpCode, static::specialCode())) $code = $tmpCode;
        }
        return $code;
    }

    public function dayPicUrl()
    {
        if (empty($this->day_pic)) return '';
        return str_replace('@ASSETS_HOST@', Yii::$app->params['newsImageWebUrl'], $this->day_pic);
    }

    public function nightPicUrl()
    {
        if (empty($this->night_pic)) return '';
        return str_replace('@ASSETS_HOST@', Yii::$app->params['newsImageWebUrl'], $this->night_pic);
    }

    /**
     * @return array
     */
    public function toArr()
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'yahooGeoCode' => empty($this->yahoo_geo_code) ? '' : $this->yahoo_geo_code,
            'dayPic' => $this->dayPicUrl(),
            'nightPic' => $this->nightPicUrl()
        ];
    }
} 