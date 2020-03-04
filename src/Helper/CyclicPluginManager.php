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

namespace Ikarus\SPS\MS\Helper;


use Ikarus\SPS\Alert\AlertInterface;

class CyclicPluginManager extends \Ikarus\SPS\Helper\CyclicPluginManager
{
    private $enabled = true;
    public $changes = [];

    public function putCommand(string $command, $info = false)
    {
        parent::putCommand($command, $info);
        if($this->enabled)
            $this->changes['c'][$command] = $info;
    }

    public function clearCommand(string $command = NULL)
    {
        parent::clearCommand($command);
        if($this->enabled)
            $this->changes['cc'][] = $command;
    }

    public function putValue($value, $key, $domain)
    {
        parent::putValue($value, $key, $domain);
        if($this->enabled && ($this->changes['v'][$domain][$key]??NULL) != $value)
            $this->changes['v'][$domain][$key] = $value;
    }

    public function triggerAlert(AlertInterface $alert)
    {
        parent::triggerAlert($alert);
        if($this->enabled)
            $this->changes['a'][ $alert->getUUID() ] = [
                'class' => get_class($alert),
                'code' => $alert->getCode(),
                'message' => $alert->getMessage(),
                'plugin' => $alert->getAffectedPlugin() ? $alert->getAffectedPlugin()->getIdentifier() : '',
                'time' => $alert->getTimeStamp()
            ];
    }

    public function quitAlert(string $alertUUID)
    {
        parent::quitAlert($alertUUID);
        if($this->enabled)
            $this->changes['qa'][] = $alertUUID;
    }


    public function applyChanges($changes) {
        $this->enabled = false;

        foreach(($changes["v"] ?? []) as $domain => $vals) {
            foreach($vals as $key => $val)
                $this->putValue($val, $key, $domain);
        }
        foreach(($changes["c"] ?? []) as $command => $info) {
            $this->putCommand( $command, $info );
        }
        foreach(($changes["cc"] ?? []) as $command)
            $this->clearCommand($command);
        foreach(($changes["qa"] ?? []) as $alertUID)
            $this->quitAlert($alertUID);

        $this->enabled = true;
    }

    public function getFilteredChanges(array $domains, array $commands, bool $clearedCommands = true, $quitAlerts = true) {
        $changes = [];

        if($clearedCommands && isset($this->changes["cc"]))
            $changes["cc"] = $this->changes["cc"];

        if($quitAlerts && isset($this->changes["qa"]))
            $changes["qa"] = $this->changes["qa"];

        if($domains) {
            foreach(($this->changes["v"] ?? []) as $domain => $values) {
                foreach($domains as $dm) {
                    if(fnmatch($dm, $domain)) {
                        $changes["v"][$domain] = $values;
                        continue 2;
                    }
                }
            }
        } elseif(isset($this->changes["v"])) {
            $changes["v"] = $this->changes["v"];
        }

        if($commands) {
            foreach(($this->changes["c"] ?? []) as $command => $info) {
                foreach($commands as $cmd) {
                    if(fnmatch($cmd, $command)) {
                        $changes["c"][$command] = $info;
                        continue 2;
                    }
                }
            }
        } elseif(isset($this->changes["c"]))
            $changes["c"] = $this->changes["c"];

        return $changes;
    }
}