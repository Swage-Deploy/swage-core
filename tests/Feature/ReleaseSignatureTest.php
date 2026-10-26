<?php

use App\SelfUpdate\ReleaseSignature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    if (Process::run(['which', 'git'])->failed() || Process::run(['which', 'ssh-keygen'])->failed()) {
        $this->markTestSkipped('git and ssh-keygen are needed to sign and check tags.');
    }

    $this->directory = storage_path('framework/testing/signatures-'.uniqid());
    File::ensureDirectoryExists($this->directory);

    foreach (['release', 'stranger'] as $key) {
        Process::path($this->directory)->run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $key, '-f', $key])->throw();
    }

    $this->signers = $this->directory.'/release-signers';
    File::put($this->signers, "# Swage release keys\nreleases@example.com namespaces=\"git\" ".File::get($this->directory.'/release.pub'));

    $this->repository = $this->directory.'/repository';
    gitIn($this->directory, ['init', '--quiet', '-b', 'main', $this->repository]);
    gitIn($this->repository, ['commit', '--quiet', '--allow-empty', '-m', 'First']);
    $this->commit = trim(gitIn($this->repository, ['rev-parse', 'HEAD']));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

/**
 * Run git with an identity, signing tags with the given key when asked to.
 */
function gitIn(string $directory, array $arguments, ?string $signingKey = null): string
{
    return Process::path($directory)->run([
        'git', '-c', 'user.name=Swage', '-c', 'user.email=releases@example.com', '-c', 'gpg.format=ssh',
        ...($signingKey ? ['-c', "user.signingkey={$signingKey}"] : []),
        ...$arguments,
    ])->throw()->output();
}

test('a version tag signed with a release key is accepted and gives the commit it points to', function () {
    gitIn($this->repository, ['tag', '-s', 'v1.0.0', '-m', 'v1.0.0'], $this->directory.'/release');

    expect((new ReleaseSignature($this->signers))->verifyTag($this->repository, 'v1.0.0'))->toBe($this->commit);
});

test('a version tag that is not signed is refused', function () {
    gitIn($this->repository, ['tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);

    (new ReleaseSignature($this->signers))->verifyTag($this->repository, 'v1.0.0');
})->throws(RuntimeException::class, 'v1.0.0 is not signed by a Swage release key');

test('a version tag signed with a key that is not a release key is refused', function () {
    gitIn($this->repository, ['tag', '-s', 'v1.0.0', '-m', 'v1.0.0'], $this->directory.'/stranger');

    (new ReleaseSignature($this->signers))->verifyTag($this->repository, 'v1.0.0');
})->throws(RuntimeException::class, 'v1.0.0 is not signed by a Swage release key');

test('an older signed tag cannot be passed off under a newer version\'s name', function () {
    gitIn($this->repository, ['tag', '-s', 'v1.0.0', '-m', 'v1.0.0'], $this->directory.'/release');
    $tagObject = trim(gitIn($this->repository, ['rev-parse', 'refs/tags/v1.0.0']));
    gitIn($this->repository, ['update-ref', 'refs/tags/v2.0.0', $tagObject]);

    (new ReleaseSignature($this->signers))->verifyTag($this->repository, 'v2.0.0');
})->throws(RuntimeException::class, 'was made for another version');

test('only version tags can be checked', function () {
    (new ReleaseSignature($this->signers))->verifyTag($this->repository, 'main');
})->throws(RuntimeException::class, 'main is not a version tag');

test('signatures are only required once the signers file lists a key', function () {
    File::put($this->directory.'/empty-signers', "# No keys yet\n\n");

    expect((new ReleaseSignature($this->signers))->isRequired())->toBeTrue()
        ->and((new ReleaseSignature($this->directory.'/empty-signers'))->isRequired())->toBeFalse()
        ->and((new ReleaseSignature($this->directory.'/missing'))->isRequired())->toBeFalse();
});

test('the signers file that ships with swage is the one checked against', function () {
    expect(config('swage.release_signers'))->toBe(base_path('resources/release-signers'))
        ->and(File::exists(base_path('resources/release-signers')))->toBeTrue();
});
