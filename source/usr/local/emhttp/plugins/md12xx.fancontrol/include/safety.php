<?php
declare(strict_types=1);

// Parse individual Cooling elements, never the SES overall/aggregate descriptor.
function md12xx_parse_fan_telemetry(string $output): array
{
    $values = [];
    $faults = [];
    if (preg_match_all('/Element type:\s*Cooling\b(.*?)(?=\n\s*Element type:|\z)/is', $output, $sections)) {
        foreach ($sections[1] as $section) {
            preg_match_all('/^\s*element\s+\d+\s+descriptor:(.*?)(?=^\s*(?:element\s+\d+\s+descriptor:|Overall descriptor:)|\z)/ims', $section, $elements);
            foreach ($elements[1] as $element) {
                if (!preg_match('/Actual\s+speed\s*=\s*(\d+)\s*rpm/i', $element, $speed)) {
                    $faults[] = 'Fan speed is missing';
                    continue;
                }
                $values[] = (int) $speed[1];
                if ((int) $speed[1] <= 0 || preg_match('/\b(?:Fail|Disabled|Predicted failure|CRIT|UNRECOV)\s*=\s*1\b/i', $element)
                    || (preg_match('/\bstatus:\s*([^\r\n]+)/i', $element, $status) && trim($status[1]) !== 'OK')) {
                    $faults[] = 'Cooling element reports a stopped, failed, disabled, or unhealthy fan';
                }
            }
        }
    }
    if (count($values) !== 4) $faults[] = 'Expected four individual MD12xx fan readings';
    return [
        'state' => $faults ? 'fault' : 'normal',
        'averageRpm' => $values ? (int) round(array_sum($values) / count($values)) : null,
        'fanCount' => count($values), 'fanRpms' => $values,
        'message' => implode('; ', array_unique($faults)),
    ];
}

function md12xx_disk_reports_standby(array $disk): bool
{
    $explicit = strtolower(trim((string) ($disk['spundown'] ?? '')));
    return in_array($explicit, ['1', 'yes', 'true', 'on'], true)
        || str_contains(strtolower((string) ($disk['color'] ?? '')), 'blink');
}

function md12xx_sensor_failure_speed(array $config, array $curve): int
{
    return max((int) $config['sensorFailureSpeed'], max(array_column($curve, 'speed')));
}

// disks.ini reports values but supplies no successful observation evidence.
// Do not infer freshness from the file timestamp, polling cadence, or standby.
function md12xx_commission_temperature_policy(array $config, array $disks): array
{
    $required = md12xx_sensor_failure_speed($config, $config['curve']);
    foreach ($config['shelves'] ?? [] as $shelf) {
        $required = max($required, md12xx_sensor_failure_speed($config, cooling_device_curve($shelf, $config['curve'])));
    }
    if (($config['mode'] ?? '') === 'manual') $required = max($required, (int) $config['manualSpeed']);
    $active = count(array_filter($disks, static fn(array $disk): bool => !md12xx_disk_reports_standby($disk)));
    return ['safe' => false, 'restoreSpeed' => $required, 'activeDisks' => $active];
}
