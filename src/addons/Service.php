<?php
declare(strict_types=1);

namespace think\addons;

use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Event;
use think\addons\middleware\Addons;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use Symfony\Component\VarExporter\VarExporter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
/**
 * 插件服务
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    protected $addons_path;
    /**
     * 框架升级
     */
    public static function hemaphp()
    {
        // 远程下载插件，返回存放路径
        $tmpFile = runtime_path() . "hemaphp.zip";
        try {
            $client = self::getClient();
            $response = $client->get('/api/hema/upgrade', [
                'query' => [
                    'domain' => app()->request->host(),
                    'hmversion' => Config::get('app.hemaphp.version')
                ]
            ]);
            $body = $response->getBody();
            $content = $body->getContents();
            if (substr($content, 0, 1) === '{') {
                return (array)json_decode($content, true);
            }
        } catch (TransferException $e) {
            return ['msg' => '下载失败'];
        }
        if (file_put_contents($tmpFile,$content)) {
            //解压插件压缩包到指定目录
            $zip = new ZipFile();// 打开插件压缩包
            try {
                $zip->openFile($tmpFile);
            } catch (ZipException $e) {
                @unlink($tmpFile);// 移除临时文件
                $zip->close();
                return ['msg' => '无法打开zip文件'];
            }
            $dir = runtime_path() . 'hemaphp' . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0777);
            }
            try {
                $zip->extractTo($dir);// 解压插件压缩包
            } catch (ZipException $e) {
                @rmdirs($dir);//删除目录
                return ['msg' => '无法提取文件'];
            } finally {
                @unlink($tmpFile);// 移除临时文件
                $zip->close();
            }
            //升级数据库
            $sqlFile = $dir . '.database';
            if (is_file($sqlFile)) {
                $lines = file($sqlFile,FILE_IGNORE_NEW_LINES);//把文件读入一个数组中
                $templine = '';
                foreach ($lines as $line) {
                    if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                        continue;
                    }
                    $templine .= $line;
                    if (substr(trim($line), -1, 1) == ';') {
                        $templine = str_ireplace('__PREFIX__', Config::get('database.connections.mysql.prefix'), $templine);
                        $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                        try {
                            Db::getPdo()->exec($templine);
                        } catch (\PDOException $e) {
                            //$e->getMessage();
                        }
                        $templine = '';
                    }
                }
                @unlink($sqlFile);//删除数据库升级文件
            }
            if (is_dir($dir)) {
                copydirs($dir, root_path());
                @rmdirs($dir);
            }
            return true;
        }
        return ['msg' => '没有写入临时文件的权限'];
    }
    
    /**
     * 安装插件
     * @param string  $name   插件名称
     * @param array   $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $extend = [])
    {
        if (!$name || is_dir(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name)) {
            return ['msg' => '插件已经存在'];
        }
        $tmpFile = Service::download($name, $extend);// 远程下载插件，返回存放路径
        if(is_array($tmpFile)){
            return $tmpFile;
        }
        //进行本地安装
        $result = Service::local($tmpFile, $extend,$name);
        if(is_array($result)){
            return $result;
        }
        return true;
    }
    
    /**
     * 离线安装
     * @param string $tmpFile 插件压缩包
     * $extend 请求参数
     * $name 插件标识名称
     * @param array  $extend
     */
    public static function local($tmpFile, $extend = [],$name='')
    {
        //进行离线模式安装前检测
        if(empty($name)){
            $zip = new ZipFile();
            try {
                $zip->openFile($tmpFile);// 打开插件压缩包
                if(!$config = self::getInfoIni($zip)){
                    return ['msg' => '无法提取ini配置文件'];
                }
                $name = isset($config['name']) ? $config['name'] : '';// 判断插件标识
                if (!$name) {
                    return ['msg' => 'ini配置数据不正确'];
                }
            } catch (ZipException $e) {
                @unlink($tmpFile);
                return ['msg' => '无法打开插件包'];
            } finally {
                $zip->close();
            }
        }
        // 判断插件是否存在
        if (!preg_match("/^[a-zA-Z0-9]+$/", $name)) {
            @unlink($tmpFile);// 移除临时文件
            return ['msg' => '插件标识名称不正确'];
        }
        // 判断新插件是否存在
        $addonDir = self::getAddonDir($name);//获取指定插件的目录
        if (is_dir($addonDir)) {
            @unlink($tmpFile);// 移除临时文件
            return ['msg' => '插件‘'.$name.'’已存在，请先卸载'];
        }
        $extend['name'] = $name;
        $result = Service::unzip($tmpFile,$addonDir,$extend);//解压
        if(is_array($result)){
            @unlink($tmpFile);// 移除临时文件
            return $result;
        }
        $result = Service::check($name);// 检查插件是否完整
        if(is_array($result)){
            @rmdirs($addonDir);//删除安装目录目录
            return $result;
        }
        Service::importsql($name);// 导入数据
        Db::startTrans();
        try {
            //执行插件的安装方法
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if(!$addon->install()){
                    return ['msg' => $addon->getError()];
                }
            }
            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);//删除安装目录目录
            Db::rollback();
            return ['msg' => $e->getMessage()];
        }
        $result = Service::enable($name);// 启用插件
        if(is_array($result)){
            return $result;
        }
        Service::refresh();// 刷新
        return true;
    }
    /**
     * 升级插件
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
    {
        $config = get_addons_config($name);//获取原有配置值（备份）
        $tmpFile = Service::download($name, $extend);// 远程下载插件，返回存放路径
        if(is_array($tmpFile)){
            return $tmpFile;
        }
        Service::backup($name);// 备份插件文件
        $addonDir = self::getAddonDir($name);//获取指定插件的目录
        $extend['name'] = $name;
        $result = Service::unzip($tmpFile, $addonDir, $extend);// 解压插件压缩包到插件目录
        if(is_array($result)){
            return $result;
        }
        $result = Service::check($name);// 检查插件是否完整
        if(is_array($result)){
            return $result;
        }
        if ($config) {
            set_addons_config($name, $config);// 还原配置
        }
        Service::importsql($name);// 导入
        // 执行升级脚本
        try {
            $class = get_addons_class($name);// 执行安装脚本
            if (class_exists($class)) {
                $addon = new $class();
                if(!$addon->upgrade()){
                    return ['msg' => $addon->getError()];
                }
            }
        } catch (Exception $e) {
            return ['msg' => $e->getMessage()];
        }
        $result = Service::enable($name);// 启用插件
        if(is_array($result)){
            return $result;
        }
        Service::refresh();// 刷新
        return true;
    }
    
    
    /**
     * 启用
     * @param string  $name  插件名称
     * @return  boolean
     */
    public static function enable($name)
    {
        if (!$name || !is_dir(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name)) {
            return ['msg' => '插件不存在'];
        }
        $addonDir = self::getAddonDir($name);//获取指定插件的目录
        $sourceAssetsDir = self::getSourceAssetsDir($addonDir);//获取插件资源源文件夹
        $destAssetsDir = self::getDestAssetsDir($name); //获取插件资源文件存放文件夹
        $files = self::getGlobalFiles($name);//获取插件在全局的文件
        if ($files) {
            Service::config($name, ['files' => $files]);////刷新插件配置缓存
        }
        // 复制资源文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        // 复制app到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, root_path() . $dir);
            }
        }
        //插件纯净模式时将插件目录下的app和assets删除
        if (Config::get('app.hemaphp.addon_pure_mode')) {
            // 删除插件目录已复制到全局的文件
            @rmdirs($sourceAssetsDir);
            foreach (self::getCheckDirs() as $k => $dir) {
                @rmdirs($addonDir . $dir);
            }
        }
        //执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "enable")) {
                    if(!$addon->enable()){
                        return ['msg' => $addon->getError()];
                    }
                }
            }
        } catch (Exception $e) {
            return ['msg' => $e->getMessage()];
        }
        $info = get_addons_info($name);
        $info['status'] = 1;
        $info['config'] = get_addons_config($name) ? 1 : 0;//是否有参数配置项
        unset($info['url']);
        set_addons_info($name, $info);
        Service::refresh();// 刷新
        return true;
    }
    
    /**
     * 获取插件在全局的文件
     * @param string  $name         插件名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = self::getAddonDir($name); //获取指定插件的目录
        $checkDirList = self::getCheckDirs(); //检测的全局文件夹目录
        $checkDirList = array_merge($checkDirList, ['assets']);
        $assetDir = self::getDestAssetsDir($name);//获取插件资源文件存放文件夹
        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $k => $dirName) {
            //检测目录是否存在
            if (!is_dir($addonDir . $dirName)) {
                continue;
            }
            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    //如果名称为assets需要做特殊处理
                    if ($dirName === 'assets') {
                        $path = str_replace(root_path(), '', $assetDir) . str_replace($addonDir . $dirName . DIRECTORY_SEPARATOR, '', $filePath);
                    } else {
                        $path = str_replace($addonDir, '', $filePath);
                    }
                    //是否只返回冲突文件
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        $list = array_filter(array_unique($list));
        return $list;
    }
    
    /**
     * 禁用
     * @param string  $name  插件名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name)) {
            return ['msg' => '插件不存在'];
        }
        $config = Service::config($name);//全局文件
        $addonDir = self::getAddonDir($name);//插件目录
        $destAssetsDir = self::getDestAssetsDir($name);//获取插件资源文件存放文件夹
        $list = Service::getGlobalFiles($name);// 移除插件全局文件
        
        //插件纯净模式时将原有的文件复制回插件目录
        //当无法获取全局文件列表时也将列表复制回插件目录
        if (Config::get('app.hemaphp.addon_pure_mode') || !$list) {
            if ($config && isset($config['files']) && is_array($config['files'])) {
                foreach ($config['files'] as $index => $item) {
                    $item = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item);//避免切换不同服务器后导致路径不一致
                    //插件资源目录，无需重复复制
                    if (stripos($item, str_replace(root_path(), '', $destAssetsDir)) === 0) {
                        continue;
                    }
                    //检查目录是否存在，不存在则创建
                    $itemBaseDir = dirname($addonDir . $item);
                    if (!is_dir($itemBaseDir)) {
                        @mkdir($itemBaseDir, 0777, true);
                    }
                    if (is_file(root_path() . $item)) {
                        @copy(root_path() . $item, $addonDir . $item);
                    }
                }
            }
            //复制插件目录资源
            if (is_dir($destAssetsDir)) {
                @copydirs($destAssetsDir, $addonDir . 'assets' . DIRECTORY_SEPARATOR);
            }
        }
        $info = get_addons_info($name);
        $info['status'] = 0;
        unset($info['url']);
        set_addons_info($name, $info);
        // 执行禁用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "disable")) {
                    if(!$addon->disable()){
                        return ['msg' => $addon->getError()];
                    }
                }
            }
        } catch (Exception $e) {
            return ['msg' => $e->getMessage()];
        }
        Service::refresh();// 刷新
        return true;
    }
    
    /**
     * 获取插件资源源文件夹
     * @param string $addonDir 插件目录
     * @return  string
     */
    protected static function getSourceAssetsDir($addonDir)
    {
        return $addonDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
    }
    
    /**
     * 获取插件资源文件存放文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = root_path() . str_replace("/", DIRECTORY_SEPARATOR, "public/addons/{$name}/");
        return $assetsDir;
    }
    
    /**
     * 检测插件是否完整
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name)) {
            return ['msg' => '插件不存在'];
        }
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            return ['msg' => '插件类文件不存在'];
        }
        $addon = new $addonClass();
        if (!$addon->checkInfo()) {
            return ['msg' => '配置文件内容不正确'];
        }
        return true;
    }
    /**
     * 解压插件
     * @param string $tmpFile 压缩包目录
     * @param string $addonDir 解压到目录
     * @return  boolean
     * @throws  Exception
     */
    public static function unzip($tmpFile,$addonDir,$extend=[])
    { 
        $zip = new ZipFile();// 打开插件压缩包
        // 追加MD5和Data数据
        $extend['md5'] = md5_file($tmpFile);
        $extend['data'] = $zip->getArchiveComment();
        $extend['unknownsources'] = config('app.hemaphp.unknownsources');
        $extend['hmversion'] = config('app.hemaphp.version');
        // 压缩包验证、版本依赖判断
        $result = Service::valid($extend);//正确返回秘钥
        if(is_array($result)){
            @unlink($tmpFile);// 移除临时文件
            return $result;
        }
        
        try {
            $zip->openFile($tmpFile);
        } catch (ZipException $e) {
            @unlink($tmpFile);// 移除临时文件
            $zip->close();
            return ['msg' => '无法打开zip文件'];
        }
        if (!is_dir($addonDir)) {
            @mkdir($addonDir, 0777);
        }
        //解压操作
        try {
            if(!empty($result)){
                $zip->setReadPassword($result);
            }
            $zip->extractTo($addonDir);// 解压插件压缩包
        } catch (ZipException $e) {
            return ['msg' => '无法提取压缩包文件'];
        } finally {
            @unlink($tmpFile);// 移除临时文件
            $zip->close();
        }
        return true;
    }
    
    /**
     * 远程下载插件
     * @param string $name   插件名称
     * @param array  $extend 扩展参数
     * @return  string
     */
    public static function download($name, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();//获取插件备份目录
        $tmpFile = $addonsTempDir . $name . ".zip";
        try {
            $client = self::getClient();
            $response = $client->get('/api/addon/download', [
                'query' => array_merge([
                    'name' => $name,
                    'domain' => app()->request->host(),
                    'hmversion' => Config::get('app.hemaphp.version')
                ], $extend)
            ]);
            $body = $response->getBody();
            $content = $body->getContents();
            if (substr($content, 0, 1) === '{') {
                return (array)json_decode($content, true);
            }
        } catch (TransferException $e) {
            return ['msg' => '插件包下载失败'];
        }
        if (file_put_contents($tmpFile,$content)) {
            return $tmpFile;
        }
        return ['msg' => '没有写入临时文件的权限'];
    }
    
    /**
     * 卸载插件
     * @param string  $name
     * @param string  $force 是否卸载数据库
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = true)
    {
        if (!$name || !is_dir(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name)) {
            return ['msg' => '插件不存在'];
        }
        if($force){
            // 执行卸载数据库内容脚本
            try {
                $class = get_addons_class($name);
                if (class_exists($class)) {
                    $addon = new $class();
                    if(!$addon->uninstall()){
                        return ['msg' => $addon->getError()];
                    }
                    Service::importsql($name,$force);// 卸载数据表
                }
            } catch (Exception $e) {
                return ['msg' => $e->getMessage()];
            }
        }
        $config = Service::config($name);//全局文件
        //删除全局文件
        if($config && isset($config['files']) && is_array($config['files'])){
            $list = $config['files'];
            $dirs = [];
            foreach ($list as $k => $v) {
                $file = root_path() . $v;
                if (is_file($file)) {
                    @unlink($file);
                }
                $dirs[] = dirname($file);
            }
            // 移除插件空目录
            $dirs = array_filter(array_unique($dirs));
            foreach ($dirs as $k => $v) {
                if(strpos($v,"view/layout")){
                    continue; //不删除layout目录
                }
                if(strpos($v,"store/config")){
                    continue; //不删除config目录
                }
                @rmdirs($v);//删除目录
            }
        }
        @rmdirs(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name);// 移除插件目录
        Service::refresh();// 刷新
        return true;
    }
    
    /**
     * 读取或修改插件配置
     * @param string $name
     * @param array  $changed
     * @return array
     */
    public static function config($name, $changed = [])
    {
        $addonDir = self::getAddonDir($name);
        $addonConfigFile = $addonDir . '.addonrc';
        $config = [];
        if (is_file($addonConfigFile)) {
            $config = (array)json_decode(file_get_contents($addonConfigFile), true);
        }
        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($addonConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }
        return $config;
    }
    
    /**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
    {
        return [
            'app'
        ];
    }
    /**
     * 导入SQL
     * @param string $name 插件名称
     * @param bol $force 是否卸载数据
     * @return  boolean
     */
    public static function importsql($name, $force = false)
    {
        $sqlFile = self::getAddonDir($name) . ".database";
        if (is_file($sqlFile)) {
            $lines = file($sqlFile,FILE_IGNORE_NEW_LINES);//把文件读入一个数组中
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }
                if($force){
                    //卸载数据
                    //如果是创建数据表语句
                    if(strpos($line, 'CREATE TABLE') !== false){
                        $templine = '';
                        $strArray = explode('`', $line);
                        $templine = str_ireplace('__PREFIX__', Config::get('database.connections.mysql.prefix'), trim($strArray[1]));
                        $templine = 'DROP TABLE IF EXISTS `' . $templine . '`;';
                        try {
                            Db::getPdo()->exec($templine);
                        } catch (\PDOException $e) {
                            //$e->getMessage();
                        }
                    }
                    continue; 
                }else{
                    //导入数据
                    $templine .= $line;
                    if (substr(trim($line), -1, 1) == ';') {
                        $templine = str_ireplace('__PREFIX__', Config::get('database.connections.mysql.prefix'), $templine);
                        $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                        try {
                            Db::getPdo()->exec($templine);
                        } catch (\PDOException $e) {
                            //$e->getMessage();
                        }
                        $templine = '';
                    }
                }
            }
        }
        return true;
    }
    /**
     * 匹配配置文件中info信息
     * @param ZipFile $zip
     * @return array|false
     * @throws Exception
     */
    protected static function getInfoIni($zip)
    {
        $config = [];
        // 读取插件信息
        try {
            $info = $zip->getEntryContents('local.ini');
            $config = parse_ini_string($info);
        } catch (ZipException $e) {
            return false;
        }
        return $config;
    }
    /**
     * 验证压缩包、依赖验证
     * @param array $params
     * @return bool
     * @throws Exception
     */
    public static function valid($params = [])
    {
        $client = self::getClient();
        $multipart = [];
        foreach ($params as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        try {
            $response = $client->post('/api/addon/valid', ['multipart' => $multipart]);
            $content = $response->getBody()->getContents();
        } catch (TransferException $e) {
            return ['msg' => '验证网络请求错误'];
        }
        $json = (array)json_decode($content, true);
        if($json['code'] != 0) {
            return ['msg' => $json['msg'] ?? "不合法的离线安装包"];
        }
        return $json['data'][0];
    }
    
    /**
     * 备份插件
     * @param string $name 插件名称
     * @return string
     * @throws Exception
     */
    public static function backup($name)
    {
        $addonsBackupDir = self::getAddonsBackupDir();
        $file = $addonsBackupDir . $name . '-backup-' . date("YmdHis") . '.zip';
        $zipFile = new ZipFile();
        try {
            $zipFile->addDirRecursive(self::getAddonDir($name))
                ->saveAsFile($file)
                ->close();
        } catch (ZipException $e) {
            return false;
        } finally {
            $zipFile->close();
        }
        return $file;
    }
    /**
     * 刷新插件缓存文件
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
    {
        Cache::delete("addons");
        Cache::delete("hooks");
        return true;
    }
    /**
     * 是否有冲突
     * @param string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new AddonException(__("Conflicting file found"), -3, ['conflictlist' => $list]);
        }
        return true;
    }
    /**
     * 获取指定插件的目录
     */
    public static function getAddonDir($name)
    {
        $dir = app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR;
        return $dir;
    }
    /**
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $dir = runtime_path() . 'addons' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
    
    /**
     * 获取插件安装路径
     * @return string
    public function getAddonsPath()
    {
        // 初始化插件目录
        $addons_path = app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($addons_path)) {
            @mkdir($addons_path, 0777, true);
        }
        return $addons_path;
    }
	*/
	
    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return Config::get('app.hemaphp.api_url');
    }
    
    /**
     * 获取请求对象
     * @return Client
     */
    protected static function getClient()
    {
        $options = [
            'base_uri'        => self::getServerUrl(),
            'timeout'         => 30,
            'connect_timeout' => 30,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
                'Referer'          => dirname(app()->request->root(true)),
                'User-Agent'       => 'hemaPHP',
            ]
        ];
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }
        return $client;
    }
    
     
    /**
     * 获取插件的配置信息
     * @param string $name
     * @return array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getConfig();
    }
    
    public function register()
    {
        $this->addons_path = app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR;
        // 加载系统语言包
        Lang::load([
            root_path() . '/vendor/hemaphp/think-addons/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }
    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 路由脚本
            $execute = '\\think\\addons\\Route::execute';
            // 注册插件公共中间件
            if (is_file(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . 'middleware.php')) {
                $this->app->middleware->import(include app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . 'middleware.php', 'route');
            }
            // 注册控制器路由
            $route->rule("addons/:addon/[:controller]/[:action]", $execute)->middleware(Addons::class);
            // 自定义路由
            $routes = (array) Config::get('addons.route', []);
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addons'        => $addon,
                            'controller'    => $controller,
                            'action'        => $action,
                            'indomain'      => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addons' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
        });
    }
    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array) $values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }
    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->addons_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->addons_path . $name)) {
                continue;
            }
            $addonDir = $this->addons_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }
            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }
            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }
    /**
     * 自动载入插件
     * @return bool
     */
    private function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob(app()->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR  . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            // 找到插件入口文件
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        Config::set($config, 'addons');
    }
}
