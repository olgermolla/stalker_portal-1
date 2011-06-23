<?php
/**
 * Main STB class.
 * 
 * @package stalker_portal
 * @author zhurbitsky@gmail.com
 */

class Stb
{
    public $id  = 0;
    public $mac = '';
    public $ip;
    public $hd  = 0;
    private $is_moderator = false;
    private $params = array();
    private $db;
    public $lang;
    private $locale;
    private $country_id;
    public $city_id;
    public $timezone;
    private $stb_lang;
    public $additional_services_on = 0;

    //private static $all_modules = array();
    //private static $disabled_modules = array();
    private static $allowed_languages;
    //private static $allowed_locales;
    
    private static $instance = NULL;

    /**
     * @static
     * @return Stb
     */
    public static function getInstance(){
        if (self::$instance == NULL)
        {
            self::$instance = new Stb();
        }
        return self::$instance;
    }
    
    private function __construct(){
        
        if (!empty($_COOKIE['mac'])){
            $this->mac = @trim(urldecode($_COOKIE['mac']));
        }else if (!empty($_REQUEST['mac'])){
            $this->mac = @trim(urldecode($_REQUEST['mac']));
        }

        if (!empty($_COOKIE['stb_lang'])){
            $this->stb_lang = @trim(urldecode($_COOKIE['stb_lang']));
        }

        if (!empty($_COOKIE['timezone'])){
            $this->timezone = @trim(urldecode($_COOKIE['timezone']));
        }

        //var_dump($_COOKIE, $this->stb_lang);
        
        if (@$_SERVER['HTTP_X_REAL_IP']){
            $this->ip = @$_SERVER['HTTP_X_REAL_IP'];
        }else{
            $this->ip = @$_SERVER['REMOTE_ADDR'];
        }
        
        $this->db = Mysql::getInstance();
        $this->getStbParams();
        
        if ($this->db->from('moderators')->where(array('mac' => $this->mac, 'status' => 1))->get()->count() == 1){
            $this->is_moderator = true;
        }

        //if ($this->is_moderator && !empty($_COOKIE['debug'])){
        if (!empty($_COOKIE['debug']) || !empty($_REQUEST['debug'])){
            Mysql::$debug = true;
        }
    }
    
    public function setId($id){
        $this->id = $id;
        $this->params['id'] = $id;
    }
    
    public function getStbParams(){

        $user = $this->db->from('users')
                         ->where(array('mac' => $this->mac))
                         ->get()
                         ->first();
        
        if (!empty($user)){
            $this->params = $user;
            $this->id    = $user['id'];
            $this->hd    = $user['hd'];

            $this->locale     = (empty($user['locale']) && Config::exist('default_locale')) ? Config::get('default_locale') : $user['locale'];

            $this->city_id = (empty($user['city_id']) && Config::exist('default_city_id')) ? Config::get('default_city_id') : intval($user['city_id']);

            $this->country_id = intval(Mysql::getInstance()->from('cities')->where(array('id' => $this->city_id))->get()->first('country_id'));

            $this->timezone   = (empty($this->timezone) && Config::exist('default_timezone')) ? Config::get('default_timezone') : $this->timezone;
            
            date_default_timezone_set($this->timezone);

            $date = new DateTime();
            $offset = $date->format('P');
            Mysql::getInstance()->set_timezone($offset);

            $stb_lang = $this->stb_lang;

            if (!empty($this->stb_lang) && strlen($this->stb_lang) >= 2){
                $preferred_locales = array_filter(Config::get('allowed_locales'),
                    function ($e) use ($stb_lang){
                        if (strpos($e, $stb_lang) === 0){
                            return true;
                        }
                    }
                );

                if (!empty($preferred_locales)){

                    $preferred_locales = array_values($preferred_locales);

                    $this->locale = $preferred_locales[0];
                }
            }

            $this->additional_services_on = $user['additional_services_on'];

            setlocale(LC_MESSAGES, $this->locale);
            putenv('LC_MESSAGES='.$this->locale);

            if (!function_exists('bindtextdomain')){
                throw new ErrorException("php-gettext extension not installed.");
            }

            if (!function_exists('locale_accept_from_http')){
                throw new ErrorException("php-intl extension not installed.");
            }

            bindtextdomain('stb', PROJECT_PATH.'/locale');
            textdomain('stb');
            bind_textdomain_codeset('stb', 'UTF-8');
        }
    }
    
