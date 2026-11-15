<?php

function scriptlogs_default_cfg()
{
    return [
        'REFRESH_ENABLED' => '1',
        'REFRESH_INTERVAL' => '10',
        'ENABLED_SCRIPTS' => '',
        'SHOW_IDLE_LOGS' => '0',
        'LOG_FONT_SIZE' => '1rem',
        'REMOVE_EMPTY_LOG_LINES' => '1'
    ];
}

function scriptlogs_read_cfg($plugin_name = 'scriptlogs')
{
    $cfg = parse_plugin_cfg($plugin_name);
    if (!is_array($cfg)) {
        $cfg = [];
    }

    return array_merge(scriptlogs_default_cfg(), $cfg);
}

function scriptlogs_allowed_font_sizes()
{
    return ['0.75rem', '0.875rem', '1rem', '1.125rem', '1.25rem'];
}

function scriptlogs_order_available_scripts($available_scripts, $enabled_scripts)
{
    $available_scripts = scriptlogs_normalize_script_list($available_scripts);
    $enabled_scripts = scriptlogs_normalize_script_list($enabled_scripts);
    $available_lookup = array_fill_keys($available_scripts, true);
    $ordered = [];
    $seen = [];

    foreach ($enabled_scripts as $script_name) {
        if (isset($available_lookup[$script_name]) && !isset($seen[$script_name])) {
            $ordered[] = $script_name;
            $seen[$script_name] = true;
        }
    }

    foreach ($available_scripts as $script_name) {
        if (!isset($seen[$script_name])) {
            $ordered[] = $script_name;
            $seen[$script_name] = true;
        }
    }

    return $ordered;
}

function scriptlogs_tail_limits()
{
    return ['lines' => 100, 'bytes' => 262144];
}

function scriptlogs_json_options()
{
    $options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $options |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return $options;
}

function scriptlogs_t($text)
{
    return function_exists('_') ? _($text) : $text;
}

function scriptlogs_theme_name()
{
    global $display;
    // Unraid's ThemeHelper resolves names like "black-sidebar" the same way.
    $theme = strtok((string)($display['theme'] ?? ''), '-');
    return is_string($theme) && $theme !== '' ? strtolower($theme) : 'black';
}

function scriptlogs_theme_is_light()
{
    return in_array(scriptlogs_theme_name(), ['white', 'azure'], true);
}

function scriptlogs_is_foreground_running($ps_output, $script_name)
{
    if (!is_string($ps_output) || $ps_output === '') {
        return false;
    }

    $script_path = '/tmp/user.scripts/tmpScripts/' . $script_name . '/script';
    $pattern = '/(?:^|\s)(?:\S+\/)?startScript\.sh\s+' . preg_quote($script_path, '/') . '(?:\s|$)/';
    $lines = preg_split('/\r\n|\r|\n/', $ps_output);
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        if (preg_match($pattern, $line) === 1) {
            return true;
        }
    }

    return false;
}

function scriptlogs_normalize_script_list($items)
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($items as $item) {
        $name = trim((string)$item);
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $normalized[] = $name;
    }

    return $normalized;
}

function scriptlogs_parse_enabled_scripts($raw_value)
{
    $raw_value = is_string($raw_value) ? trim($raw_value) : '';
    if ($raw_value === '') {
        return [];
    }

    if (strpos($raw_value, 'json:') === 0) {
        $encoded = substr($raw_value, 5);
        if ($encoded !== '') {
            $decoded_json = base64_decode($encoded, true);
            if (is_string($decoded_json) && $decoded_json !== '') {
                $decoded = json_decode($decoded_json, true);
                if (is_array($decoded)) {
                    return scriptlogs_normalize_script_list($decoded);
                }
            }
        }
    }

    return scriptlogs_normalize_script_list(explode(',', $raw_value));
}

function scriptlogs_encode_enabled_scripts($scripts)
{
    $normalized = scriptlogs_normalize_script_list($scripts);
    $json = json_encode($normalized);
    if (!is_string($json)) {
        $json = '[]';
    }

    return 'json:' . base64_encode($json);
}

function scriptlogs_user_scripts_dir()
{
    return '/boot/config/plugins/user.scripts/scripts';
}

function scriptlogs_user_scripts_plugin_dir()
{
    return '/usr/local/emhttp/plugins/user.scripts';
}

function scriptlogs_is_safe_script_name($name)
{
    if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
        return false;
    }

    if ($name !== basename($name)) {
        return false;
    }

    // User Scripts hands the script path to at(1) on a shell command line,
    // so names with shell metacharacters are refused instead of launched.
    return preg_match('/[\x00-\x1f\x7f`$;&|<>(){}\[\]\\\\"\'*?!#~]/', $name) !== 1;
}

