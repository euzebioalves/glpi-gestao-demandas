<?php
declare(strict_types=1);

// Focused token rules, not a type checker or a proof of authorization.
$root = getenv('DEMANDAS_QUALITY_ROOT') ?: dirname(__DIR__, 2);
$errors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/plugins/demandas', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $tokens = token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
    $tokens = array_values(array_filter($tokens, static fn($t) => !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)));
    foreach ($tokens as $i => $token) {
        if ($token === '`') $errors[] = "$path: execução por crases proibida";
        if (!is_array($token)) continue;
        [$kind, $value, $line] = $token;
        $name = strtolower(ltrim($value, '\\'));
        $previous = $tokens[$i - 1] ?? null;
        $method = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        $call = ($tokens[$i + 1] ?? null) === '(';
        $blocked = $kind === T_EVAL
            || ($call && !$method && in_array($name, ['unserialize', 'shell_exec', 'system', 'passthru', 'exec', 'popen'], true))
            || ($call && $method && $name === 'query')
            || ($call && !$method && $name === 'proc_open' && $path !== 'plugins/demandas/src/WorkPackageAnalysisService.php');
        if ($blocked) $errors[] = "$path:$line: operação proibida pela política estática";
    }
}
foreach ($errors as $error) fwrite(STDERR, $error . "\n");
if ($errors) exit(1);
echo "PASS: regras estáticas PHP (tokens, sem comentários/literais)\n";
