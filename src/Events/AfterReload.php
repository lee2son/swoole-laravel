<?php

namespace Flysion\Swoolaravel\Events;

/**
 * Worker 进程 Reload 之后触发此事件，在 Manager 进程中回调
 *
 * @link https://wiki.swoole.com/#/server/events?id=onafterreload
 */
class AfterReload
{
    /**
     * @var \Swoole\Server|\Swoole\Http\Server|\Swoole\WebSocket\Server
     */
    public $server;

    /**
     * @param \Swoole\Server|\Swoole\Http\Server|\Swoole\WebSocket\Server
     */
    public function __construct($server)
    {
        $this->server = $server;
    }
}