    public function getStorages(){

        $master = new VideoMaster();
        return $master->getStoragesForStb();
    }
    
    public function getProfile(){
        
        if (!$this->id){
            $this->createProfile();
        }

        $this->getInfoFromOss();
        
        $this->db->update('users', array(
                'last_start' => 'NOW()',
                'keep_alive' => 'NOW()',
                'version'    => @$_REQUEST['ver'],
                'hd'         => @$_REQUEST['hd'],
            ),
            array('id' => $this->id));
        
        $master = new VideoMaster();
        /*$master->checkAllHomeDirs();*/
        
        $profile = $this->params;
        $profile['storages'] = $master->getStoragesForStb();
        
        $itv = Itv::getInstance();
        $profile['last_itv_id'] = $itv->getLastId();
        
        $profile['updated'] = $this->getUpdatedPlaces();
        
        $profile['rtsp_type']  = Config::get('rtsp_type');
        $profile['rtsp_flags'] = Config::get('rtsp_flags');

        $profile['locale'] = $this->locale;

        $profile['display_menu_after_loading'] = Config::get('display_menu_after_loading');
        $profile['record_max_length']          = intval(Config::get('record_max_length'));

        $profile['web_proxy_host']         = Config::exist('stb_http_proxy_host') ? Config::get('stb_http_proxy_host') : '';
        $profile['web_proxy_port']         = Config::exist('stb_http_proxy_port') ? Config::get('stb_http_proxy_port') : '';
        $profile['web_proxy_user']         = Config::exist('stb_http_proxy_user') ? Config::get('stb_http_proxy_user') : '';
        $profile['web_proxy_pass']         = Config::exist('stb_http_proxy_pass') ? Config::get('stb_http_proxy_pass') : '';
        $profile['web_proxy_exclude_list'] = Config::exist('stb_http_proxy_exclude_list') ? Config::get('stb_http_proxy_exclude_list') : '';

        return $profile;
    }
    
    private function createProfile(){
        
        $uid = $this->db->insert('users', array(
                    'mac'  => $this->mac,
                    'name' => substr($this->mac, 12, 16)
                ))->insert_id();
                
        $this->getStbParams();        
        
        $this->setId($uid);
            
        $this->db->insert('updated_places', array('uid' => $this->id));
    }
    
    public function getLocalization(){
        return System::get_all_words();
    }
    
    public function isModerator(){
        return $this->is_moderator;
    }
    
    public function getPreloadImages(){
        
        $dir = PROJECT_PATH.'/../c/i/';
        $files = array();

        if (is_dir($dir)) {
            if ($dh = opendir($dir)) {
                while (($file = readdir($dh)) !== false) {
                    if (is_file($dir.$file)){
                        $files[] = 'i/'.$file;
                    }
                }
                closedir($dh);
            }
        }
        
        return $files;
    }
    
    public function setParentPassword(){
        
        if (isset($_REQUEST['pass'])){
            $this->db->update('users', array('parent_password' => $_REQUEST['pass']), array('mac' => $this->mac));
            $this->params['parent_password'] = $_REQUEST['pass'];
        }
        
        return true;
    }
    
    public function setVolume(){
        
        $volume = intval($_REQUEST['vol']);
        
        if($volume < 0 || $volume > 100){
            $volume = 100;
        }
        
        $this->db->update('users', array('volume' => $volume), array('mac' => $this->mac));
        $this->params['volume'] = $volume;
        
        return true;
    }
    
