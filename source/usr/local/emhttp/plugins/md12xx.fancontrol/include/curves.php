<?php
declare(strict_types=1);
function cooling_default_curve(): array {
    return [['temperatureC' => 0.0, 'speed' => 20], ['temperatureC' => 35.0, 'speed' => 25], ['temperatureC' => 45.0, 'speed' => 30], ['temperatureC' => 50.0, 'speed' => 50]];
}
function cooling_validate_curve($curve): array {
    if (!is_array($curve) || count($curve) < 2 || count($curve) > 10) throw new InvalidArgumentException('A fan curve needs 2–10 points');
    $result = [];
    foreach ($curve as $step) {
        if (!is_array($step) || !is_numeric($step['temperatureC'] ?? null) || !is_numeric($step['speed'] ?? null)) throw new InvalidArgumentException('Each point needs a temperature and speed');
        $temp = (float) $step['temperatureC']; $speed = (int) $step['speed'];
        if (!is_finite($temp) || $temp < 0 || $temp > 100 || $speed < 20 || $speed > 100) throw new InvalidArgumentException('Curve temperatures must be 0–100°C and speeds 20–100%');
        $result[] = ['temperatureC' => $temp, 'speed' => $speed];
    }
    usort($result, static fn(array $a, array $b): int => $a['temperatureC'] <=> $b['temperatureC']);
    for ($i = 1; $i < count($result); $i++) if ($result[$i]['temperatureC'] <= $result[$i - 1]['temperatureC'] || $result[$i]['speed'] < $result[$i - 1]['speed']) throw new InvalidArgumentException('Temperatures must increase; fan speeds cannot decrease');
    return $result;
}
function cooling_device_curve(array $device, array $global): array {
    return ($device['curveMode'] ?? 'global') === 'individual' ? cooling_validate_curve($device['curve'] ?? $global) : $global;
}
function cooling_curve_speed(array $curve, float $temperature): int {
    $speed = (int) $curve[0]['speed'];
    foreach ($curve as $step) if ($temperature >= $step['temperatureC']) $speed = max($speed, (int) $step['speed']);
    return $speed;
}
