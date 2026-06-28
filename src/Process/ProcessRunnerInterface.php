<?php

namespace Kistn\Process;

interface ProcessRunnerInterface
{
    /**
     * @return array{output: string, exitCode: int}
     */
    public function run(string $command): array;
}