    public function setAspect(){
        $aspect = intval($_REQUEST['aspect']);
        
        $this->db->update('users', array('aspect' => $aspect), array('mac' => $this->mac));
        $this->params['aspect'] = $aspect;
        
        return true;
    }
    
    public function setFavItvStatus(){
        
        $fav_itv_on = intval($_REQUEST['fav_itv_on']);
        
        $this->db->update('users', array('fav_itv_on' => $fav_itv_on), array('mac' => $this->mac));
        $this->params['fav_itv_on'] = $fav_itv_on;
        
        return true;
    }
    
    public function getUpdatedPlaces(){
        //return $this->db->getFirstData('updated_places', array('uid' => $this->id));
        return $this->db->from('updated_places')->where(array('uid' => $this->id))->get()->first();
    }
    
    public function setUpdatedPlaceConfirm(){
        
        $place = $_REQUEST['place'];
        
        $this->db->update('updated_places', array($place => 0), array('uid' => $this->id));
        
        return true;
    }
    
    public function setEventConfirm(){
        
        $event_id = intval($_REQUEST['event_id']);

        Event::setConfirmed($event_id);
        
        return true;
    }
    
    public function getWatchdog(){
        
        $this->db->update('users', 
                          array('keep_alive'       => 'NOW()',
                                'ip'               => $this->ip,
                                'now_playing_type' => intval($_REQUEST['cur_play_type'])
                               ), 
                          array('mac' => $this->mac));
        
        
        $events = Event::getAllNotEndedEvents($this->id);
        
        $messages = count($events);
                
        $res = array();
        $res['msgs'] = $messages;
        
        if ($messages>0){
            if ($events[0]['sended'] == 0){
                
                Event::setSended($events[0]['id']);
                
                if($events[0]['need_confirm'] == 0){
                    Event::setEnded($events[0]['id']);
                }
            }
            
            if ($events[0]['id'] != @$_REQUEST['event_active_id']){
                $res['id']    = $events[0]['id'];
                $res['event'] = $events[0]['event'];
                $res['need_confirm'] = $events[0]['need_confirm'];
                $res['msg']   = $events[0]['msg'];
                $res['reboot_after_ok'] = $events[0]['reboot_after_ok'];
            }
        }
        
        $res['additional_services_on'] = $this->additional_services_on;
        
        $cur_weather = new Curweather();
        $res['cur_weather'] = $cur_weather->getData();
        
        $res['updated'] = $this->getUpdatedPlaces();
        
        return $res;
    }
    
    public function setStreamError(){
        $this->db->insert('stream_error',
                           array(
                                'ch_id'      => intval($_REQUEST['ch_id']),
                                'mac'        => $this->stb->mac,
                                'error_time' => 'NOW()'
                           ));
        return true;
    }
    
