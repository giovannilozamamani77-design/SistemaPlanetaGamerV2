<?php

it('C369 keeps visible application sources in UTF-8 without double-encoded Spanish characters', function () {
    $failures = [];
    foreach (['app', 'resources', 'lang', 'config'] as $folder) {
        if (! is_dir(base_path($folder))) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'json', 'vue', 'css'], true)) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! mb_check_encoding($content, 'UTF-8') || preg_match('/(?:\\x{00C3}[\\x{0080}-\\x{00BF}]|\\x{00C2}[\\x{0080}-\\x{00BF}]|\\x{00E2}[\\x{0080}-\\x{009F}\\x{20AC}]|\\x{FFFD})/u', $content)) {
                $failures[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }
    expect($failures)->toBe([]);
});
