<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

/** Docker CLI is available only in the separate provisioner image. No shell interpolation. */
class DockerCommand
{
    /** @param list<string> $arguments */
    public function run(array $arguments, string $code = 'infrastructure.docker_failed'): string
    {
        $command = array_merge(['docker'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new ProvisioningFailure($code, true); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $started = microtime(true);
        do {
            $read = [];
            foreach ([1, 2] as $index) { if (!feof($pipes[$index])) { $read[] = $pipes[$index]; } }
            if ($read !== []) {
                $write = null; $except = null;
                if (stream_select($read, $write, $except, 0, 100000) !== false) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 8192);
                        if ($stream === $pipes[1] && is_string($chunk) && strlen($output) < 1048576) {
                            $output .= substr($chunk, 0, 1048576 - strlen($output));
                        }
                        // Daemon stderr may contain host paths. Never return or log it.
                    }
                }
            } else { usleep(100000); }
            $status = proc_get_status($process);
            if (microtime(true) - $started > 300) {
                proc_terminate($process, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                throw new ProvisioningFailure($code, true);
            }
        } while ($status['running'] || !feof($pipes[1]) || !feof($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        $closed = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 0) {
            throw new ProvisioningFailure($code, true);
        }
        return trim($output);
    }

    /** @return array<string,mixed>|null */
    public function inspect(string $kind, string $name): ?array
    {
        if (!in_array($kind, ['container', 'network', 'image'], true)) {
            throw new \LogicException('Invalid Docker object kind.');
        }
        try { $result = $this->run(['inspect', '--type', $kind, $name]); }
        catch (ProvisioningFailure) { return null; }
        $decoded = json_decode($result, true);
        return is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : null;
    }
}
