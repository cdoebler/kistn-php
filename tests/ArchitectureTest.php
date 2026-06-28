<?php

// Arch testing in general
// https://pestphp.com/docs/arch-testing

// https://pestphp.com/docs/arch-testing/#content-php
// https://github.com/pestphp/pest/blob/3.x/src/ArchPresets/Php.php
arch("Code satisfies Pest's php presets")
    ->preset()
    ->php();

// https://pestphp.com/docs/arch-testing/#content-security
// https://github.com/pestphp/pest/blob/3.x/src/ArchPresets/Security.php
arch("Code satisfies Pest's security presets")
    ->preset()
    ->security()
    ->ignoring(\Kistn\Process\ShellProcessRunner::class); // ShellProcessRunner intentionally wraps exec(); commands are hardcoded in collectors, not user input

// Redundant since in Laravel presets as well but mandatory not to forget
test('No debugging statements are left in the code')
    ->expect(['dd', 'dump', 'print_r', 'ray', 'var_dump'])
    ->not->toBeUsed();
