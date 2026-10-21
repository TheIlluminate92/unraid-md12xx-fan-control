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

// Before pairing is proven, monitor disks mapped to every candidate enclosure.
// Do not trust a stale manual shelf assignment during identification.
function md12xx_commission_temperature_policy(array $config, array $disks): array
{
    $safe = count($disks) > 0;
    $required = 20;
    $active = 0;
    foreach ($disks as $disk) {
        $asleep = in_array(strtolower(trim((string) ($disk['spundown'] ?? ''))), ['1', 'yes', 'true', 'on'], true)
            || str_contains(strtolower((string) ($disk['color'] ?? '')), 'blink');
        if ($asleep) continue;
        $active++;
        $raw = $disk['temp'] ?? null;
        if (!is_numeric($raw) || (float) $raw <= 0) { $safe = false; $required = max($required, (int) $config['sensorFailureSpeed']); continue; }
        $speed = (int) $config['curve'][0]['speed'];
        foreach ($config['curve'] as $step) if ((float) $raw >= $step['temperatureC']) $speed = max($speed, (int) $step['speed']);
        $required = max($required, $speed);
        if ((float) $raw >= 35 || $speed > 20) $safe = false;
    }
    if (!$disks) $required = max($required, (int) $config['sensorFailureSpeed']);
    // The current configuration may be manual, but commissioning must not
    // restore below its saved manual target if the test is interrupted.
    if (($config['mode'] ?? '') === 'manual') $required = max($required, (int) $config['manualSpeed']);
    return ['safe' => $safe && $required === 20, 'restoreSpeed' => $required, 'activeDisks' => $active];
}
