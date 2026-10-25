<?php

use App\SelfUpdate\GitRefs;

test('git refs are read from ls-remote output, with annotated tags pointing to their commit', function () {
    $refs = GitRefs::parse("ref: refs/heads/main\tHEAD\n1111\tHEAD\n1111\trefs/heads/main\n2222\trefs/tags/v1.0.0\n3333\trefs/tags/v1.0.0^{}\n");

    expect($refs)->toBe([
        'branches' => ['main' => '1111'],
        'tags' => ['v1.0.0' => '3333'],
        'head' => 'main',
    ]);
});

test('the newest version tag is picked, leaving out pre-releases and other tags', function () {
    expect(GitRefs::newestVersion(['v1.9.0' => 'a', 'v1.10.0' => 'b', '2.0.0-beta' => 'c', 'latest' => 'd', '1.2' => 'e']))->toBe('v1.10.0')
        ->and(GitRefs::newestVersion(['latest' => 'd']))->toBeNull()
        ->and(GitRefs::isNewer('v1.10.0', '1.9'))->toBeTrue()
        ->and(GitRefs::isNewer('v1.0.0', 'v1.0.0'))->toBeFalse();
});
