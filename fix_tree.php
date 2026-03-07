<?php
$output = [];
exec('git ls-tree 507407bea9057a81e924d6ddfac8087ba1d8ee94', $output);
$new_tree_lines = [];
foreach ($output as $line) {
    if (empty($line)) continue;
    $parts = explode("\t", $line, 2);
    if (count($parts) == 2) {
        if (strpos($parts[1], '\\\\') !== false) {
            continue;
        }
    }
    $new_tree_lines[] = $line;
}
$new_tree_input = implode("\n", $new_tree_lines) . "\n";

$descriptorspec = [
   0 => ["pipe", "r"],
   1 => ["pipe", "w"],
   2 => ["pipe", "w"]
];

$process = proc_open('git mktree', $descriptorspec, $pipes);
if (is_resource($process)) {
    fwrite($pipes[0], $new_tree_input);
    fclose($pipes[0]);
    $new_tree_hash = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    
    $commit_output = [];
    exec("git commit-tree " . escapeshellarg($new_tree_hash) . " -p 300a2e8b19057eaef86d297cf2e0ce96069d6d21 -m \"Fixed commit 507407b for Windows\"", $commit_output);
    $new_commit_hash = trim($commit_output[0]);
    
    exec("git reset --hard " . escapeshellarg($new_commit_hash));
    echo "SUCCESS: reset to " . $new_commit_hash . "\n";
}
?>
