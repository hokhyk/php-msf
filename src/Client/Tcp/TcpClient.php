<?php
/**
 * @desc: 协程Tcp客户端
 * @author: leandre <niulingyun@camera360.com>
 * @date: 2017/3/21
 * @copyright All rights reserved.
 */

namespace PG\MSF\Client\Tcp;

use PG\MSF\{
    Base\Exception, Helpers\Context, Pack\IPack, Coroutine\TcpClientRequest
};

class TcpClient
{
    /**
     * @var Context
     */
    public $context;

    /**
     * @var \swoole_client
     */
    public $client;
    /**
     * @var IPack
     */
    protected $pack;
    protected $packageLengthTypeLength;
    private $ip;
    private $port;
    private $timeOut;

    public function __construct(\swoole_client $client, $ip, $port, $timeOut)
    {
        $this->client = $client;
        $this->ip = $ip;
        $this->port = $port;
        $this->timeOut = $timeOut * 1000;

        $this->set = getInstance()->config->get('tcpClient.set', []);
        $packTool = getInstance()->config->get('tcpClient.pack_tool', 'JsonPack');

        $this->packageLengthTypeLength = strlen(pack($this->set['package_length_type'], 1));
        //pack class
        $pack_class_name = "\\App\\Pack\\" . $packTool;
        if (class_exists($pack_class_name)) {
            $this->pack = new $pack_class_name;
        } else {
            $pack_class_name = "\\PG\\MSF\\Pack\\" . $packTool;
            if (class_exists($pack_class_name)) {
                $this->pack = new $pack_class_name;
            } else {
                throw new Exception("class {$packTool} is not exist.");
            }
        }
    }


    public function coroutineSend($data)
    {
        if (!array_key_exists('path', $data)) {
            throw new Exception('tcp data must has path');
        }

        $path = $data['path'];
        $data['logId'] = $this->context->PGLog->logId;
        $data = $this->encode($this->pack->pack($data));
        return new TcpClientRequest($this, $data, $path, $this->timeOut);
    }

    private function encode($buffer)
    {
        if ($this->set['open_length_check']??0 == 1) {
            $total_length = $this->packageLengthTypeLength + strlen($buffer) - $this->set['package_body_offset'];
            return pack($this->set['package_length_type'], $total_length) . $buffer;
        } else {
            if ($this->set['open_eof_check']??0 == 1) {
                return $buffer . $this->set['package_eof'];
            } else {
                throw new Exception("tcpClient won't support set");
            }
        }
    }

    public function send($data, $callback)
    {
        $this->client->on('connect', function ($cli) use ($data) {
            $cli->send($data);
        });

        $this->client->on('receive', function ($cli, $recData) use ($callback) {
            $recData = $this->pack->unPack($this->decode($recData));
            if ($callback != null) {
                call_user_func($callback, $cli, $recData);
            }
        });

        $this->connect();
    }

    private function decode($buffer)
    {
        if ($this->set['open_length_check']??0 == 1) {
            $data = substr($buffer, $this->packageLengthTypeLength);
            return $data;
        } else {
            if ($this->set['open_eof_check']??0 == 1) {
                $data = $buffer;
                return $data;
            }
        }
    }

    private function connect()
    {
        $this->client->connect($this->ip, $this->port, $this->timeOut);
    }
}