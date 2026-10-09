<?php

/*
 * WHAT A RUN STARTS WITH (tools/demo-images/build.php): the sources' keys, the face detector
 * where it can be found, and the owner's removals, a new one taken from --remove. Required by
 * build.php with $root, $config, $cache and $options in scope.
 */

/** A key from the environment or the site's .env; never printed, never written anywhere else. */
$key = static function (string $name) use ($root): string {
    $value = (string) getenv($name);
    if ($value === '' && is_file($root . '/.env') && preg_match('~^' . $name . '\s*=\s*"?([^"\s]+)"?~m', (string) file_get_contents($root . '/.env'), $m) === 1) {
        $value = $m[1];
    }

    return $value;
};
$env = ['pixabay_key' => $key('PIXABAY_API_KEY'), 'pexels_key' => $key('PEXELS_API_KEY'), 'cache' => $cache, 'ua' => $config['user_agent']];
$needs = ['pixabay' => 'PIXABAY_API_KEY', 'pexels' => 'PEXELS_API_KEY'];

$python = (string) (getenv('DEMO_FACES_PYTHON') ?: getenv('HOME') . '/boxlet-build/venv-faces/bin/python');
$model = (string) (getenv('DEMO_FACES_MODEL') ?: getenv('HOME') . '/boxlet-build/yunet.onnx');
$python = is_file($python) && is_file($model) && trim((string) shell_exec(escapeshellarg($python) . ' -c "import cv2; print(int(hasattr(cv2, \'FaceDetectorYN\')))" 2>/dev/null')) === '1' ? $python : null;

$removedFile = __DIR__ . '/removed.json';
$removed = is_file($removedFile) ? (array) json_decode((string) file_get_contents($removedFile), true) : [];
$state = is_file($cache . '/state.json') ? (array) json_decode((string) file_get_contents($cache . '/state.json'), true) : [];
if (isset($options['remove'])) {
    foreach (array_filter(array_map('trim', explode(',', (string) $options['remove']))) as $file) {
        if (!isset($state[$file])) {
            fwrite(STDERR, "Not a picture of the last run: {$file}\n");
            exit(1);
        }
        $removed[$state[$file]] = $file . (isset($options['reason']) ? ': ' . $options['reason'] : '');
    }
    file_put_contents($removedFile, json_encode($removed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
