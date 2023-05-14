<?php
namespace think\addons;

/**
 * 控制器基类
 */
class Controller extends \app\BaseController
{
    protected $controller = '';//当前控制器名称
    protected $action = '';//当前方法名称
    protected $routeUri = '';//当前路由uri
    protected $group = '';//当前路由：分组名称

    /* 登录验证白名单 */
    protected $allowAllAction = [];

    /**
     * 后台初始化
     */
    public function initialize()
    { 
        $this->getRouteInfo();// 当前路由信息
    }
    /**
     * 解析当前路由参数 （分组名称、控制器名称、方法名）
     */
    protected function getRouteInfo()
    {
        $this->controller = uncamelize($this->request->controller());// 控制器名称
        $this->action = $this->request->action();// 方法名称
        $this->routeUri = "{$this->controller}/$this->action";// 当前uri
        // 验证当前请求是否在白名单
        if (in_array($this->routeUri, $this->allowAllAction)) {
            return true;
        }
    }
    
    /**
     * 返回封装后的 API 数据到客户端
     */
    protected function renderJson($code = 1, string $msg = '', string $url = '', array $data = [])
    {
        return json(compact('code', 'msg', 'url', 'data'));
    }

    /**
     * 返回操作成功json
     */
    protected function renderSuccess(string $msg = 'success', string $url = '', array $data = [])
    {
        return $this->renderJson(1, $msg, $url, $data);
    }

    /**
     * 返回操作失败json
     */
    protected function renderError(string $msg = 'error', string $url = '', array $data = [])
    {
        return $this->renderJson(0, $msg, $url, $data);
    }

    /**
     * 获取post数据 (数组)
     * @param $key
     * @return mixed
     */
    protected function postData($key = null)
    {
        return $this->request->post(empty($key) ? '' : "{$key}/a");
    }

    /**
     * 获取post数据 (数组)
     * @param $key
     * @return mixed
     */
    protected function postForm($key = 'form')
    {
        return $this->postData($key);
    }

    /**
     * 获取post数据 (数组)
     * @param $key
     * @return mixed
     */
    protected function getData($key = null)
    {
        return $this->request->get(is_null($key) ? '' : $key);
    }
}