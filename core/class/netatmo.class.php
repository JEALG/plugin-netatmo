<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class netatmo extends eqLogic {
  /*     * *************************Attributs****************************** */
  
  /*     * ***********************Methode static*************************** */
  
  public static function cron15(){
    sleep(rand(0,120));
    try {
      self::refresh_weather();
    } catch (\Exception $e) {
      
    }
    try {
      self::refresh_security();
    } catch (\Exception $e) {
      
    }
  }
  
  public static function request($_path,$_data = null,$_type='GET'){
    $url = config::byKey('service::cloud::url').'/service/netatmo';
    $url .='?path='.urlencode($_path);
    if($_data !== null && $_type == 'GET'){
      $url .='&options='.urlencode(json_encode($_data));
    }
    $request_http = new com_http($url);
    $request_http->setHeader(array(
      'Autorization: '.sha512(mb_strtolower(config::byKey('market::username')).':'.config::byKey('market::password'))
    ));
    $datas = json_decode($request_http->exec(30,1),true);
    if(isset($datas['state']) && $datas['state'] != 'ok'){
      throw new \Exception(__('Erreur sur la récuperation des données : ',__FILE__).json_encode($datas));
    }
    return json_decode($datas,true);
  }
  
  public static function getGConfig($_mode,$_key){
    $keys = explode('::',$_key);
    $return = json_decode(file_get_contents(__DIR__.'/../config/'.$_mode.'.json'),true);
    foreach ($keys as $key) {
      if(!isset($return[$key])){
        return '';
      }
      $return = $return[$key];
    }
    return $return;
  }
  
  public static function sync(){
    $weather = self::request('/getstationsdata');
    if(isset($weather['body']['devices']) &&  count($weather['body']['devices']) > 0){
      foreach ($weather['body']['devices'] as $device) {
        $eqLogic = eqLogic::byLogicalId($device['_id'], 'netatmo');
        if (isset($device['read_only']) && $device['read_only'] === true) {
          continue;
        }
        if (!is_object($eqLogic)) {
          $eqLogic = new netatmo();
          $eqLogic->setIsVisible(1);
          $eqLogic->setIsEnable(1);
          $eqLogic->setName($device['station_name']);
          $eqLogic->setCategory('heating', 1);
        }
        $eqLogic->setConfiguration('mode','weather');
        $eqLogic->setEqType_name('netatmo');
        $eqLogic->setLogicalId($device['_id']);
        $eqLogic->setConfiguration('type', $device['type']);
        $eqLogic->save();
        if(isset($device['modules']) &&  count($device['modules']) > 0){
          foreach ($device['modules'] as $module) {
            $eqLogic = eqLogic::byLogicalId($module['_id'], 'netatmo');
            if (!is_object($eqLogic)) {
              $eqLogic = new netatmo();
              $eqLogic->setName($module['module_name']);
              $eqLogic->setIsEnable(1);
              $eqLogic->setCategory('heating', 1);
              $eqLogic->setIsVisible(1);
            }
            $eqLogic->setConfiguration('mode','weather');
            $eqLogic->setConfiguration('battery_type', self::getGConfig('weather',$module['type'].'::bat_type'));
            $eqLogic->setEqType_name('netatmo');
            $eqLogic->setLogicalId($module['_id']);
            $eqLogic->setConfiguration('type', $module['type']);
            $eqLogic->save();
          }
        }
      }
      self::refresh_weather($weather);
    }
    
    $security = self::request('/gethomedata');
    if(isset($security['body']['homes']) &&  count($security['body']['homes']) > 0){
      foreach ($security['body']['homes'] as &$home) {
        $eqLogic = eqLogic::byLogicalId($home['id'], 'netatmo');
        if(!isset($home['name']) || trim($home['name']) == ''){
          $home['name'] = $home['id'];
        }
        if (!is_object($eqLogic)) {
          $eqLogic = new netatmo();
          $eqLogic->setEqType_name('netatmo');
          $eqLogic->setIsEnable(1);
          $eqLogic->setName($home['name']);
          $eqLogic->setCategory('security', 1);
          $eqLogic->setIsVisible(1);
        }
        $eqLogic->setConfiguration('type', 'NAHome');
        $eqLogic->setLogicalId($home['id']);
        $eqLogic->setConfiguration('mode','security');
        $eqLogic->save();
        foreach ($home['persons'] as $person) {
          if (!isset($person['pseudo']) || $person['pseudo'] == '') {
            continue;
          }
          $cmd = $eqLogic->getCmd('info', 'isHere' . $person['id']);
          if (!is_object($cmd)) {
            $cmd = new netatmoCmd();
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setLogicalId('isHere' . $person['id']);
            $cmd->setType('info');
            $cmd->setSubType('binary');
            $cmd->setName(substr(__('Présence', __FILE__) . ' ' . $person['pseudo'].' - '.$person['id'],0,44));
            $cmd->save();
          }
          $cmd = $eqLogic->getCmd('info', 'lastSeen' . $person['id']);
          if (!is_object($cmd)) {
            $cmd = new netatmoCmd();
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setLogicalId('lastSeen' . $person['id']);
            $cmd->setType('info');
            $cmd->setSubType('string');
            $cmd->setName(substr(__('Derniere fois', __FILE__) . ' ' . $person['pseudo'].' - '.$person['id'],0,44));
            $cmd->save();
          }
        }
        foreach ($home['cameras'] as &$camera) {
          $eqLogic = eqLogic::byLogicalId($camera['id'], 'netatmo');
          if(!isset($camera['name']) || trim($camera['name']) == ''){
            $camera['name'] = $camera['id'];
          }
          if (!is_object($eqLogic)) {
            $eqLogic = new netatmo();
            $eqLogic->setEqType_name('netatmo');
            $eqLogic->setIsEnable(1);
            $eqLogic->setName($camera['name']);
            $eqLogic->setCategory('security', 1);
            $eqLogic->setIsVisible(1);
          }
          $eqLogic->setConfiguration('mode','security');
          $eqLogic->setConfiguration('type', $camera['type']);
          $eqLogic->setLogicalId($camera['id']);
          $eqLogic->save();
          if(isset($camera['modules'])){
            foreach ($camera['modules'] as &$module) {
              $eqLogic = eqLogic::byLogicalId($module['id'], 'netatmo');
              if(!isset($module['name']) || trim($module['name']) == ''){
                $module['name'] = $module['id'];
              }
              if (!is_object($eqLogic)) {
                $eqLogic = new netatmo();
                $eqLogic->setEqType_name('netatmo');
                $eqLogic->setIsEnable(1);
                $eqLogic->setName($module['name']);
                $eqLogic->setCategory('security', 1);
                $eqLogic->setIsVisible(1);
              }
              $eqLogic->setConfiguration('mode','security');
              $eqLogic->setConfiguration('type', $module['type']);
              $eqLogic->setLogicalId($module['id']);
              $eqLogic->save();
              
            }
          }
        }
        foreach ($home['smokedetectors'] as &$smokedetectors) {
          $eqLogic = eqLogic::byLogicalId($smokedetectors['id'], 'netatmo');
          if(!isset($smokedetectors['name']) || trim($smokedetectors['name']) == ''){
            $smokedetectors['name'] = $smokedetectors['id'];
          }
          if (!is_object($eqLogic)) {
            $eqLogic = new netatmo();
            $eqLogic->setEqType_name('netatmo');
            $eqLogic->setIsEnable(1);
            $eqLogic->setName($smokedetectors['name']);
            $eqLogic->setCategory('security', 1);
            $eqLogic->setIsVisible(1);
          }
          $eqLogic->setConfiguration('mode','security');
          $eqLogic->setConfiguration('type', $smokedetectors['type']);
          $eqLogic->setLogicalId($smokedetectors['id']);
          $eqLogic->save();
        }
      }
      self::refresh_security($security);
    }
  }
  
  
  public static function createCamera($_datas = null) {
    if(!class_exists('camera')){
      return;
    }
    if($_datas == null){
      $security = self::request('/gethomedata');
    }else{
      $security = $data;
    }
    foreach ($security['homes'] as $home) {
      foreach ($home['cameras'] as $camera) {
        $eqLogic = eqLogic::byLogicalId($camera['id'], 'netatmo');
        if(!is_object($eqLogic)){
          continue;
        }
        log::add('netatmo','debug',json_encode($camera));
        $url_parse = parse_url($eqLogic->getCache('vpnUrl'). '/live/snapshot_720.jpg');
        log::add('netatmo','debug','VPN URL : '.json_encode($url_parse));
        if (!isset($url_parse['host']) || $url_parse['host'] == '') {
          continue;
        }
        $plugin = plugin::byId('camera');
        $camera_jeedom = eqLogic::byLogicalId($camera['id'], 'camera');
        if (!is_object($camera_jeedom)) {
          $camera_jeedom = new camera();
          $camera_jeedom->setIsEnable(1);
          $camera_jeedom->setIsVisible(1);
          $camera_jeedom->setName($camera['name']);
        }
        $camera_jeedom->setConfiguration('home_id',$home['id']);
        $camera_jeedom->setConfiguration('ip', $url_parse['host']);
        $camera_jeedom->setConfiguration('urlStream', $url_parse['path']);
        $camera_jeedom->setConfiguration('cameraStreamAccessUrl', 'http://#ip#'.str_replace('snapshot_720.jpg','index.m3u8',$url_parse['path']));
        if ($camera['type'] == 'NOC') {
          $camera_jeedom->setConfiguration('device', 'presence');
        } else {
          $camera_jeedom->setConfiguration('device', 'welcome');
        }
        $camera_jeedom->setEqType_name('camera');
        $camera_jeedom->setConfiguration('protocole', $url_parse['scheme']);
        if ($url_parse['scheme'] == 'https') {
          $camera_jeedom->setConfiguration('port', 443);
        } else {
          $camera_jeedom->setConfiguration('port', 80);
        }
        $camera_jeedom->setLogicalId($camera['id']);
        $camera_jeedom->save(true);
        if(is_object($eqLogic)){
          foreach ($eqLogic->getCmd('info') as $cmdEqLogic) {
            if(!in_array($cmdEqLogic->getLogicalId(),array('lastOneEvent','lastEvents'))){
              continue;
            }
            $cmd = $camera_jeedom->getCmd('info', $cmdEqLogic->getLogicalId());
            if (!is_object($cmd)) {
              $cmd = new CameraCmd();
              $cmd->setEqLogic_id($camera_jeedom->getId());
              $cmd->setLogicalId($cmdEqLogic->getLogicalId());
              $cmd->setType('info');
              $cmd->setSubType($cmdEqLogic->getSubType());
              $cmd->setName($cmdEqLogic->getName());
              $cmd->setIsVisible(0);
            }
            $cmd->save();
          }
        }
      }
    }
  }
  
  
  public static function refresh_weather($_weather = null) {
    if($_weather == null){
      $weather = self::request('/getstationsdata');
    }else{
      $weather = $_weather;
    }
    if(isset($weather['body']['devices']) &&  count($weather['body']['devices']) > 0){
      foreach ($weather['body']['devices'] as $device) {
        $eqLogic = eqLogic::byLogicalId($device["_id"], 'netatmo');
        if (!is_object($eqLogic)) {
          continue;
        }
        $eqLogic->setConfiguration('firmware', $device['firmware']);
        $eqLogic->setConfiguration('wifi_status', $device['wifi_status']);
        $eqLogic->save(true);
        if(isset($device['dashboard_data']) && count($device['dashboard_data']) > 0){
          foreach ($device['dashboard_data'] as $key => $value) {
            if ($key == 'max_temp') {
              $collectDate = date('Y-m-d H:i:s', $device['dashboard_data']['date_max_temp']);
            } else if ($key == 'min_temp') {
              $collectDate = date('Y-m-d H:i:s', $device['dashboard_data']['date_min_temp']);
            } else if ($key == 'max_wind_str') {
              $collectDate = date('Y-m-d H:i:s', $device['dashboard_data']['date_max_wind_str']);
            } else {
              $collectDate = date('Y-m-d H:i:s', $device['dashboard_data']['time_utc']);
            }
            $eqLogic->checkAndUpdateCmd(strtolower($key),$value,$collectDate);
          }
        }
        if(isset($device['modules']) &&  count($device['modules']) > 0){
          foreach ($device['modules'] as $module) {
            $eqLogic = eqLogic::byLogicalId($module["_id"], 'netatmo');
            if(!is_object($eqLogic)){
              continue;
            }
            $eqLogic->setConfiguration('rf_status', $module['rf_status']);
            $eqLogic->setConfiguration('firmware', $module['firmware']);
            $eqLogic->save(true);
            $eqLogic->batteryStatus(round(($module['battery_vp'] - self::getGConfig('weather',$module['type'].'::bat_min')) / (self::getGConfig('weather',$module['type'].'::bat_max') - self::getGConfig('weather',$module['type'].'::bat_min')) * 100, 0));
            foreach ($module['dashboard_data'] as $key => $value) {
              if ($key == 'max_temp') {
                $collectDate = date('Y-m-d H:i:s', $module['dashboard_data']['date_max_temp']);
              } else if ($key == 'min_temp') {
                $collectDate = date('Y-m-d H:i:s', $module['dashboard_data']['date_min_temp']);
              } else if ($key == 'max_wind_str') {
                $collectDate = date('Y-m-d H:i:s', $module['dashboard_data']['date_max_wind_str']);
              } else {
                $collectDate = date('Y-m-d H:i:s', $module['dashboard_data']['time_utc']);
              }
              $eqLogic->checkAndUpdateCmd(strtolower($key),$value,$collectDate);
            }
          }
        }
      }
    }
  }
  
  public static function refresh_security($_security = null) {
    if($_security == null){
      $security = self::request('/gethomedata');
    }else{
      $security = $_security;
    }
    try {
      //  self::createCamera($_datas);
    } catch (\Exception $e) {
      
    }
    foreach ($security['body']['homes'] as $home) {
      $eqLogic = eqLogic::byLogicalId($home['id'], 'netatmo');
      if (!is_object($eqLogic)) {
        continue;
      }
      foreach ($home['persons'] as $person) {
        $eqLogic->checkAndUpdateCmd('isHere' . $person['id'], ($person['out_of_sight'] != 1));
        $eqLogic->checkAndUpdateCmd('lastSeen' . $person['id'], date('Y-m-d H:i:s', $person['last_seen']));
      }
      $events = $home['events'];
      if ($events[0] != null && isset($events[0]['event_list'])) {
        $details = $events[0]['event_list'][0];
        $message = date('Y-m-d H:i:s', $details['time']) . ' - ' . $details['message'];
        $eqLogic->checkAndUpdateCmd('lastOneEvent', $message);
      }
      $message = '';
      $eventsByEqLogic = array();
      foreach ($events as $event) {
        if(isset($event['module_id'])){
          $eventsByEqLogic[$event['module_id']][] = $event;
        }else{
          $eventsByEqLogic[$event['device_id']][] = $event;
        }
        if (!isset($event['event_list']) || !isset($event['event_list'][0])) {
          continue;
        }
        $details = $event['event_list'][0];
        if(!isset($details['snapshot']['url'])){
          $details['snapshot']['url'] = '';
        }
        $message .= '<span title="" data-tooltip-content="<img height=\'500\' class=\'img-responsive\' src=\''.self::downloadSnapshot($details['snapshot']['url']).'\'/>">'.date('Y-m-d H:i:s', $details['time']) . ' - ' . $details['message'] . '</span><br/>';
      }
      $eqLogic->checkAndUpdateCmd('lastEvent', $message);
      foreach ($eventsByEqLogic as $id => $events) {
        $eqLogic = eqLogic::byLogicalId($id, 'netatmo');
        if(!is_object($eqLogic)){
          continue;
        }
        $message = '';
        foreach ($events as $event) {
          if(isset($event['message'])){
            $message .= $event['message'].'<br/>';
            continue;
          }
          if (!isset($event['event_list']) || !isset($event['event_list'][0])) {
            continue;
          }
          $details = $event['event_list'][0];
          if(!isset($details['snapshot']['url'])){
            $details['snapshot']['url'] = '';
          }
          $message .= '<span title="" data-tooltip-content="<img height=\'500\' class=\'img-responsive\' src=\''.self::downloadSnapshot($details['snapshot']['url']).'\'/>">'.date('Y-m-d H:i:s', $details['time']) . ' - ' . $details['message'] . '</span><br/>';
        }
        if($message != ''){
          $eqLogic->checkAndUpdateCmd('lastEvent',$message);
        }
      }
      foreach ($home['cameras'] as &$camera) {
        $eqLogic = eqLogic::byLogicalId($camera['id'], 'netatmo');
        $eqLogic->checkAndUpdateCmd('state', ($camera['status'] == 'on'));
        $eqLogic->checkAndUpdateCmd('stateSd', ($camera['sd_status'] == 'on'));
        $eqLogic->checkAndUpdateCmd('stateAlim', ($camera['alim_status'] == 'on'));
        if(!isset($camera['vpn_url']) || $camera['vpn_url'] == ''){
          continue;
        }
        if (!is_object($eqLogic)) {
          continue;
        }
        $url = $camera['vpn_url'];
        try {
          $request_http = new com_http($camera['vpn_url'] . '/command/ping');
          $result = json_decode(trim($request_http->exec(5, 1)), true);
          $eqLogic->setCache('vpnUrl',str_replace(',,','', $result['local_url']));
        } catch (Exception $e) {
          log::add('netatmo','debug','Local error : '.$e->getMessage());
        }
      }
      foreach ($home['cameras'] as &$camera) {
        if(isset($camera['modules'])){
          foreach ($camera['modules'] as $module) {
            $eqLogic = eqLogic::byLogicalId($module['id'], 'netatmo');
            if (!is_object($eqLogic)) {
              continue;
            }
            if($module['type'] == 'NACamDoorTag'){
              $eqLogic->checkAndUpdateCmd('state', ($module['status'] == 'open'));
            }else if($module['type'] == 'NIS'){
              $eqLogic->checkAndUpdateCmd('state', $module['status']);
              $eqLogic->checkAndUpdateCmd('alim', $module['alim_source']);
              $eqLogic->checkAndUpdateCmd('monitoring', $module['monitoring']);
            }
            if(isset($module['battery_percent'])){
              $eqLogic->batteryStatus($module['battery_percent']);
            }
          }
        }
      }
    }
  }
  
  public static function downloadSnapshot($_snapshot){
    if($_snapshot == ''){
      return 'core/img/no_image.gif';
    }
    if(!file_exists(__DIR__.'/../../data')){
      mkdir(__DIR__.'/../../data');
    }
    $parts  = parse_url($_snapshot);
    $filename = basename($parts['path']).'.jpg';
    if($filename == 'getcamerapicture'){
      return 'core/img/no_image.gif';
    }
    if(!file_exists(__DIR__.'/../../data/'.$filename)){
      file_put_contents(__DIR__.'/../../data/'.$filename,file_get_contents($_snapshot));
    }
    return 'plugins/netatmo/data/'.$filename;
  }
  
  public function getImage() {
    if(file_exists(__DIR__.'/../img/'.  $this->getConfiguration('type').'.png')){
      return 'plugins/netatmo/core/img/'.  $this->getConfiguration('type').'.png';
    }
    return false;
  }
  
  /*     * *********************Méthodes d'instance************************* */
  
  
  public function postSave() {
    if ($this->getConfiguration('applyType') != $this->getConfiguration('type')) {
      $this->applyType();
    }
    $cmd = $this->getCmd(null, 'refresh');
    if (!is_object($cmd)) {
      $cmd = new netatmoCmd();
      $cmd->setName(__('Rafraichir', __FILE__));
    }
    $cmd->setEqLogic_id($this->getId());
    $cmd->setLogicalId('refresh');
    $cmd->setType('action');
    $cmd->setSubType('other');
    $cmd->save();
  }
  
  public function applyType(){
    $this->setConfiguration('applyType', $this->getConfiguration('type'));
    $supported_commands = self::getGConfig($this->getConfiguration('mode'),$this->getConfiguration('type').'::cmd');
    $commands = array('commands');
    foreach ($supported_commands as $supported_command) {
      $commands['commands'][] = self::getGConfig($this->getConfiguration('mode'),'commands::'.$supported_command);
    }
    $this->import($commands);
  }
  
  
  /*     * **********************Getteur Setteur*************************** */
}

