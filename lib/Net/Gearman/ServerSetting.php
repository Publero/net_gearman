<?php
namespace Net\Gearman;

interface ServerSetting
{
    /**
     * @return string[]
     */
    public function getServers();

    /**
     * @param string $host
     * @param int $port
     * @throws \InvalidArgumentException
     * @return self
     */
    public function addServer($host = null , $port = null);

    /**
     * @param string[] $servers
     * @throws \InvalidArgumentException
     * @return self
     */
    public function addServers(array $servers);
}
