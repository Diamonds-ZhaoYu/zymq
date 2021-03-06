<?php
// +----------------------------------------------------------------------
// |  redis操作请求
// | 
// +----------------------------------------------------------------------
// | Copyright (c) https://admuch.txbapp.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: zhaoyu <9641354@qq.com>
// +----------------------------------------------------------------------
// | Date: 2019/12/24 2:47 下午
// +----------------------------------------------------------------------

namespace Qk\QingkeMq;


class RedisMq
{
    /**
     * @var \Redis 连接成功时的标识
     */
    public      $_mc;

    /**
     * @var array redis链接参数
     */
    protected $_rdscfg
        = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'pwd'  => '',
            'pre'  => '',
            'dbs'  => '0',
        ];


    /**
     * 预留方法 扩展使用
     *
     * @param string $host 链接地址
     * @param int    $port 端口
     * @param string $pwd  密码
     * @param string $pre  前缀
     * @param int    $dbs  数据库
     * @return RedisMq
     * @throws \Exception
     */
    public static function Factory(string $host = '127.0.0.1', int $port = 6379, string $pwd = '', int $dbs = 0, string $pre = '')
    {
        return new self($host, $port, $pwd, $pre, $dbs);
    }



    /**
     * 初始化对象
     * RedisMq constructor.
     * @param string $host 链接地址
     * @param int    $port 端口
     * @param string $pwd  密码
     * @param string $pre  前缀
     * @param int    $dbs  数据库
     * @throws \Exception
     */
    private function __construct(string $host ,int $port,string $pwd,string $pre ,int $dbs)
    {
        $this->_rdscfg['host'] = $host;
        $this->_rdscfg['port'] = $port;
        $this->_rdscfg['pwd']  = $pwd;
        $this->_rdscfg['pre']  = $pre;
        $this->_rdscfg['dbs']  = $dbs;

        $this->_mc = new \Redis();
        $this->_mc->connect(
            isset($this->_rdscfg['host']) ? $this->_rdscfg['host'] : '127.0.0.1',
            isset($this->_rdscfg['port']) ? $this->_rdscfg['port'] : '6379'
        ) or self::showMsg('[RedisCache:]Could not connect');

        // 校验是否需要密码
        if (isset($this->_rdscfg['pwd']) && $this->_mc->auth($this->_rdscfg['pwd']) == false) {
            self::showMsg('[RedisCache:]' . $this->_mc->getLastError());
        }

        !isset($this->_rdscfg['pre']) && $this->_rdscfg['pre'] = '';
        $this->_mc->select($this->_rdscfg['dbs']);
    }

    /**
     * 与set方法相同
     * 唯一的区别是: 增加对数组序列化功能
     *
     * @param  string $key    数据的标识
     * @param  string $value  实体内容
     * @param  string $expire 过期时间单位秒
     * @return bool
     **/
    public function sets($key, $value, $expire = 0)
    {
        $expire > 0 && $expire = self::setLifeTime($expire);
        return $expire > 0 ? $this->_mc->setex($this->_rdscfg['pre'] . $key, $expire, $value) : $this->_mc->set($this->_rdscfg['pre'] . $key, $value);
    }

    /**
     * 获取数据缓存
     *
     * @param  string $key    数据的标识
     * @return string
     **/
    public function gets($key)
    {
        return $this->_mc->get($this->_rdscfg['pre'] . $key);
    }

    /**
     * 设置数据缓存
     * 与add|replace比较类似
     * 唯一的区别是: 无论key是否存在,是否过期都重新写入数据
     *
     * @param  string $key    数据的标识
     * @param  string $value  实体内容
     * @param  string $expire 过期时间[天d|周w|小时h|分钟i] 如:8d=8天 默认为0永不过期
     * @param  bool   $iszip  是否启用压缩
     * @return bool
     **/
    public function set($key,$value,$expire=0)
    {
        $value = self::rdsCode($value, 1);
        $expire > 0 && $expire = self::setLifeTime($expire);
        return $expire > 0 ? $this->_mc->setex($this->_rdscfg['pre'] . $key, $expire, $value) : $this->_mc->set($this->_rdscfg['pre'] . $key, $value);
    }

    /**
     * 获取数据缓存
     *
     * @param  string $key    数据的标识
     * @return string
     **/
    public function get($key)
    {
        $value = $this->_mc->get($this->_rdscfg['pre'] . $key);
        return $value ? self::rdsCode($value) : $value;
    }

    /**
     * 获取数据集合
     *
     * @param  array $key  数据的标识
     * @param  int   $t    是否清除空记录
     * @return array
     **/
    public function mget(array $key, $t = 0)
    {
        $item = array();
        $_tmp = $this->_mc->getMultiple($key);
        $_k   = 0;//解决键名中带有ID的情况
        foreach ($key as $k => &$v) {
            $v = $_tmp[$_k];
            $v = $v ? self::rdsCode($v) : $v;
            ++$_k;

            if ($t && empty($v)) continue;
            $item[$k] =& $v;
        }
        return $item;
    }

    /**
     * 新增数据缓存
     * 只有当key不存,存在但已过期时被设值
     *
     * @param  string $key    数据的标识
     * @param  string $value  实体内容
     * @param  string $expire 过期时间[天d|周w|小时h|分钟i] 如:8d=8天 默认为0永不过期
     * @param  bool   $iszip  是否启用压缩
     * @return bool   操作成功时返回ture,如果存在返回false否则返回true
     **/
    public function add($key, $value, $expire = 0)
    {
        if ($expire > 0) {
            $expire = self::setLifeTime($expire);
            if ($this->mc->exists($this->_rdscfg['pre'] . $key)) {
                return false;
            } else {
                return $this->set($key, $value, $expire);
            }
        } else {
            $value = self::rdsCode($value, 1);
            return $this->_mc->setnx($this->_rdscfg['pre'] . $key, $value);
        }
    }

    /**
     * 替换数据
     * 与 add|set 参数相同,与set比较类似
     * 唯一的区别是: 只有当key存在且未过期时才能被替换数据
     *
     * @param  string $key    数据的标识
     * @param  string $value  实体内容
     * @param  string $expire 过期时间[天d|周w|小时h|分钟i] 如:8d=8天 默认为0永不过期
     * @param  bool   $iszip  是否启用压缩
     * @return bool
     **/
    public function replace($key, $value, $expire = 0)
    {
        if (self::iskey($key)) {
            return self::set($key, $value, $expire);
        }
        return false;
    }

    /**
     * 检测缓存是否存在
     *
     * @param  string $key 数据的标识
     * @return bool
     **/
    public function isKey($key)
    {
        return $this->_mc->exists($this->_rdscfg['pre'] . $key);
    }

    /**
     * 获得key
     * @param $key 鉴权
     * @return array
     */
    public function getKey($key)
    {
        return $this->_mc->keys($key);
    }

    /**
     * 删除一个数据缓存
     *
     * @param  string $key    数据的标识
     * @param  string $expire 删除的等待时间,好像有问题尽量不要使用
     * @return bool
     **/
    public function del($key)
    {
        return $this->_mc->del($this->_rdscfg['pre'] . $key);
    }

    /**
     * Increment the value of a key
     *
     * @param  string $key    数据的标识
     * @return bool
     **/
    public function incr($key)
    {
        return $this->_mc->incr($this->_rdscfg['pre'] . $key);
    }

    /**
     * 设置队列
     * @param string $key
     * @param string $value
     * @return bool|int
     */
    public function rpush($key, $value)
    {
        return $this->_mc->rpush($this->_rdscfg['pre'] . $key, $value);
    }


    /**
     * 格式化过期时间
     * 注意: 限制时间小于2592000=30天内
     *
     * @param string $t 要处理的串
     * @return int
     **/
    private function setLifeTime($t)
    {
        if (!is_numeric($t)) {
            switch (substr($t, -1)) {
                case 'w'://周
                    $t = (int)$t * 7 * 24 * 3600;
                    break;
                case 'd'://天
                    $t = (int)$t * 24 * 3600;
                    break;
                case 'h'://小时
                    $t = (int)$t * 3600;
                    break;
                case 'i'://分钟
                    $t = (int)$t * 60;
                    break;
                default:
                    $t = (int)$t;
                    break;
            }
        }
        $t > 2592000 && $t = 2592000;
        return $t;
    }

    /**
     * 编码解码
     *
     * @param  string $str 串
     * @param  string $tp  类型,1编码0为解码
     * @return array|string
     **/
    private function rdsCode($str, $tp = 0)
    {
        return $tp ? @serialize($str) : @unserialize($str);
    }


    /**
     * 设置异常消息 可以通过try块中捕捉该消息
     * @param $str
     * @throws \Exception
     */
    private function showMsg($str)
    {
        throw new \Exception($str);
    }

}