class netatmoCmd extends cmd {
  /*     * *************************Attributs****************************** */
  
  public function execute($_options = array()) {
    $eqLogic = $this->getEqLogic();
    if ($this->getLogicalId() == 'refresh') {
      if($eqLogic->getConfiguration('mode') == 'weather'){
        netatmo::refresh_weather();
      }
      if($eqLogic->getConfiguration('mode') == 'security'){
        netatmo::refresh_security();
      }
    }
    if(strpos($this->getLogicalId(),'monitoringOff') !== false){
      $request_http = new com_http($this->getCache('vpnUrl').'/command/changestatus?status=off');
      $request_http->exec(5, 1);
    }else if(strpos($this->getLogicalId(),'monitoringOn') !== false){
      $request_http = new com_http($this->getCache('vpnUrl').'/command/changestatus?status=on');
      $request_http->exec(5, 1);
    }else if(strpos($this->getLogicalId(),'light') !== false){
      $vpn = $eqLogic->getCache('vpnUrl');
      $command = '/command/floodlight_set_config?config=';
      if($this->getSubType() == 'slider'){
        $config = '{"mode":"on","intensity":"'.$_options['slider'].'"}';
      }else{
        if($this->getConfiguration('mode')=='on'){
          $config = '{"mode":"on","intensity":"100"}';
        }else if($this->getConfiguration('mode')=='auto'){
          $config = '{"mode":"auto"}';
        }else{
          $config = '{"mode":"off","intensity":"0"}';
        }
      }
      $request_http = new com_http($vpn.$command.urlencode($config));
      $request_http->exec(5, 1);
    }
  }
  
  /*     * **********************Getteur Setteur*************************** */
}
