<?php
require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';
require_once '/usr/local/emhttp/plugins/scriptlogs/scriptlogs_common.php';

function scriptlogs_tail_log($path, $max_lines = 100, $max_bytes = 262144, $remove_empty_lines = true)
{
    $result = ['ok' => false, 'text' => '', 'truncated' => false];

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return $result;
    }

    if (@fseek($handle, 0, SEEK_END) !== 0) {
        @fclose($handle);
        return $result;
    }

    $position = @ftell($handle);
    if ($position === false) {
        @fclose($handle);
        return $result;
    }

    $remaining = (int)$position;
    $buffer = '';
    $read_bytes = 0;
    $chunk_size = 4096;

    while ($remaining > 0 && $read_bytes < $max_bytes) {
        $bytes_to_read = min($chunk_size, $remaining, $max_bytes - $read_bytes);
        $remaining -= $bytes_to_read;

        if (@fseek($handle, $remaining, SEEK_SET) !== 0) {
            break;
        }

        $chunk = @fread($handle, $bytes_to_read);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }

        $buffer = $chunk . $buffer;
        $read_bytes += strlen($chunk);

        if (substr_count($buffer, "\n") >= ($max_lines + 1) && $read_bytes >= 16384) {
            break;
        }
    }

    @fclose($handle);

    $result['truncated'] = $remaining > 0;
    $result['ok'] = true;

    if ($buffer === '') {
        return $result;
    }

    $drop_first_line = $result['truncated'] && $buffer[0] !== "\n" && $buffer[0] !== "\r";
    $lines = preg_split('/\r\n|\r|\n/', $buffer);
    if (!is_array($lines)) {
        $lines = [];
    }

    if ($remove_empty_lines) {
        $filtered = [];
        foreach ($lines as $line) {
            if ($line !== '') {
                $filtered[] = $line;
            }
        }
    } else {
        $filtered = $lines;
    }

    if ($drop_first_line && count($filtered) > 0) {
        $filtered = array_slice($filtered, 1);
    }

    if (count($filtered) > $max_lines) {
        $filtered = array_slice($filtered, -$max_lines);
        $result['truncated'] = true;
    }

    $result['text'] = implode("\n", $filtered);
    return $result;
}

function scriptlogs_format_tail_text($tail)
{
    $text = (string)($tail['text'] ?? '');
    if (($tail['truncated'] ?? false) && $text !== '') {
        return "... log truncated ...\n" . $text;
    }

    return $text;
}

$scriptlogs_request_method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($scriptlogs_request_method === 'POST') {
    // Unraid's webGui validates csrf_token on every POST before this file runs
    // (auto-prepended local_prepend.php), so a forged request never gets here.
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    if (($_POST['action'] ?? '') !== 'run_script') {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['ok' => false, 'message' => 'Invalid action'], scriptlogs_json_options());
        exit;
    }

    $cfg = scriptlogs_read_cfg('scriptlogs');
    $result = scriptlogs_start_background_script(
        isset($_POST['script']) ? (string)$_POST['script'] : '',
        scriptlogs_parse_enabled_scripts($cfg['ENABLED_SCRIPTS'] ?? '')
    );

    if (!$result['ok']) {
        http_response_code($result['http_status']);
    }
    echo json_encode(['ok' => $result['ok'], 'message' => $result['message']], scriptlogs_json_options());
    exit;
}

if ($scriptlogs_request_method !== 'GET') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Only GET and POST requests allowed');
}

if (($_GET['action'] ?? '') === 'get_full_log') {
    // Whole background log of one selected script, as plain text.
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, must-revalidate');

    $cfg = scriptlogs_read_cfg('scriptlogs');
    $script_name = isset($_GET['script']) ? trim((string)$_GET['script']) : '';
    $enabled_scripts = scriptlogs_parse_enabled_scripts($cfg['ENABLED_SCRIPTS'] ?? '');

    if (!scriptlogs_is_safe_script_name($script_name) || !in_array($script_name, $enabled_scripts, true)) {
        header('HTTP/1.1 403 Forbidden');
        exit(scriptlogs_t('This script is not selected in the ScriptLogs settings.'));
    }

    $log_file = "/tmp/user.scripts/tmpScripts/{$script_name}/log.txt";
    if (!@is_file($log_file) || !@is_readable($log_file)) {
        header('HTTP/1.1 404 Not Found');
        exit(scriptlogs_t('No log found for this script (it has not run in the background since the last boot, or its log was deleted).'));
    }

    $size = @filesize($log_file);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @readfile($log_file);
    exit;
}

