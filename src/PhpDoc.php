<?php


namespace xtzero_me\PhpDoc;


use App\HttpController\Router;
use Base\Exception\Exception;
use EasySwoole\EasySwoole\Config;
use EasySwoole\Http\Response;

class PhpDoc
{
    private static $routerFilePath = EASYSWOOLE_ROOT . '/App/HttpController/Router.php';
    private static $phpdocPath = EASYSWOOLE_ROOT . '/vendor/xtzero_me/phpdoc-easyswoole-fastroute/src';

    public static function getDocHtml(Response $response)
    {
        if (ENVIRONMENT !== 'development') {
            self::display($response, "");
        } else {
            $allRoutes = self::getAllRoutes();
            $html = self::C(self::$phpdocPath . "/index.html", [
                "title" => "接口文档",
                "baseHost" => Config::getInstance()->getConf('BASE_HOST'),
                "routes" => implode('', array_map(function($group) {
                    return self::C(self::$phpdocPath . '/routeGroup.html', [
                        'groupName' => $group['group'],
                        'routes' => implode('', array_map(function($route) {
                            return self::C(self::$phpdocPath . '/route.html', [
                                'method' => $route['method'],
                                'route' => $route['url'],
                                'group' => $route['group'],
                                'function' => $route['function'],
                                'apiname' => $route['apiname'] ?: "{$route['group']}{$route['url']}",
                                'apidesc' => $route['apidesc'],
                                'params' => implode('', array_map(function($param) {
                                    return self::C(self::$phpdocPath . '/param.html', [
                                        "name" => $param['name'],
                                        "rule_desc" => $param['rule']['desc'] ?? '',
                                        "rule_require" => $param['rule']['require'] == 1 ? "是" : "否",
                                        "rule_type" => $param['rule']['type'] ?? '',
                                        "rule_enum" => implode(',', $param['rule']['enum'] ?? []),
                                    ]);
                                }, $route['params'])),
                            ]);
                        }, $group['routes'] ?? []))
                    ]);
                }, $allRoutes ?? [])),
                "columns" => implode("", array_map(function($route) {
                    return self::C(self::$phpdocPath . '/columnItem.html', [
                        'name' => implode(" ", [$route['group'], $route['apiname'] ?: $route['url']]),
                        'id' => "{$route['group']}{$route['url']}"
                    ]);
                }, (function () use($allRoutes){
                    $_allRoutes = [];
                    foreach ($allRoutes as $v) {
                        foreach ($v['routes'] as $vv) {
                            array_push($_allRoutes, $vv);
                        }
                    }
                    return $_allRoutes;
                })())),
                "json" => ''
            ]);
            self::display($response, $html);
        }
    }

    /**
     * 解析Route文件，使用反射获取参数列表，获取全部路由及参数
     * @return array
     */
    public static function getAllRoutes()
    {
        $routeFileContent = file_get_contents(self::$routerFilePath);
        $routeFileContentArr = [];

        $currentGroup = "/";
        foreach (explode("\n", $routeFileContent) as $line) {
            if (stripos($line, 'addGroup') !== false) {
                preg_match_all('/addGroup\((.*), function/', $line, $matchGroup);
                $currentGroup = str_replace(["\""], "", $matchGroup[1][0]);
            } if (stripos($line, '$route->get') !== false || stripos($line, '$route->post') !== false) {
                preg_match_all('/\$route->(post|get)((.*), (.*));/', $line, $matchRoute);
                $method = $matchRoute[1][0] ?? '';
                $url = str_replace(["(", "'", '"'], '', $matchRoute[3][0] ?? '');
                $function = str_replace(['"', ")", "'"], '', $matchRoute[4][0] ?? '');
                $group = $currentGroup;
                $params = [];
                try {
                    // 使用反射解析参数
                    $path = $function;
                    $pathArr = explode('/', $path);
                    $pathArrWithoutFunction = $pathArr;
                    $functionName = $pathArrWithoutFunction[count($pathArrWithoutFunction) - 1];
                    unset($pathArrWithoutFunction[count($pathArrWithoutFunction) - 1]);
                    $controllerName = 'App\\HttpController\\' . implode('\\', $pathArrWithoutFunction);
                    $ref = new \ReflectionClass($controllerName);
                    $methodRef = $ref->getMethod($functionName);
                    $doc = $methodRef->getDocComment();
                    foreach (explode("\n", $doc) as $ruleLine) {
                        if (stripos($ruleLine, "Param") !== false) {
                            $_a = strpos($ruleLine, '@Param') + 6;
                            $_b = strpos($ruleLine, "{");
                            // 参数名
                            $_name = trim(substr($ruleLine, $_a, $_b - $_a));
                            // 规则值
                            $_value = substr($ruleLine, $_b);
                            // 规则数组
                            $_rule = json_decode($_value, true) ?? [];

                            $params[] = [
                                'name' => $_name ?? '',
                                'rule' => $_rule ?? '',
                            ];
                        }

                        if (stripos($ruleLine, "Apiname") !== false) {
                            $_apiname = str_replace(["@Apiname", "*", " "], "", $ruleLine) ?: '';
                        }

                        if (stripos($ruleLine, "Apidesc") !== false) {
                            $_apidesc = str_replace(["@Apidesc", "*", " "], "", $ruleLine) ?: '';
                        }
                    }
                } catch (\Throwable $e) {
                    $params = [];
                }

                if ($method && $url && $function) {
                    $routeFileContentArr[$currentGroup][] = [
                        'apiname' => $_apiname ?? '',
                        'apidesc' => $_apidesc ? $_apidesc : (empty($params) ? "接口注释：".$doc : ''),
                        "method" => $method,
                        "url" => $url,
                        "group" => $group,
                        "function" => $function,
                        "params" => $params
                    ];
                    unset($_apiname);
                    unset($_apidesc);
                    unset($method);
                    unset($url);
                    unset($function);
                    unset($params);
                }
            }
        }

        $resArr = [];
        foreach ($routeFileContentArr as $k => $v) {
            $resArr[] = [
                'group' => $k,
                'routes' => $v
            ];
        }
        return array_values($resArr);
    }

