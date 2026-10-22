<?php
/**
 * BHIMAS KITCHEN - Permissions Fixer Utility
 * 
 * Upload this file to your web root folder (e.g. public_html/ or web/) 
 * and visit: https://bhimas.lu/fix_permissions.php in your browser.
 * 
 * It will recursively set folder permissions to 0755 and file permissions to 0644.
 */

header('Content-Type: text/plain; charset=utf-8');

function recursivelyFixPermissions($path) {
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path)) {
        // Exclude parent and current dir links
        if (basename($path) === '.' || basename($path) === '..') {
            return;
        }
        
        // Exclude wordpress backup to save time/memory if it exists
        if (basename($path) === 'wordpress_backup') {
            echo "Skipping wordpress_backup directory...\n";
            return;
        }

        // Set directory to 0755 (read & execute for everyone, write for owner)
        if (chmod($path, 0755)) {
            echo "DIR  [0755] -> $path\n";
        } else {
            echo "FAIL [DIR]  -> $path (Check ownership)\n";
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            recursivelyFixPermissions($path . '/' . $item);
        }
    } else {
        // Set file to 0644 (read for everyone, write for owner)
        if (chmod($path, 0644)) {
            echo "FILE [0644] -> $path\n";
        } else {
            echo "FAIL [FILE] -> $path\n";
        }
    }
}

echo "--- Starting Bhimas Permissions Fixer ---\n\n";

$startDir = __DIR__;
recursivelyFixPermissions($startDir);

echo "\n--- Permissions Fix Completed! ---\n";
echo "This script will now self-delete for security.\n";

// Self-delete this file
unlink(__FILE__);