if (!isset($_GET['action']) || $_GET['action'] !== 'get_script_states') {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid action'], scriptlogs_json_options());
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

$cfg = scriptlogs_read_cfg('scriptlogs');

$enabled_scripts = scriptlogs_parse_enabled_scripts($cfg['ENABLED_SCRIPTS'] ?? '');
$show_idle_logs = ($cfg['SHOW_IDLE_LOGS'] ?? '0') === '1';
$remove_empty_log_lines = ($cfg['REMOVE_EMPTY_LOG_LINES'] ?? '1') === '1';
$tail_limits = scriptlogs_tail_limits();

$ps_output = @shell_exec('ps -ef 2>&1');
if (!is_string($ps_output)) {
    $ps_output = '';
}

$response_data = [];

foreach ($enabled_scripts as $script_name_raw) {
    $script_name = basename(trim((string)$script_name_raw));
    if ($script_name === '') {
        continue;
    }

    $script_data = ['name' => $script_name, 'status' => 'idle', 'log' => '', 'truncated' => false];

    $is_running_foreground = scriptlogs_is_foreground_running($ps_output, $script_name);
    $status_file_background = "/tmp/user.scripts/running/{$script_name}";
    $is_running_background = @file_exists($status_file_background);
    $log_file = "/tmp/user.scripts/tmpScripts/{$script_name}/log.txt";

    if ($is_running_foreground) {
        $script_data['status'] = 'running';
        $script_data['log'] = scriptlogs_t("Script is running in the foreground.\nView its live log in the 'User Scripts' plugin window.");
    } elseif ($is_running_background) {
        $script_data['status'] = 'running';
        if (@file_exists($log_file) && @is_readable($log_file)) {
            $tail = scriptlogs_tail_log($log_file, $tail_limits['lines'], $tail_limits['bytes'], $remove_empty_log_lines);
            if ($tail['ok']) {
                $script_data['truncated'] = (bool)$tail['truncated'];
                if ($tail['text'] !== '') {
                    $script_data['log'] = scriptlogs_format_tail_text($tail);
                } else {
                    $script_data['log'] = scriptlogs_t('Script is running, but has not produced any output yet.');
                }
            } else {
                $script_data['log'] = scriptlogs_t('Script is running, but log file cannot be read.');
            }
        } else {
            $script_data['log'] = scriptlogs_t('Script is running, but its log file has not been created yet.');
        }
    } else {
        if ($show_idle_logs) {
            if (@file_exists($log_file) && @is_readable($log_file)) {
                $tail = scriptlogs_tail_log($log_file, $tail_limits['lines'], $tail_limits['bytes'], $remove_empty_log_lines);
                if ($tail['ok']) {
                    $script_data['truncated'] = (bool)$tail['truncated'];
                    if ($tail['text'] !== '') {
                        $tail_text = scriptlogs_format_tail_text($tail);
                        $script_data['log'] = scriptlogs_t("Script is not running. Last log:\n\n") . $tail_text;
                    } else {
                        $script_data['log'] = scriptlogs_t('Script is not running. No previous log found (or it was last run in the foreground).');
                    }
                } else {
                    $script_data['log'] = scriptlogs_t('Script is not running. Log file cannot be read.');
                }
            } else {
                $script_data['log'] = scriptlogs_t('Script is not running. No previous log found (or it was last run in the foreground).');
            }
        } else {
            $script_data['log'] = scriptlogs_t('Script is not running.');
        }
    }

    $response_data[] = $script_data;
}

$json = json_encode($response_data, scriptlogs_json_options());
echo $json !== false ? $json : '[]';