    public function log(){
        
        $action = strval($_REQUEST['real_action']);
        $param  = strval($_REQUEST['param']);
        $type   = $_REQUEST['tmp_type'];
        
        $this->db->insert('user_log',
                            array(
                                'mac'    => $this->mac,
                                'action' => $action,
                                'param'  => $param,
                                'time'   => 'NOW()',
                                'type'   => $type
                            ));
        
        $update_data = array();
                            
        if ($action == 'play'){
            $update_data['now_playing_start'] = 'NOW()';

            switch ($type){
                case 1: // TV
                    
                    $ch_name = $this->db->from('itv')->where(array('cmd' => $param, 'status' => 1))->get()->first('name');
                    
                    if (empty($ch_name)){
                        $ch_name = $param;
                    }
                    
                    $update_data['now_playing_content'] = $ch_name;
                    
                    break;
                case 2: // Video Club
                    
                    //preg_match("/auto \/media\/([\S\s]+)\/(\d+)\.[a-z]*$/", $param, $tmp_arr);

                    if (strpos($param, 'http://') !== false){
                        preg_match("/\/([^\/]+)\/[^\/]+\/(\d+)\.[a-z]*$/", $param, $tmp_arr);
                    }else{
                        preg_match("/auto \/media\/([\S\s]+)\/(\d+)\.[a-z]*$/", $param, $tmp_arr);
                    }
                    
                    $storage  = $tmp_arr[1];
                    $media_id = intval($tmp_arr[2]);
                    
                    $video = $this->db->from('video')->where(array('id' => $media_id))->get()->first();
                    
                    $update_data['storage_name'] = $storage;
                    
                    if (!empty($video)){
                        
                        $update_data['now_playing_content'] = $video['name'];
                        $update_data['hd_content']          = $video['hd'];
                    }else{
                        $update_data['now_playing_content'] = $param;
                    }
                    
                    break;
                case 3: // Karaoke
                    
                    preg_match("/(\d+).mpg$/", $param, $tmp_arr);
                    $karaoke_id = intval($tmp_arr[1]);
                    
                    $karaoke = $this->db->from('karaoke')->where(array('id' => $karaoke_id))->get()->first();
                    
                    if (!empty($karaoke)){
                        $update_data['now_playing_content'] = $karaoke['name'];
                    }else{
                        $update_data['now_playing_content'] = $param;
                    }
                    
                    break;
                case 4: // Audio Club
                    
                    preg_match("/(\d+).mp3$/", $param, $tmp_arr);
                    $audio_id = intval($tmp_arr[1]);
                    
                    $audio = $this->db->from('audio')->where(array('id' => $audio_id))->get()->first();
                    
                    if (!empty($audio)){
                        $update_data['now_playing_content'] = $audio['name'];
                    }else{
                        $update_data['now_playing_content'] = $param;
                    }
                    
                    break;
                case 5: // Radio
                
                    $radio = $this->db->from('radio')->where(array('cmd' => $param, 'status' => 1))->get()->first();
                    
                    if (!empty($radio)){
                        $update_data['now_playing_content'] = $radio['name'];
                    }else{
                        $update_data['now_playing_content'] = $param;
                    }
                    
                    break;
                case 6: // My Records
                
                    /*$my_record_name = '';
                    
                    preg_match("/\/(\d+).mpg/", $param, $tmp_arr);
                    $my_record_id = $tmp_arr[1];
                    
                    $sql = "select t_start,itv.name from users_rec, itv where users_rec.ch_id=itv.id and users_rec.id=$my_record_id";
                    $rs = $db->executeQuery($sql);
                    
                    if ($rs->getRowCount() == 1){
                        $my_record_name = $rs->getValueByName(0, 't_start').' '.$rs->getValueByName(0, 'name');
                    }else{
                        $my_record_name = $param;
                    }
                    
                    $_sql .= ", now_playing_content='$my_record_name'";
                    break;*/
                case 7: // Shared Records
                    /*$shared_record_name = '';

                    preg_match("/(\d+).mpg$/", $param, $tmp_arr);
                    $shared_record_id = $tmp_arr[1];

                    $sql = "select * from video_records where id=$shared_record_id";
                    $rs = $db->executeQuery($sql);

                    if ($rs->getRowCount() == 1){
                        $shared_record_name = $rs->getValueByName(0, 'descr');
                    }else{
                        $shared_record_name = $param;
                    }

                    $_sql .= ", now_playing_content='$shared_record_name'";*/
                    break;
                case 8: // Video clips
                    /*$video_name = '';

                    preg_match("/(\d+).mpg$/", $param, $tmp_arr);
                    $media_id = $tmp_arr[1];

                    $sql = "select * from video_clips where id=$media_id";
                    $rs = $db->executeQuery($sql);

                    if ($rs->getRowCount() == 1){
                        $video_name = $rs->getValueByName(0, 'name');
                    }else{
                        $video_name = $param;
                    }

                    $_sql .= ", now_playing_content='$video_name'";
                    break;*/
                default:
                    $update_data['now_playing_content'] = 'unknown media '.$param;
            }
        }
        
        if ($action == 'infoportal'){
            $update_data['now_playing_start'] = 'NOW()';

            $info_arr = array(
                20 => 'city_info',
                21 => 'anec_page',
                22 => 'weather_page',
                23 => 'game_page',
                24 => 'horoscope_page',
                25 => 'course_page'
            );
            
            if (@$info_arr[$type]){
                $info_name = $info_arr[$type];
            }else{
                $info_name = 'unknown';
            }
            
            $update_data['now_playing_content'] = $info_name;
        }
        
        if ($action == 'stop' || $action == 'close_infoportal'){
            
            $update_data['now_playing_content'] = '';
            $update_data['storage_name'] = '';
            $update_data['hd_content'] = '';
            
            $type = 0;
        }
        
        if ($action == 'pause'){
            
            $this->db->insert('vclub_paused',
                              array(
                                  'uid' => $this->id,
                                  'mac' => $this->mac,
                                  'pause_time' => 'NOW()'
                              ));
        }
        
        if ($action == 'continue' || $action == 'stop' || $action == 'set_pos()' || $action == 'play'){
            
            $this->db->delete('vclub_paused',
                              array(
                                  'mac' => $this->mac
                              ));
        }
        
        if ($action == 'readed_anec'){
            
            return $this->db->insert('readed_anec',
                              array(
                                  'mac'    => $this->mac,
                                  'readed' => 'NOW()'
                              ));
        }
        
        if ($action == 'loading_fail'){
            
            return $this->db->insert('loading_fail',
                                     array(
                                         'mac'   => $this->mac,
                                         'added' => 'NOW()'
                                     ));
        }
        
        $update_data['last_active'] = 'NOW()';
        $update_data['keep_alive']  = 'NOW()';
        $update_data['now_playing_type']  = $type;
        
        $this->db->update('users', $update_data, array('mac' => $this->mac));
        
        return 1;
    }
    