    /**
     * 模板变量替换
     * @param $templateFilePath
     * @param $data
     * @return false|string|string[]
     */
    public static function C($templateFilePath, $data)
    {
        if (file_exists($templateFilePath)) {
            $content = file_get_contents($templateFilePath);
            foreach ($data as $k => $v) {
                $content = str_replace('{{'. $k .'}}', $v, $content);
            }
            return $content;
        } else {
            return "";
        }
    }

    public static function display(Response $response, $html)
    {
        $response->write($html);
        $response->withHeader('Content-type', 'text/html; charset=UTF-8');
        $response->withStatus(200);
    }

    /**
     * @param Response $response
     * @return void
     */
    public static function getYapiJson(Response $response): void
    {
        $allRoutes = self::getAllRoutes();
        $resArr = [];
        foreach ($allRoutes as $groupK => $groupV) {
            $_group = [
                'index' => $groupK,
                'name' => $groupV['group'],
                'desc' => $groupV['group'],
                'add_time' => time(),
                'up_time' => time(),
                'list' => []
            ];
            $_list = [];
            foreach ($groupV['routes'] as $routeK => $routeV) {
                $params = array_map(function($param) {
                    return [
                        'required' => $param['rule']['require'] == true ? true : false,
                        '_id' => md5($param['name']),
                        'name' => $param['name'],
                        "type" => $param['rule']['type'],
                        "example" => "",
                        "desc" => json_encode($param)
                    ];
                }, $routeV['params']);
                $_list[] = [
                    'query_path' => [
                        'path' => "{$groupV['group']}{$routeV['url']}",
                        "params" => []
                    ],
                    'edit_uid' => 0,
                    'status' => 'done',
                    "type" => 'static',
                    'req_body_is_json_schema' => true,
                    'res_body_is_json_schema' => true,
                    'api_opened' => false,
                    'index' => $routeK,
                    'tag' => [],
                    '_id' => rand(100000, 999999),
                    'method' => strtoupper($routeV['method']),
                    'catid' => rand(100000, 999999),
                    'title' => $routeV['apiname'],
                    'path' => "{$groupV['group']}{$routeV['url']}",
                    'project_id' => 64,
                    'req_params' => $routeV['method'] == 'get' ? $params : [],
                    'res_body_type' => 'json',
                    'uid' => 27,
                    'add_time' => time(),
                    'up_time' => time(),
                    'req_query' => [],
                    'req_headers' => [[
                        "required" => "1",
                        "_id" => "6056fe61bd65ca06ffc55c99",
                        "name" => "Content-Type",
                        "value" => "application/x-www-form-urlencoded"
                    ]],
                    'req_body_form' => $routeV['method'] == 'post' ? $params : [],
                    '__v' => 0,
                    'desc' => '',
                    'markdown' => '',
                    'req_body_type' => 'form',
                    'res_body' => '',
                ];
            }
            $_group['list'] = $_list;
            $resArr[] = $_group;
        }
        self::display($response, json_encode($resArr, ));
    }
}
