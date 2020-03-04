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

define("SOCK_BUFFER_SIZE", 2048);
define("IKARUS_AWARE_SERVER_PORT", 8686);
define("IKARUS_MAX_CLIENTS", 10);


$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

$options = getopt("a:p:");

if(!isset($options["a"])) {
    $options["a"] = '127.0.0.1';

    if(preg_match_all('/inet.+?(\d+\.\d+\.\d+\.\d+)/i', `ifconfig`, $ips)) {
        foreach($ips[1] as $ip) {
            if($ip == '127.0.0.1')
                continue;
            $options["a"] = $ip;
            break;
        }
    }
}

if (!@socket_bind($socket, $options["a"], $options['p'] ?? 0)) {
    $c = socket_last_error($socket);
    throw new RuntimeException( "socket_create() failed: " . socket_strerror($c), $c);
}

socket_listen($socket, IKARUS_MAX_CLIENTS);
socket_getsockname($socket, $serverAddr, $serverPort);

if(function_exists('pcntl_fork')) {
    // Launch master aware server
    /**
     * The master aware server knows how to contact the ikarus server and its reachable over broadcast
     */

    $pid = pcntl_fork();
    if($pid == -1)
        throw new RuntimeException("Could not fork process");

    if($pid == 0) {
        // Child process
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($sock, 0, IKARUS_AWARE_SERVER_PORT);

        while (1) {
            socket_recvfrom($sock, $buf, 1000, 0, $addr, $port);
            if($buf == 'hello') {
                $buf = "$serverAddr:$serverPort";
                socket_sendto($sock, $buf, strlen($buf), 0, $addr, $port);
            }
        }
        exit();
    }
}

$MASTERS = [];
$SLAVES = [];

echo "OK, listen on $serverAddr:$serverPort\n";

if(function_exists("pcntl_signal")) {
    $handler = function($signo) use (&$pid) {
        echo "Stop ikarus aware server $pid...\n";

        posix_kill($pid, $signo);
        pcntl_waitpid($pid, $status);
        exit();
    };
    pcntl_signal(SIGINT, $handler, false);
    pcntl_signal(SIGTERM, $handler, false);
}

socket_set_nonblock($socket);

while (1) {
    if($msgsock = socket_accept($socket)) {
        $input = socket_read($msgsock, 4096);
        $output = doCommand(
            $input
        );
        socket_write($msgsock, $output, strlen($output));
        socket_set_nonblock($msgsock);

        socket_close($msgsock);
    }
    declare(ticks=1) {
        usleep(1000);
    }
}

socket_close($socket);


function doCommand($command) {
    global $MASTERS, $SLAVES;

    $data = explode(" ", $command, 3);
    $info = NULL;
    if(count($data) == 3) {
        list($cmd, $identifier, $info) = $data;
        $info = unserialize(trim($info));
    } elseif(count($data) == 2)
        list($cmd, $identifier) = $data;
    else
        return "-5";

    switch ($cmd) {
        case 'lgim':    // Login master
            echo "Login master $identifier\n";
            if(!isset($MASTERS[$identifier]))
                $MASTERS[$identifier] = [];
            else
                return "-1";
            break;
        case 'lgom':    // logout master
            echo "Logout master $identifier\n";
            unset($MASTERS[ $identifier ]);
            break;
        case 'lgis':    // login slave
            echo "Login $identifier ($info)\n";
            if(!isset($SLAVES[$identifier]))
                $SLAVES[$identifier] = $info;
            else
                return "-2";
            break;
        case 'lgos':    // logout slave
            echo "Logout slave $identifier\n";
            unset($SLAVES[ $identifier ]);
            break;
        case 'syncm':
            $changes = $MASTERS[ $identifier ] ?? NULL;
            $MASTERS[$identifier] = $info ?: [];
            return serialize($changes);
            break;
        case 'syncs':
            $masterID = $SLAVES[ $identifier ] ?? NULL;
            if(!$masterID) {
                echo "Slave $identifier is not logged in.\n";
                return serialize(-1);
            }

            if(!isset( $MASTERS[$masterID] )) {
                echo "Master $masterID is not logged in.\n";
                return serialize(-2);
            }

            $changes = $MASTERS[ $masterID ] ?? NULL;

            $merge = function($array1, $array2) use (&$merge) {
                foreach($array2 as $key => $value) {
                    if(is_array($value)) {
                        $v1 = $array1[$key] ?? [];
                        if(!is_array($v1)) {
                            $v1 = $v1 ? [$v1] : [];
                        }
                        $array1[$key] = $merge($v1, $value);
                    } else {
                        $array1[$key] = $value;
                    }
                }
                return $array1;
            };

            $MASTERS[$masterID] = $merge($MASTERS[$masterID] ?: [], $info);

            return serialize($changes);
            break;
    }

    return "OK";
}