    public function getModules(){

        return array(
            'all_modules'      => Config::get('all_modules'),
            'disabled_modules' => Config::get('disabled_modules')
            );
    }
    
    /*public static function setModules($all, $disabled){
        
        self::$all_modules = $all;
        self::$disabled_modules = $disabled;
    }*/
    
    public static function setAllowedLanguages($languages){
        
        self::$allowed_languages = $languages;
    }

    /*public static function setAllowedLocales($locales){

        self::$allowed_locales = $locales;
    }*/

    public function getLocales(){

        $result = array();

        foreach (Config::get('allowed_locales') as $label => $locale){
            $selected = ($this->locale == $locale)? 1 : 0;
            $result[] = array('label' => $label, 'value' => $locale, 'selected' => $selected);
        }

        return $result;
    }

    public function setLocale(){
        $locale  = $_REQUEST['locale'];
        $city_id = intval($_REQUEST['city']);

        if (in_array($locale, Config::get('allowed_locales'))){

            return $this->db->update('users', array('locale' => $locale, 'city_id' => $city_id), array('id' => $this->id));
        }

        return false;
    }

    public function setPlaybackSettings(){
        $playback_buffer_bytes = intval($_REQUEST['playback_buffer_bytes']);
        $playback_buffer_size  = intval($_REQUEST['playback_buffer_size']) * 1000;
        $audio_out             = intval($_REQUEST['audio_out']);

        return Mysql::getInstance()->update('users',
            array(
                'playback_buffer_bytes' => $playback_buffer_bytes,
                'playback_buffer_size'  => $playback_buffer_size,
                'audio_out'             => $audio_out
            ),
            array('id' => $this->id));
    }

    public function getCountries(){

        $result = array();

        $countries = Mysql::getInstance()->from('countries')->orderby('name_en')->get()->all();

        foreach ($countries as $country){
            $selected = ($this->country_id == $country['id'])? 1 : 0;
            $result[] = array('label' => $country['name_en'], 'value' => $country['id'], 'selected' => $selected);
        }

        return $result;
    }