function scriptlogs_is_script_running($script_name)
{
    if (@file_exists("/tmp/user.scripts/running/{$script_name}")) {
        return true;
    }

    $ps_output = @shell_exec('ps -ef 2>&1');
    return scriptlogs_is_foreground_running(is_string($ps_output) ? $ps_output : '', $script_name);
}

function scriptlogs_run_result($ok, $http_status, $message)
{
    return ['ok' => (bool)$ok, 'http_status' => (int)$http_status, 'message' => (string)$message];
}

/**
 * Starts a User Script in the background, the same way the "Run In Background"
 * button of the User Scripts plugin does: the script is copied to
 * /tmp/user.scripts/tmpScripts/<name>/script and handed to backgroundScript.sh,
 * which detaches it through at(1). Only scripts selected for the dashboard
 * widget can be started.
 */
function scriptlogs_start_background_script($script_name, $enabled_scripts)
{
    $script_name = is_string($script_name) ? trim($script_name) : '';

    if (!scriptlogs_is_safe_script_name($script_name)) {
        return scriptlogs_run_result(false, 400, scriptlogs_t('This script name cannot be started from the dashboard.'));
    }

    if (!in_array($script_name, scriptlogs_normalize_script_list($enabled_scripts), true)) {
        return scriptlogs_run_result(false, 403, scriptlogs_t('This script is not selected in the ScriptLogs settings.'));
    }

    $plugin_dir = scriptlogs_user_scripts_plugin_dir();
    $launcher = $plugin_dir . '/backgroundScript.sh';
    if (!@is_file($launcher)) {
        return scriptlogs_run_result(false, 500, scriptlogs_t('The User Scripts plugin is not installed.'));
    }

    $source_path = scriptlogs_user_scripts_dir() . '/' . $script_name . '/script';
    if (!@is_file($source_path) || !@is_readable($source_path)) {
        return scriptlogs_run_result(false, 404, scriptlogs_t('Script not found.'));
    }

    if (scriptlogs_is_script_running($script_name)) {
        return scriptlogs_run_result(false, 409, scriptlogs_t('This script is already running.'));
    }

    $variables = [];
    $helpers = $plugin_dir . '/helpers.php';
    if (!function_exists('getScriptVariables') && @is_file($helpers)) {
        require_once $helpers;
    }
    if (function_exists('getScriptVariables')) {
        $parsed = getScriptVariables($source_path);
        if (is_array($parsed)) {
            $variables = $parsed;
        }
    }

    if (!empty($variables['foregroundOnly'])) {
        return scriptlogs_run_result(false, 409, scriptlogs_t('This script is marked foregroundOnly and cannot run in the background.'));
    }

    if (!empty($variables['arrayStarted'])) {
        $unraid_vars = @parse_ini_file('/var/local/emhttp/var.ini');
        if (!is_array($unraid_vars) || ($unraid_vars['mdState'] ?? '') !== 'STARTED') {
            return scriptlogs_run_result(false, 409, scriptlogs_t('The array must be started to run this script.'));
        }
    }

    $script = @file_get_contents($source_path);
    if (!is_string($script)) {
        return scriptlogs_run_result(false, 500, scriptlogs_t('The script could not be read.'));
    }

    if (strncmp($script, '#!', 2) !== 0) {
        $script = "#!/bin/bash\n" . $script;
    }
    $script = str_replace("\r", '', $script);

    $run_dir = '/tmp/user.scripts/tmpScripts/' . $script_name;
    $run_path = $run_dir . '/script';
    if (!@is_dir($run_dir) && !@mkdir($run_dir, 0777, true) && !@is_dir($run_dir)) {
        return scriptlogs_run_result(false, 500, scriptlogs_t('The script could not be prepared.'));
    }
    if (@file_put_contents($run_path, $script) === false) {
        return scriptlogs_run_result(false, 500, scriptlogs_t('The script could not be prepared.'));
    }
    @chmod($run_path, 0755);

    $output = [];
    $exit_code = 1;
    @exec('/bin/bash ' . escapeshellarg($launcher) . ' ' . escapeshellarg($run_path) . ' > /dev/null 2>&1', $output, $exit_code);
    if ($exit_code !== 0) {
        return scriptlogs_run_result(false, 500, scriptlogs_t('The script could not be started.'));
    }

    @exec('logger -t scriptlogs ' . escapeshellarg("Started '{$script_name}' in the background from the dashboard"));

    return scriptlogs_run_result(true, 200, scriptlogs_t('Script started in the background.'));
}
