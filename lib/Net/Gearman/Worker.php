<?php
namespace Net\Gearman;

/**
 * Interface for Danga's Gearman job scheduling system
 *
 * PHP version 5.3.0+
 *
 * LICENSE: This source file is subject to the New BSD license that is
 * available through the world-wide-web at the following URI:
 * http://www.opensource.org/licenses/bsd-license.php. If you did not receive
 * a copy of the New BSD License and are unable to obtain it through the web,
 * please send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category  Net
 * @package   Net_Gearman
 * @author    Joe Stump <joe@joestump.net>
 * @copyright 2007-2008 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version   CVS: $Id$
 * @link      http://pear.php.net/package/Net_Gearman
 * @link      http://www.danga.com/gearman/
 */

/**
 * Gearman worker class
 *
 * Run an instance of a worker to listen for jobs. It then manages the running
 * of jobs, etc.
 *
 * @category  Net
 * @package   Net_Gearman
 * @author    Joe Stump <joe@joestump.net>
 * @copyright 2007-2008 Digg.com, Inc.
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version   Release: @package_version@
 * @link      http://www.danga.com/gearman/
 * @see       Net\Gearman\Job, Net\Gearman\Connection
 */
class Worker implements ServerSetting
{
    /**
     * @var string $id Unique id for this worker
     */
    protected $id;

    /**
     * @var array $connection Pool of connections to Gearman servers
     */
    protected $connection = array();

    /**
     * @var array $conn Pool of retry connections
     */
    protected $retryConn = array();

    /**
     * @var array[] $functions
     */
    protected $functions = array();

    /**
     * @var string[] List of gearman servers
     */
    protected $servers = array();

    /**
     * Callbacks registered for this worker
     *
     * @var array $callback
     * @see Net\Gearman\Worker::JOB_START
     * @see Net\Gearman\Worker::JOB_COMPLETE
     * @see Net\Gearman\Worker::JOB_FAIL
     */
    protected $callback = array(
        self::JOB_START     => array(),
        self::JOB_COMPLETE  => array(),
        self::JOB_FAIL      => array()
    );

    /**
     * Callback types
     *
     * @const integer JOB_START    Ran when a job is started
     * @const integer JOB_COMPLETE Ran when a job is finished
     * @const integer JOB_FAIL     Ran when a job fails
     */
    const JOB_START    = 1;
    const JOB_COMPLETE = 2;
    const JOB_FAIL     = 3;

    /**
     * @param string $id Optional unique id for this worker
     */
    public function __construct($id = null)
    {
        if(null === $id){
            $id = "pid_".getmypid()."_".uniqid();
        }

        $this->id = $id;
    }

    public function getServers()
    {
        return array_keys($this->servers);
    }

    public function addServers(array $servers)
    {
        foreach ($servers as $server) {
            $explodedServer = explode(':', $server);
            $port = isset($explodedServer[1]) ? $explodedServer[1] : null;

            $this->addServer($explodedServer[0], $port);
        }

        return $this;
    }

    public function addServer($host = null , $port = null)
    {
        if (null === $host) {
            $host = 'localhost';
        } else {
            $host = trim($host);
        }

        if (empty($host)) {
            throw new \InvalidArgumentException("Invalid host '$host' given");
        }

        if (null === $port) {
            $port = $this->getDefaultPort();
        } elseif (empty($port)) {
            throw new \InvalidArgumentException("Invalid port '$port' given");
        }

        $server = $host . ':' . $port;

        if (isset($this->servers[$server])) {
            throw new \InvalidArgumentException("Server '$server' is already registered");
        }

        $this->servers[$server] = true;

        return $this;
    }

    /**
     * @return int
     */
    protected function getDefaultPort()
    {
        return 4730;
    }

    /**
     * @return string[]
     */
    public function getFunctions()
    {
        return $this->functions;
    }

    /**
     * @param string $functionName
     * @param callback $callback
     * @param int $timeout
     * @throws \InvalidArgumentException
     * @return self
     */
    public function addFunction($functionName, $callback, $timeout = null)
    {
        if (isset($this->functions[$functionName])) {
            throw new \InvalidArgumentException("Function $functionName is already registered");
        }

        $this->functions[$functionName] = array('callback' => $callback);
        if (null !== $timeout) {
            $this->functions[$functionName]['timeout'] = $timeout;
        }

        return $this;
    }

