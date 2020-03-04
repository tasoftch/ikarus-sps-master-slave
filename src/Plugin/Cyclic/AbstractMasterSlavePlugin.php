<?php
/**
 * Copyright (c) 2019 TASoft Applications, Th. Abplanalp <info@tasoft.ch>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Ikarus\SPS\MS\Plugin\Cyclic;


use Ikarus\SPS\Alert\RecoveryAlert;
use Ikarus\SPS\Client\Exception\SocketException;
use Ikarus\SPS\Client\TcpClient;
use Ikarus\SPS\Exception\SPSException;
use Ikarus\SPS\MS\Helper\CyclicPluginManager;
use Ikarus\SPS\Plugin\Cyclic\AbstractCyclicPlugin;
use Ikarus\SPS\Plugin\Management\CyclicPluginManagementInterface;
use Ikarus\SPS\Plugin\Management\PluginManagementInterface;
use Ikarus\SPS\Plugin\SetupPluginInterface;
use Ikarus\SPS\Plugin\TearDownPluginInterface;

abstract class AbstractMasterSlavePlugin extends AbstractCyclicPlugin implements SetupPluginInterface, TearDownPluginInterface
{
    const BROADCAST_PORT = 8686;

    /** @var TcpClient|null */
    protected $tcpClient;

    protected $sharedDomains = [];
    protected $sharedCommands = [];
    protected $shareClearedCommands = true;
    protected $shareQuitAlerts = true;

    /**
     * MasterPlugin constructor.
     * @param string $identifier
     * @param array $sharedDomains
     * @param array $sharedCommands
     */
    public function __construct(string $identifier, array $sharedDomains = [], array $sharedCommands = [])
    {
        if(preg_match("/^\S+$/i", $identifier)) {
            parent::__construct($identifier);
            $this->sharedCommands = $sharedCommands;
            $this->sharedDomains = $sharedDomains;
        } else {
            throw new SPSException("Identifier must not contain whitespace", -78);
        }
    }

    /**
     * @return bool
     */
    public function shareClearedCommands(): bool
    {
        return $this->shareClearedCommands;
    }

    /**
     * @param bool $shareClearedCommands
     * @return static
     */
    public function setShareClearedCommands(bool $shareClearedCommands)
    {
        $this->shareClearedCommands = $shareClearedCommands;
        return $this;
    }

    /**
     * @return bool
     */
    public function shareQuitAlerts(): bool
    {
        return $this->shareQuitAlerts;
    }

    /**
     * @param bool $shareQuitAlerts
     * @return static
     */
    public function setShareQuitAlerts(bool $shareQuitAlerts)
    {
        $this->shareQuitAlerts = $shareQuitAlerts;
        return $this;
    }


    /**
     * @param array $sharedDomains
     * @return static
     */
    public function setSharedDomains(array $sharedDomains)
    {
        $this->sharedDomains = $sharedDomains;
        return $this;
    }

    /**
     * @param array $sharedCommands
     * @return static
     */
    public function setSharedCommands(array $sharedCommands)
    {
        $this->sharedCommands = $sharedCommands;
        return $this;
    }

    /**
     * @return array
     */
    public function getSharedDomains(): array
    {
        return $this->sharedDomains;
    }

    /**
     * @return array
     */
    public function getSharedCommands(): array
    {
        return $this->sharedCommands;
    }

    /**
     * Setting up by broadcasting to an ikarus aware server to obtain the ip address and port of the master slave server.
     */
    public function setup()
    {

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array("sec"=>1, "usec"=>0));
        $MSG = "hello";
        socket_sendto($sock, $MSG, strlen($MSG), MSG_EOR, '255.255.255.255', 8686);
        socket_recvfrom($sock, $buf, 1000, 0, $addr, $port);
        socket_close($sock);

        if(preg_match("/^(\d+\.\d+\.\d+\.\d+):(\d+)$/i", $buf, $ms)) {
            echo "Server found at $ms[1]:$ms[2]\n";
            $this->tcpClient = new TcpClient($ms[1], $ms[2]);
            $this->login( $this->tcpClient );
        } else {
            throw new SPSException("Can not login on ikarus master-slave server. Please run it first", -88);
        }
    }

    public function tearDown()
    {
        if($this->tcpClient)
            $this->logout( $this->tcpClient );
    }

    public function update(CyclicPluginManagementInterface $pluginManagement)
    {
        if($pluginManagement instanceof CyclicPluginManager && $this->tcpClient) {
            $changes = $pluginManagement->getFilteredChanges( $this->getSharedDomains(), $this->getSharedCommands() );
            try {
                $response = $this->interchange($this->tcpClient, $changes);
            } catch (SocketException $exception) {
                $this->handleConnectionException($exception, $changes, $pluginManagement);
                $this->tcpClient = NULL;
            }
            $pluginManagement->changes = [];

            if(isset($response) && is_array($response)) {
                $pluginManagement->applyChanges($response);
            }
        }
    }

    /**
     * Login on the remote master slave server
     *
     * @param TcpClient $client
     */
    abstract protected function login(TcpClient $client);

    /**
     * Logout on the remote master slave server.
     *
     * @param TcpClient $client
     */
    abstract protected function logout(TcpClient $client);

    /**
     * This method should send the changes to the remote server and receive its response changes.
     *
     * @param TcpClient $client
     * @param $changes
     * @return array
     */
    abstract protected function interchange(TcpClient $client, $changes);

    protected function handleConnectionException(SocketException $exception, $changes, PluginManagementInterface $pluginManagement) {
        $pushLostMasterAlert = function() use (&$pushLostMasterAlert, $pluginManagement) {
            echo "Master lost...\n";
            $alert = new RecoveryAlert(917, "Master lost", NULL);
            $alert->setCallback( function() use (&$pushLostMasterAlert) {
                try {
                    $this->setup();
                } catch (SPSException $exception) {
                    $pushLostMasterAlert();
                }
            } );
            $pluginManagement->triggerAlert( $alert );
            return;
        };

        $pushLostMasterAlert();
    }
}