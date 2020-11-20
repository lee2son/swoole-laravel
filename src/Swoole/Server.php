<?php

namespace Flysion\Swoolaravel\Swoole;

use Flysion\Swoolaravel\Events\ManagerStart;
use Flysion\Swoolaravel\Events\Start;
use Flysion\Swoolaravel\Events\WorkerStart;

/**
 * 事件执行顺序：
 *  1.所有事件回调均在 $server->start 后发生
 *  2.服务器关闭程序终止时最后一次事件是 onShutdown
 *  3.服务器启动成功后，onStart/onManagerStart/onWorkerStart 会在不同的进程内并发执行
 *  4.onReceive/onConnect/onClose 在 Worker 进程中触发
 *  5.Worker/Task 进程启动 / 结束时会分别调用一次 onWorkerStart/onWorkerStop
 *  6.onTask 事件仅在 task 进程中发生
 *  7.onFinish 事件仅在 worker 进程中发生
 *  8.onStart/onManagerStart/onWorkerStart 3 个事件的执行顺序是不确定的
 *
 * @link https://wiki.swoole.com/#/server/tcp_init
 * @link https://wiki.swoole.com/#/server/events?id=%e4%ba%8b%e4%bb%b6%e6%89%a7%e8%a1%8c%e9%a1%ba%e5%ba%8f 事件执行顺序
 * @mixin \Swoole\Server
 */
class Server
{
    /**
     * @var \Illuminate\Events\Dispatcher
     */
    private $events;

    /**
     * @var \Swoole\Server
     */
    private $swooleServer;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var int
     */
    private $mode;

    /**
     * @var int
     */
    private $sockType;

    /**
     * @param \Illuminate\Events\Dispatcher|null $events
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $sockType
     */
    public function __construct($host, $port = 0, $mode = SWOOLE_PROCESS, $sockType = SWOOLE_SOCK_TCP, $events = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->mode = $mode;
        $this->sockType = $sockType;
        $this->events = $events ?? app('events');
    }

    /**
     * @return \Illuminate\Events\Dispatcher
     */
    public function events()
    {
        return $this->events;
    }

    /**
     * 创建一个 swoole server
     *
     * @param string $host
     * @param int $port
     * @param int $mode
     * @param int $sockType
     * @return \Swoole\Server
     */
    public static function create($host, $port = 0, $mode = SWOOLE_PROCESS, $sockType = SWOOLE_SOCK_TCP)
    {
        return new \Swoole\Server($host, $port, $mode, $sockType);
    }

    /**
     * @return \Swoole\Server|\Swoole\Http\Server|\Swoole\WebSocket\Server
     */
    public function swooleServer()
    {
        if(!$this->swooleServer) {
            $this->swooleServer = static::create($this->host, $this->port, $this->mode, $this->sockType);
        }

        return $this->swooleServer;
    }

    /**
     * 将 http 请求转发到 laravel 框架
     * 必须打开 swooler server 的 open_http_protocol 选项
     */
    public function enableHttpRequestToLaravel()
    {
        $this->on(\Flysion\Swoolaravel\Events\Request::class, \Flysion\Swoolaravel\Listeners\RequestToLaravel::class);
    }

    /**
     * 设置进程名称
     * 仅仅是注册一个监听进程启动事件，在事件中设置进程名称
     *
     * @param string $prefix
     */
    public function setProcessName($prefix)
    {
        $this->on(Start::before, function($server, Start $event) use($prefix) {
            \swoole_set_process_name("{$prefix}master-{$event->server->master_pid}");
        });

        $this->on(ManagerStart::before, function($server, ManagerStart $event) use($prefix) {
            \swoole_set_process_name("{$prefix}manager-{$event->server->manager_pid}");
        });

        $this->on(WorkerStart::before, function($server, WorkerStart $event) use($prefix) {
            if($event->server->taskworker) {
                \swoole_set_process_name("{$prefix}taskworker-{$event->server->worker_pid}-{$event->workerId}");
            } else {
                \swoole_set_process_name("{$prefix}worker-{$event->server->worker_pid}-{$event->workerId}");
            }
        });
    }

    /**
     * @param string $eventName
     * @param  \Closure[]|string[]  $listener
     */
    public function on($eventName, ...$callbacks)
    {
        $eventClass = strpos($eventName, ':') > 0 ? explode(':', $eventName, 2)[0] : $eventName;

        if(!$this->events()->hasListeners($eventClass))
        {
            $this->registerSwooleServerEvent($eventClass);
        }

        foreach($callbacks as $callback)
        {
            $this->events()->listen($eventClass, $callback);
        }
    }

    /**
     * @param string $eventClass
     */
    protected function registerSwooleServerEvent($eventClass)
    {
        $this->swooleServer()->on($eventClass::name, function(...$arguments) use($eventClass){
            $event = new $eventClass(...$arguments);

            $this->onBefore($eventClass, $event);
            $this->events()->dispatch($eventClass, [$this, $event]);
            $this->onAfter($eventClass, $event);
        });
    }

    /**
     * @param string $eventClass
     * @param \Flysion\Swoolaravel\Events\SwooleEvent $event
     * @throws
     */
    final protected function onBefore($eventClass, $event)
    {
        // 内置 before

        $before = \Illuminate\Support\Str::camel('on_before_' . $eventClass::name);

        if(method_exists($this, $before))
        {
            $this->$before($event);
        }

        // 用户 before

        $this->events()->dispatch($eventClass::before, $event);
    }

    /**
     * @param string $eventClass
     * @param \Flysion\Swoolaravel\Events\SwooleEvent $event
     * @throws
     */
    final protected function onAfter($eventClass, $event)
    {
        // 内置 after

        $after = \Illuminate\Support\Str::camel('on_after_' . $eventClass::name);

        if(method_exists($this, $after))
        {
            $this->$after($event);
        }

        // 用户 after

        $this->events()->dispatch($eventClass::after, $event);
    }

    /**
     * @return mixed
     * @throws
     */
    public function start($setting = [])
    {
        $this->events()->dispatch(\Flysion\Swoolaravel\Events\Ready::class);
        $this->swooleServer()->set(array_merge($this->defaultSettings ?? [], $setting, $this->swooleServer()->setting ?? []));
        return $this->swooleServer()->start();
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->swooleServer()->$name(...$arguments);
    }
}