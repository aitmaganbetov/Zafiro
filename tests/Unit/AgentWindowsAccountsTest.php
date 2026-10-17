<?php

use Symfony\Component\Finder\Finder;

// The agent is installed on the super workstation of a diskless club: an account it created
// there would be in the boot image of every PC of the club.
test('the agent, its installer and scripts never create Windows accounts or set up auto sign-in', function () {
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/WindowsAgent')
        ->exclude(['bin', 'obj', 'ZafiroAgent.Tests'])
        ->name(['*.cs', '*.ps1', '*.iss', '*.cmd', '*.bat', '*.xml']);
    $forbidden = '/New-LocalUser|\bnet(?:\.exe)?\s+user\b|NetUserAdd|DirectoryServices|PrincipalContext|UserPrincipal|AutoAdminLogon|DefaultUserName|DefaultPassword|unattend|sysprep/i';

    $found = [];
    foreach ($files as $file) {
        if (preg_match_all($forbidden, $file->getContents(), $matches)) {
            $found[] = $file->getRelativePathname().': '.implode(', ', array_unique($matches[0]));
        }
    }

    expect(iterator_count($files))->toBeGreaterThan(10)
        ->and($found)->toBe([]);
});
