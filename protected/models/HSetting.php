<?php

/**
 * HumHub
 * Copyright © 2014 The HumHub Project
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 */

/**
 * This is the model class for table "settings" and is responsible for system
 * wide settings of modules.
 *
 * Only use this for settings and not for general proposes.
 *
 * The registry is used to store systemwide settings.
 * Also modules can use this to store e.g. configuration options.
 *
 * The followings are the available columns in table 'registry':
 *
 * @property int $id
 * @property string $name
 * @property string $value
 * @property string $value_text
 * @property string $module_id
 *
 * @package humhub.models
 * @since 0.5
 */
class HSetting extends HActiveRecord {

    /**
     * Returns the static model of the specified AR class.
     * @param string $className active record class name.
     * @return HSetting the static model class
     */
    public static function model($className = __CLASS__) {
        return parent::model($className);
    }

    /**
     * @return string the associated database table name
     */
    public function tableName() {
        return 'setting';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules() {
        // NOTE: you should only define rules for those attributes that
        // will receive user inputs.
        return array(
            array('name', 'required'),
            array('value', 'length', 'max' => 100),
            array('value_text', 'safe'),
            array('name, module_id', 'length', 'max' => 100),
        );
    }

    /**
     * Returns a registry record by Name and Module Id
     * The result is cached.
     *
     * @param type $name
     * @param type $moduleId
     * @return \HSetting
     */
    private static function GetRecord($name, $moduleId = "") {

        $cacheId = 'HSetting_' . $name . '_' . $moduleId;

        // Check if stored in Runtime Cache
        if (RuntimeCache::Get($cacheId) !== false) {
            return RuntimeCache::Get($cacheId);
        }

        // Check if stored in Cache
        $cacheValue = Yii::app()->cache->get($cacheId);
        if ($cacheValue !== false) {
            return $cacheValue;
        }

        $condition = "";
        $params = array('name' => $name);

        if ($moduleId != "") {
            $params['module_id'] = $moduleId;
        } else {
            $condition = "module_id IS NULL";
        }

        $record = HSetting::model()->findByAttributes($params, $condition);

        if ($record == null) {
            $record = new HSetting;
        } else {
            $expireTime = 3600;
            if ($record->name != 'expireTime' && $record->module_id != "cache")
                $expireTime = HSetting::Get('expireTime', 'cache');

            Yii::app()->cache->set($cacheId, $record, $expireTime);
            RuntimeCache::Set($cacheId, $record);
        }

        return $record;
    }

    /**
     * Returns a standard registry entry (max. 255 characters) from database
     *
     * @param type $name
     * @param type $moduleId
     * @return type
     */
    public static function Get($name, $moduleId = "") {

        $record = self::GetRecord($name, $moduleId);
        return $record->value;
    }

    /**
     * Returns a rext entry from the registry table
     *
     * @param type $name
     * @param type $moduleId
     * @return type
     */
    public static function GetText($name, $moduleId = "") {

        $record = self::GetRecord($name, $moduleId);
        return $record->value_text;
    }

    /**
     * Sets a standard Text (max. 255 Characters) entry to the registry
     *
     * @param type $name
     * @param type $value
     * @param type $moduleId
     */
    public static function Set($name, $value, $moduleId = "") {
        $record = self::GetRecord($name, $moduleId);

        $record->name = $name;
        $record->value = $value;
        if ($moduleId != "")
            $record->module_id = $moduleId;

        if ($value == "") {
            if (!$record->isNewRecord)
                $record->delete();
        } else {
            $record->save();
        }
    }

    /**
     * Sets a Text (more than 255 Characters) into the HSetting
     *
     * @param type $name
     * @param type $value
     * @param type $moduleId
     */
    public static function SetText($name, $value, $moduleId = "") {
        $record = self::GetRecord($name, $moduleId);

        $record->name = $name;
        $record->value_text = $value;
        if ($moduleId != "")
            $record->module_id = $moduleId;

        $record->save();
    }

    /**
     * Returns the Cache ID for this HSetting Entry
     *
     * @return String
     */
    public function getCacheId() {
        return "HSetting_" . $this->name . "_" . $this->module_id;
    }

    /**
     * Saving the registry object
     * Also deletes cache Entry
     *
     * @param type $runValidation
     * @param type $attributes
     * @return type
     */
    public function save($runValidation = true, $attributes = null) {

        Yii::app()->cache->delete($this->getCacheId());
        RuntimeCache::Remove($this->getCacheId());
        return parent::save($runValidation, $attributes);
    }

    /**
     * After delete check if its required to rewrite configuration file
     */
    public function afterDelete() {

        $cacheId = $this->getCacheId();
        Yii::app()->cache->delete($cacheId);
        RuntimeCache::Remove($cacheId);

        parent::afterDelete();

        // Only rewrite static configuration file when necessary
        if ($this->module_id != 'mailing' &&
                $this->module_id != 'cache' &&
                $this->name != 'name' &&
                $this->name != 'theme'
        ) {
            return;
        }

        self::rewriteConfiguration();
    }

    /**
     * After saving check if its required to rewrite the configuration file.
     */
    public function afterSave() {

        parent::afterSave();

        // Only rewrite static configuration file when necessary
        if ($this->module_id != 'mailing' &&
                $this->module_id != 'cache' &&
                $this->name != 'name' &&
                $this->name != 'theme'
        ) {
            return;
        }

        self::rewriteConfiguration();
    }

    /**
     * Rewrites the configuration file
     */
    public static function rewriteConfiguration() {

        // Get Current Configuration
        $config = HSetting::getConfiguration();

        // Add Application Name to Configuration
        $config['name'] = HSetting::Get('name');

        // Add Caching
        $cacheClass = HSetting::Get('type', 'cache');
        if (!$cacheClass) {
            $cacheClass = "CDummyCache";
        }
        $config['components']['cache'] = array(
            'class' => $cacheClass,
        );

        // Install Mail Component
        $mail = array(
            'class' => 'ext.yii-mail.YiiMail',
            'transportType' => HSetting::Get('transportType', 'mailing'),
            'viewPath' => 'application.views.mail',
            'logging' => true,
            'dryRun' => false,
        );
        if (HSetting::Get('transportType', 'mailing') == 'smtp') {

            $mail['transportOptions'] = array();

            if (HSetting::Get('hostname', 'mailing'))
                $mail['transportOptions']['host'] = HSetting::Get('hostname', 'mailing');

            if (HSetting::Get('username', 'mailing'))
                $mail['transportOptions']['username'] = HSetting::Get('username', 'mailing');

            if (HSetting::Get('password', 'mailing'))
                $mail['transportOptions']['password'] = HSetting::Get('password', 'mailing');

            if (HSetting::Get('encryption', 'mailing'))
                $mail['transportOptions']['encryption'] = HSetting::Get('encryption', 'mailing');

            if (HSetting::Get('port', 'mailing'))
                $mail['transportOptions']['port'] = HSetting::Get('port', 'mailing');
        }
        $config['components']['mail'] = $mail;

        // Add Theme
        $theme = HSetting::Get('theme');
        if ($theme && $theme != "") {
            $config['theme'] = $theme;
        } else {
            unset($config['theme']);
        }

        HSetting::setConfiguration($config);
    }

    /**
     * Returns the configuration file (_settings.php) as Array
     *
     * @return Array Configuration file
     */
    public static function getConfiguration() {

        $configFile = Yii::app()->basePath . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . '_settings.php';
        $config = require($configFile);

        if (!is_array($config))
            return array();

        return $config;
    }

    /**
     * Writes a new configuration file array
     *
     * @param type $config
     */
    public static function setConfiguration($config = array()) {

        $configFile = Yii::app()->basePath . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . '_settings.php';

        $content = "<" . "?php return ";
        $content .= var_export($config, true);
        $content .= "; ?" . ">";

        file_put_contents($configFile, $content);
    }

}