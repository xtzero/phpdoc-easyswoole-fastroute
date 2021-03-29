# 自动生成文档
在接口对应的控制器方法上添加注释以便生成文档

这是一个注释示例

```php
/**
     * @Apiname 接口名
     * @Apidesc 接口简介
     * @Param 参数名 {参数规则}

     * @Param answer {"require": true, "type": "string", "desc": "回答内容"}
     *
     */
```
+ 如果没有接口名，会用接口url代替
+ 如果没有接口简介，也没有参数，接口简介会用全部注释代替
+ 权限菜单和url从路由表中取
+ 参数规则使用json格式描述，解析时会使用json_decode处理
+ 参数规则支持以下字段
    + require   是否必要。必要时如果没传参会报业务错误
    + type      字段类型。string、int、enum
    + desc      字段描述
    + range     字段类型是enum时会使用range限制取值范围，传值不在范围内会报业务错误
