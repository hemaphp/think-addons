<?php
declare(strict_types=1);

namespace think;

use think\App;
use think\helper\Str;
use think\facade\Config;
use think\facade\View;

abstract class Addons
{
    // app 容器
    protected $app;
    // 请求对象
    protected $request;
    // 当前插件标识
    protected $name;
    // 插件路径
    protected $addon_path;
    // 视图模型
    protected $view;
    // 错误内容
    protected $error;
    // 插件配置
    //protected $addon_config;
    // 插件信息
    //protected $addon_info;
    /**
     * 插件构造函数
     * Addons constructor.
     * @param \think\App $app
     */
    public function __construct(App $app)
    {
        //$app = app();
        $this->app = $app;
        $this->request = $app->request;
        $this->name = $this->getName();
        $this->addon_path = $app->getRootPath() . 'addons'  . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
        //$this->addon_config = "addon_{$this->name}_config";
        //$this->addon_info = "addon_{$this->name}_info";
        $this->view = clone View::engine('Think');
        $this->view->config([
            'view_path' => $this->addon_path . 'view' . DIRECTORY_SEPARATOR
        ]);
        // 控制器初始化
        $this->initialize();
    }
    // 初始化
    protected function initialize()
    {}
    
    /**
     * 获取插件标识
     * @return mixed|null
     */
    final protected function getName()
    {
        $class = get_class($this);
        list(, $name, ) = explode('\\', $class);
        $this->request->addon = $name;
        return $name;
    }
    
    /**
     * 加载模板输出
     * @param string $template
     * @param array $vars           模板文件名
     * @return false|mixed|string   模板输出变量
     * @throws \think\Exception
     */
    protected function fetch($template = '', $vars = [])
    {
        return $this->view->fetch($template, $vars);
    }
    /**
     * 渲染内容输出
     * @access protected
     * @param  string $content 模板内容
     * @param  array  $vars    模板输出变量
     * @return mixed
     */
    protected function display($content = '', $vars = [])
    {
        return $this->view->display($content, $vars);
    }
    /**
     * 模板变量赋值
     * @access protected
     * @param  mixed $name  要显示的模板变量
     * @param  mixed $value 变量的值
     * @return $this
     */
    protected function assign($name, $value = '')
    {
        $this->view->assign([$name => $value]);
        return $this;
    }
    /**
     * 初始化模板引擎
     * @access protected
     * @param  array|string $engine 引擎参数
     * @return $this
     */
    protected function engine($engine)
    {
        $this->view->engine($engine);
        return $this;
    }
    
    /**
     * 插件基础信息
     * @return array
     */
    final public function getInfo()
    {
        $info_file = $this->addon_path . '.info';
        $addon_info = $this->info ?? [];
        if (is_file($info_file)) {
            $info = (array)json_decode(file_get_contents($info_file), true);
            $info['config'] = get_addons_config($info['name']) ? 1 : 0;//是否有参数配置项
            if($addon_info['version'] != $info['version']){
                $info['version'] = $addon_info['version'];
                file_put_contents($info_file, json_encode($info, JSON_UNESCAPED_UNICODE));
            }
        }else{
            $info = $addon_info; 
            $info['config'] = get_addons_config($info['name']) ? 1 : 0;//是否有参数配置项
            file_put_contents($info_file, json_encode($info, JSON_UNESCAPED_UNICODE));
        }
        return isset($info) ? $info : [];
    }
    
    /**
     * 设置插件信息数据
     * @param array $value
     * @return array
     */
    final public function setInfo($value)
    {
        $info_file = $this->addon_path . '.info';
        if (is_file($info_file)) {
            $info = (array)json_decode(file_get_contents($info_file), true);
        }else{
           $info = $this->info ?? []; 
        }
        $info = array_merge($info, $value);
        file_put_contents($info_file, json_encode($info, JSON_UNESCAPED_UNICODE));
        return isset($info) ? $info : [];
    }
    
    /**
     * 获取配置信息
     * @param bool $force 是否获取完整配置
     * @return array|mixed
     */
    final public function getConfig($force = false)
    {
        $config_file = $this->addon_path . '.config';
        if (is_file($config_file)) {
            $config = (array)json_decode(file_get_contents($config_file), true);
        }else{
            $config = [];
            $config_url = $this->addon_path . 'config.php';
            if (is_file($config_url)) {
                $config = (array)include $config_url;
                file_put_contents($config_file, json_encode($config, JSON_UNESCAPED_UNICODE));
            }
        }
        if($force){
            return isset($config) ? $config : [];
        }
        $temp_arr = [];
        foreach ($config as $value) {
            $temp_arr[$value['name']] = $value['value'];
        }
        return $temp_arr;
    }
    
    /**
     * 设置配置数据
     * @param array $value
     * @return array
     */
    final public function setConfig($value)
    {
        $config_file = $this->addon_path . '.config';
        if (is_file($config_file)) {
            $config = (array)json_decode(file_get_contents($config_file), true);
        }else{
            $config = [];
            $config_url = $this->addon_path . 'config.php';
            if (is_file($config_url)) {
                $config = (array)include $config_url;
            }
        }
        for($n=0;$n<sizeof($config);$n++){
            if(isset($value[$config[$n]['name']])){
                $config[$n]['value'] = $value[$config[$n]['name']];
            }
        }
        file_put_contents($config_file, json_encode($config, JSON_UNESCAPED_UNICODE));
        return isset($config) ? $config : [];
    }
    
    /**
     * 设置插件标识
     * @param $name
     */
    final public function setName($name)
    {
        $this->name = $name;
    }
    
    /**
     * 检查基础配置信息是否完整
     * @return bool
     */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'description', 'author', 'version', 'status'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 获取当前错误信息
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }
    //必须实现安装
    abstract public function install();
    //必须卸载插件方法
    abstract public function uninstall();
    
    //必须升级插件方法
    abstract public function upgrade();
    
    //必须启用插件方法
    abstract public function enable();
    
    //必须禁用插件方法
    abstract public function disable();
}