    /**
     * @param string
     * @throws \InvalidArgumentException
     * @return self
     */
    public function unregister($functionName)
    {
        if (!isset($this->functions[$functionName])) {
            throw new \InvalidArgumentException("Function $functionName is not registered");
        }

        unset($this->functions[$functionName]);

        return $this;
    }

    /**
     * @return self
     */
    public function unregisterAll()
    {
        $this->functions = array();

        return $this;
    }

    /**
     * @throws Exception
     * @return bool
     */
    public function work($monitor = null)
    {
        $this->connectToAllServers();
        $this->registerFunctionsToOpenedConnections();

        if (!is_callable($monitor)) {
            $monitor = array($this, 'stopWork');
        }

        $write     = null;
        $except    = null;
        $working   = true;
        $lastJob   = time();
        $retryTime = 5;

        while ($working) {
            $sleep = true;
            $currentTime = time();

            foreach ($this->connection as $server => $socket) {
                $worked = false;
                try {
                    $worked = $this->doWork($socket);
                } catch (\Exception $e) {
                    unset($this->connection[$server]);
                    file_put_contents('/home/hlavac/result.txt', $e->getMessage() . "\n", FILE_APPEND);
                    $this->retryConn[$server] = $currentTime;
                }
                if ($worked) {
                    $lastJob = time();
                    $sleep   = false;
                }
            }

            $idle = false;
            if ($sleep && count($this->connection)) {
                foreach ($this->connection as $socket) {
                    Connection::send($socket, 'pre_sleep');
                }

                $read = $this->connection;
                socket_select($read, $write, $except, 60);
                $idle = (count($read) == 0);
            }

            $retryChange = false;
            foreach ($this->retryConn as $s => $lastTry) {
                if (($lastTry + $retryTime) < $currentTime) {
                    try {
                        $conn = Connection::connect($s);
                        $this->connection[$s]   = $conn;
                        $retryChange            = true;
                        unset($this->retryConn[$s]);
                        Connection::send($conn, "set_client_id", array("client_id" => $this->id));
                    } catch (Exception $e) {
                        $this->retryConn[$s] = $currentTime;
                    }
                }
            }

            if (count($this->connection) == 0) {
                // sleep to avoid wasted cpu cycles if no connections to block on using socket_select
                sleep(1);
            }

            if ($retryChange === true) {
                $this->registerFunctionsToOpenedConnections();
            }

            if (call_user_func($monitor, $idle, $lastJob) == true) {
                $working = false;
            }
        }

    }

    private function connectToAllServers()
    {
        foreach ($this->getServers() as $server) {
            try {
                $connection = Connection::connect($server);
                Connection::send($connection, "set_client_id", array("client_id" => $this->id));
                $this->connection[$server] = $connection;
            } catch (\Exception $exception) {
                $this->retryConn[$server] = time();
            }
        }

        if (empty($this->connection)) {
            throw new Exception("Couldn't connect to any available servers");
        }
    }

    public function registerFunctionsToOpenedConnections()
    {
        foreach (array_keys($this->functions) as $gearmanFunction) {
            $params = array('func' => $gearmanFunction);
            $call = isset($params['timeout']) ? 'can_do_timeout' : 'can_do';

            foreach ($this->connection as $connection) {
                Connection::send($connection, $call, $params);
            }
        }
    }

