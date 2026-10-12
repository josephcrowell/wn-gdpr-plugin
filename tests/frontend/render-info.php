<?php

// Render the real backend views without starting Winter or using a database.
class Block
{
    public static function put($name) { ob_start(); }
    public static function endPut() { ob_end_clean(); }
}

class Backend
{
    public static function url($path) { return '/backend/' . $path; }
}

function trans($key) { return $key; }

$view = $argv[1] ?? 'index';
if (!in_array($view, [
    'index', 'overallinfo', 'dataretention', 'cookieconsent', 'cookiesmodulesuserguide',
    'cookiesmodulessetup', 'dpolinks', 'bannerlaws',
], true)) {
    throw new InvalidArgumentException('Unknown backend info view');
}

require dirname(__DIR__, 2) . '/controllers/info/' . $view . '.htm';