    public function searchCountries(){

        $search = $_REQUEST['search'];

        if (empty($search)){
            return array();
        }

        $countries = Mysql::getInstance()
            ->select('id, name_en')
            ->from('countries')
            ->like(array(
                'name'    => $search.'%',
                'name_en' => $search.'%'
            ), 'OR ')
            ->limit(3)
            ->get()
            ->all();

        $result = array();

        foreach ($countries as $country){
            $result[] = array('label' => $country['name_en'], 'value' => $country['id']);
        }

        return $result;
    }

    public function getCities(){

        $country_id = intval($_REQUEST['country_id']);

        $result = array();

        /// TRANSLATORS: don't translate this.
        $cities = Mysql::getInstance()->from('cities')->where(array('country_id' => $country_id))->orderby(_('city_name_field'))->get()->all();

        foreach ($cities as $city){
            $selected = ($this->city_id == $city['id'])? 1 : 0;
            //$city_name = empty($city[_('city_name_field')]) ? $city['name_en'] : $city[_('city_name_field')];
            $city_name = $city['name_en'];
            $result[] = array('label' => $city_name , 'value' => $city['id'], 'timezone' => $city['timezone'], 'selected' => $selected);
        }

        return $result;
    }

    public function searchCities(){

        $search = $_REQUEST['search'];
        $country_id = intval($_REQUEST['country_id']);

        if (empty($search)){
            return array();
        }

        $cities = Mysql::getInstance()
            ->select('id, name_en')
            ->from('cities')
            ->where(array('country_id' => $country_id))
            ->like(array(
                'name'    => $search.'%',
                'name_en' => $search.'%'
            ), 'OR ')
            ->limit(3)
            ->get()
            ->all();

        $result = array();
        
        foreach ($cities as $city){
            $result[] = array('label' => $city['name_en'] , 'value' => $city['id']);
        }

        return $result;
    }

    public function getTimezones(){

        $result = array();

        $timezones = Mysql::getInstance()->from('cities')->groupby('timezone')->orderby('timezone')->get()->all('timezone');

        foreach ($timezones as $timezone){

            if (empty($timezone)) continue;
            
            $selected = ($this->timezone == $timezone)? 1 : 0;
            $result[] = array('label' => $timezone, 'value' => $timezone, 'selected' => $selected);
        }

        return $result;
    }

    public function getByUids($uids = array()){

        $result = Mysql::getInstance()->from('users');

        //if (!empty($uids)){
        $result = $result->in('id', $uids);
        //}

        $result = $result->get()->all();

        return $result;
    }

    public function updateByUids($uids = array(), $data){

        if (empty($data)){
            return false;
        }

        $result = Mysql::getInstance();

        //if (!empty($uids)){
        $result = $result->in('id', $uids);
        //}

        $result = $result->update('users', $data);

        if (!$result){
            return false;
        }

        return $this->getByUids($uids);
    }

    private function getInfoFromOss(){

        if (!Config::exist('oss_url')){
            return false;
        }

        if (Config::get('oss_url') == ''){
            return false;
        }
        
        $data = file_get_contents(Config::get('oss_url').'?mac='.$this->mac.'&uid='.$this->id);

        if (!$data){
            return false;
        }

        $data = json_decode($data);

        if (empty($data)){
            return false;
        }

        if (key_exists('ls', $data)){
            Mysql::getInstance()->update('users', array('ls' => $data['ls']), array('id' => $this->id));
        }
    }

    public static function getUidByLs($ls){

        $result = Mysql::getInstance()->from('users');

        if ($ls !== null){

            if (!is_array($ls)){
                $ls = array($ls);
            }

            $result = $result->in('ls', $ls);
        }

        return $result->get()->all('id');
    }

    public static function getUidByMacs($mac){

        if (!is_array($mac)){
            $mac = array($mac);
        }

        return Mysql::getInstance()->from('users')->in('mac', $mac)->get()->all('id');
    }
}
?>