    /**
     * Listen on the socket for work
     *
     * Sends the 'grab_job' command and then listens for either the 'noop' or
     * the 'no_job' command to come back. If the 'job_assign' comes down the
     * pipe then we run that job.
     *
     * @param resource $socket The socket to work on
     *
     * @return boolean Returns true if work was done, false if not
     * @throws Net_Gearman_Exception
     * @see Net_Gearman_Connection::send()
     */
    protected function doWork($socket)
    {
        Connection::send($socket, 'grab_job');

        $resp = array('function' => 'noop');
        while (count($resp) && $resp['function'] == 'noop') {
            $resp = Connection::blockingRead($socket);
        }

        if (in_array($resp['function'], array('noop', 'no_job'))) {
            return false;
        }

        if ($resp['function'] != 'job_assign') {
            throw new Exception('Internal error - Job was not assigned after it was grabbed by this worker');
        }

        $name   = $resp['data']['func'];
        $handle = $resp['data']['handle'];
        $arg    = array();

        if (isset($resp['data']['arg']) &&
            Connection::stringLength($resp['data']['arg'])) {
            $arg = json_decode($resp['data']['arg'], true);
            if($arg === null){
                $arg = $resp['data']['arg'];
            }
        }

        try {
            $this->callStartCallbacks($handle, $name, $arg);

            $functionCallback = $this->functions[$name]['callback'];
            $result = call_user_func($functionCallback, $arg);

            $this->jobComplete($socket, $handle, $result);
            $this->callCompleteCallbacks($handle, $name, $result);
        } catch (JobException $e) {
            $this->jobFail($socket, $handle);
            $this->callFailCallbacks($handle, $name, $e);
        }

        // Force the job's destructor to run
        $job = null;

        return true;
    }

    /**
     * Update Gearman with your job's status
     *
     * @param integer $numerator   The numerator (e.g. 1)
     * @param integer $denominator The denominator  (e.g. 100)
     *
     * @see Net\Gearman\Connection::send()
     */
    public function jobStatus($numerator, $denominator)
    {
        Connection::send($this->conn, 'work_status', array(
            'handle' => $this->handle,
            'numerator' => $numerator,
            'denominator' => $denominator
        ));
    }

    /**
     * Mark your job as complete with its status
     *
     * @param resource $socket
     * @param string $handle
     * @param array $result Result of your job
     *
     * @see Net\Gearman\Connection::send()
     */
    private function jobComplete($socket, $handle, $result)
    {
        Connection::send($socket, 'work_complete', array(
            'handle' => $handle,
            'result' => $result
        ));
    }

    /**
     * Mark your job as failing
     *
     * If your job fails for some reason (e.g. a query fails) you need to run
     * this function and exit from your run() method. This will tell Gearman
     * (and the client by proxy) that the job has failed.
     *
     * @param resource $socket
     * @param string $handle
     *
     * @see Net\Gearman\Connection::send()
     */
    private function jobFail($socket, $handle)
    {
        Connection::send($socket, 'work_fail', array(
            'handle' => $handle
        ));
    }

    /**
     * @param callback $callback
     * @param int      $type
     *
     * @throws Net\Gearman\Exception When an invalid callback is specified.
     * @throws Net\Gearman\Exception When an invalid type is specified.
     */
    public function attachCallback($callback, $type = self::JOB_COMPLETE)
    {
        if (!is_callable($callback)) {
            throw new Exception('Invalid callback specified');
        }
        if (!isset($this->callback[$type])) {
            throw new Exception('Invalid callback type specified.');
        }
        $this->callback[$type][] = $callback;
    }

    /**
     * @param string $handle The job's Gearman handle
     * @param string $job    The name of the job
     * @param mixed  $args   The job's argument list
     */
    protected function callStartCallbacks($handle, $job, $args)
    {
        foreach ($this->callback[self::JOB_START] as $callback) {
            call_user_func($callback, $handle, $job, $args);
        }
    }

    /**
     * @param string $handle The job's Gearman handle
     * @param string $job    The name of the job
     * @param string $result The job's returned result
     */
    protected function callCompleteCallbacks($handle, $job, $result)
    {
        foreach ($this->callback[self::JOB_COMPLETE] as $callback) {
            call_user_func($callback, $handle, $job, $result);
        }
    }

    /**
     * @param string $handle The job's Gearman handle
     * @param string $job    The name of the job
     * @param object $error  The exception thrown
     */
    protected function callFailCallbacks($handle, $job, PEAR_Exception $error)
    {
        foreach ($this->callback[self::JOB_FAIL] as $callback) {
            call_user_func($callback, $handle, $job, $error);
        }
    }

    public function __destruct()
    {
        $this->endWork();
    }

    public function endWork()
    {
        foreach ($this->connection as $conn) {
            Connection::close($conn);
        }
    }

    /**
     * Should we stop work?
     *
     * @return boolean
     */
    public function stopWork()
    {
        return false;
    }
}
