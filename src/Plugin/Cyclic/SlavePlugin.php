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


use Ikarus\SPS\Client\TcpClient;
use Ikarus\SPS\Exception\SPSException;

class SlavePlugin extends AbstractMasterSlavePlugin
{
    private $requiredMasterIdentifier;

    public function __construct(string $identifier, string $requiredMasterIdentifier, array $sharedDomains = [], array $sharedCommands = [])
    {
        parent::__construct($identifier, $sharedDomains, $sharedCommands);
        if(preg_match("/^\S+$/i", $requiredMasterIdentifier)) {
            $this->requiredMasterIdentifier = $requiredMasterIdentifier;
        } else {
            throw new SPSException("Required master identifier must not contain whitespace", -77);
        }
    }

    protected function login(TcpClient $client)
    {
        @$client->sendCommandNamed("lgis " . $this->getIdentifier() . " " . serialize($this->requiredMasterIdentifier));
    }

    protected function logout(TcpClient $client)
    {
        @$client->sendCommandNamed("lgos " . $this->getIdentifier());
    }

    protected function interchange(TcpClient $client, $changes)
    {
        return @unserialize( $client->sendCommandNamed("syncs " . $this->getIdentifier() . " " . serialize($changes)) );
    }
}