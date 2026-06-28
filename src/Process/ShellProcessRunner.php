<?php

namespace Kistn\Process;

class ShellProcessRunner implements ProcessRunnerInterface
{
    public function run(string $command): array
    {
        $outputLines = [];
        $exitCode = 0;

        exec($command . ' 2>/dev/null', $outputLines, $exitCode);

        return [
            'output'   => implode("\n", $outputLines),
            'exitCode' => $exitCode,
        ];
    }
}
