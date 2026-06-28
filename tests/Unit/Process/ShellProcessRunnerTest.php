<?php

use Kistn\Process\ShellProcessRunner;

test('returns output and exitCode keys', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run('echo hello');

    expect($result)->toHaveKeys(['output', 'exitCode']);
});

test('captures stdout and returns exit code 0 on success', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run('echo hello');

    expect($result['output'])->toBe('hello');
    expect($result['exitCode'])->toBe(0);
});

test('joins multiple output lines with newline', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run('printf "line1\nline2\nline3"');

    expect($result['output'])->toBe("line1\nline2\nline3");
    expect($result['exitCode'])->toBe(0);
});

test('returns empty string when command produces no output', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run('true');

    expect($result['output'])->toBe('');
    expect($result['exitCode'])->toBe(0);
});

test('returns non-zero exit code on failure', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run('false');

    expect($result['exitCode'])->toBe(1);
});

test('suppresses stderr output', function () {
    $runner = new ShellProcessRunner();
    $result = $runner->run("sh -c 'echo error_msg >&2'");

    expect($result['output'])->toBe('');
    expect($result['exitCode'])->toBe(0);
});
