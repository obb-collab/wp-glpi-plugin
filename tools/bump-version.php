<?php
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI\n");
    exit(1);
}

$argvCount = $_SERVER['argc'];
if ($argvCount < 2) {
    fwrite(STDERR, "Usage: php tools/bump-version.php <patch|minor|major|set> [version]\n");
    exit(1);
}

$action = $argv[1];
$versionArg = $argvCount > 2 ? $argv[2] : null;
$pluginFile = __DIR__ . '/../gexe-copy.php';
$readmeFile = __DIR__ . '/../readme.txt';

$contents = file_get_contents($pluginFile);
if (!preg_match('/^Version:\s*(\d+\.\d+\.\d+)/m', $contents, $matches)) {
    fwrite(STDERR, "Cannot find version in plugin file\n");
    exit(1);
}
$currentVersion = $matches[1];

function incrementVersion(string $version, string $type): string {
    list($major, $minor, $patch) = array_map('intval', explode('.', $version));
    switch ($type) {
        case 'patch':
            $patch++;
            break;
        case 'minor':
            $minor++;
            $patch = 0;
            break;
        case 'major':
            $major++;
            $minor = 0;
            $patch = 0;
            break;
        default:
            throw new InvalidArgumentException('Invalid increment type');
    }
    return sprintf('%d.%d.%d', $major, $minor, $patch);
}

switch ($action) {
    case 'patch':
    case 'minor':
    case 'major':
        $newVersion = incrementVersion($currentVersion, $action);
        break;
    case 'set':
        if ($versionArg === null || !preg_match('/^\d+\.\d+\.\d+$/', $versionArg)) {
            fwrite(STDERR, "Specify version as X.Y.Z\n");
            exit(1);
        }
        $newVersion = $versionArg;
        break;
    default:
        fwrite(STDERR, "Unknown action: $action\n");
        exit(1);
}

$updated = preg_replace('/^(Version:\s*)\d+\.\d+\.\d+/m', '$1' . $newVersion, $contents);
file_put_contents($pluginFile, $updated);

if (file_exists($readmeFile)) {
    $readme = file_get_contents($readmeFile);
    if (preg_match('/^Stable tag:/m', $readme)) {
        $readme = preg_replace('/^(Stable tag:\s*)\d+\.\d+\.\d+/m', '$1' . $newVersion, $readme);
    } else {
        $readme .= "\nStable tag: $newVersion\n";
    }
    file_put_contents($readmeFile, $readme);
}

$files = [$pluginFile];
if (file_exists($readmeFile)) {
    $files[] = $readmeFile;
}

$filesEscaped = array_map('escapeshellarg', $files);
exec('git add ' . implode(' ', $filesEscaped), $o, $exit);
if ($exit !== 0) {
    fwrite(STDERR, "git add failed\n");
    exit($exit);
}

$commitMsg = 'chore(release): v' . $newVersion;
exec('git commit -m ' . escapeshellarg($commitMsg), $o, $exit);
if ($exit !== 0) {
    fwrite(STDERR, "git commit failed\n");
    exit($exit);
}

exec('git tag -a v' . $newVersion . ' -m ' . escapeshellarg('v' . $newVersion), $o, $exit);
if ($exit !== 0) {
    fwrite(STDERR, "git tag failed\n");
    exit($exit);
}

echo "Bumped to v$newVersion\n";
