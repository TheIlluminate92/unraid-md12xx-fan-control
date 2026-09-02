# Local integration API

MD12xx Fan Control exposes a same-origin JSON endpoint through the authenticated Unraid WebGUI:

```text
/plugins/md12xx.fancontrol/include/api.php
```

This is a local integration surface, not a separately listening network service or a cloud API. The plugin makes no outbound API calls and never uploads data automatically.

## Security and request rules

- Make requests through an authenticated Unraid WebGUI session.
- Browser integrations should use `credentials: "same-origin"`.
- State-changing `POST` requests must include the current Unraid `csrf_token`.
- Do not expose this endpoint separately from Unraid, forward session cookies, or embed an Unraid password in an integration.
- Responses use `application/json; charset=utf-8` and `Cache-Control: no-store`.
- Timestamps are Unix epoch seconds. Temperatures are Celsius, speeds are percent, and fan telemetry is RPM.
- Poll no faster than the controller telemetry interval. The default and recommended interval is five seconds.

The supported integration contract during Beta is the default status response and the constrained `control` action described below. Setup, discovery, configuration, commissioning, and diagnostic actions are WebGUI internals and may change while the plugin is in Beta.

## Read status

```http
GET /plugins/md12xx.fancontrol/include/api.php
```

`action=status` is accepted but optional. Unknown `GET` actions currently fall back to status; integrations should not depend on that behavior.

Example from another page inside the Unraid WebGUI:

```javascript
const response = await fetch(
  "/plugins/md12xx.fancontrol/include/api.php",
  { cache: "no-store", credentials: "same-origin" }
);
if (!response.ok) throw new Error(`MD12xx status failed: ${response.status}`);
const status = await response.json();
```

Representative response:

```json
{
  "version": "0.4.6",
  "enabled": true,
  "mode": "auto",
  "manualSpeed": 20,
  "allowedManualSpeeds": [20, 30, 40, 50, 60, 70, 80, 90, 100],
  "stale": false,
  "generatedAt": 1788303283,
  "controller": {
    "enabled": true,
    "mode": "auto",
    "manualSpeed": 20,
    "state": "normal",
    "message": "",
    "conflicts": [],
    "dryRun": false,
    "pollSeconds": 5
  },
  "watchdog": {
    "generatedAt": 1788303283,
    "state": "normal",
    "message": "Controller is running under supervision",
    "restartCount": 0,
    "restartDelaySeconds": 0,
    "controllerPid": 12345
  },
  "shelves": [
    {
      "id": "shelf-1",
      "name": "MD1200 Shelf 1",
      "model": "MD1200",
      "enabled": true,
      "commissioned": true,
      "assignedDisks": ["disk1", "disk2"],
      "activeDisks": 2,
      "temperatureC": 31,
      "temperatureSource": "disk2",
      "averageRpm": 3518,
      "fanCount": 4,
      "targetPercent": 20,
      "targetReason": "disk2 31°C",
      "telemetryState": "normal",
      "writeState": "verified",
      "writeMessage": "Independent SES telemetry verified the response"
    }
  ]
}
```

Fields can be added during Beta. Consumers should ignore fields they do not recognize and handle missing or `null` telemetry safely.

### Root fields

| Field | Meaning |
| --- | --- |
| `version` | Installed plugin version. |
| `enabled` | Whether the controller is enabled. |
| `mode` | Current `auto` or `manual` mode. |
| `manualSpeed` | Saved manual target, even when Auto is selected. |
| `allowedManualSpeeds` | Accepted manual targets. |
| `stale` | `true` when current controller state is missing or older than the allowed telemetry window. |
| `generatedAt` | Time at which controller state was generated, or `null`. |
| `controller` | Controller health, conflicts, mode, and polling state. |
| `watchdog` | Supervisor health and restart information. |
| `shelves` | Per-shelf control and independent SES telemetry. |

### Shelf fields

The exact shelf object can grow during Beta. These are the integration fields dashboards should normally use:

| Field | Meaning |
| --- | --- |
| `id`, `name`, `model` | Saved shelf identity and display information. |
| `enabled`, `commissioned` | Whether the shelf may be controlled and whether its hardware pairing passed Identify & test. |
| `assignedDisks` | Current Unraid disk names assigned to the shelf. |
| `activeDisks` | Assigned disks that are currently active. |
| `temperatureC` | Temperature of the hottest active assigned disk. It is not an average. It can be `null` when all assigned disks are spun down. |
| `temperatureSource` | Disk that supplied `temperatureC`, or `null`. |
| `averageRpm` | Average of the nonzero fan RPM readings reported independently by SES. |
| `fanCount` | Number of nonzero fan readings used for RPM telemetry. |
| `targetPercent` | Current fan target. |
| `targetReason` | Human-readable reason for the selected target. |
| `telemetryState` | Shelf telemetry health. Treat anything other than `normal` as needing attention. |
| `writeState`, `writeMessage` | State and explanation of the most recent command/verification cycle. |

A dashboard should prominently surface root `stale`, `controller.state`, each shelf's `telemetryState`, and blocked or failed write states instead of displaying RPM alone.

## Change Auto or Manual mode

```http
POST /plugins/md12xx.fancontrol/include/api.php
Content-Type: application/x-www-form-urlencoded
```

Parameters:

| Parameter | Required | Value |
| --- | --- | --- |
| `action` | yes | `control` |
| `csrf_token` | yes | Current Unraid WebGUI CSRF token |
| `mode` | yes | `auto` or `manual` |
| `speed` | manual only | One of `20`, `30`, `40`, `50`, `60`, `70`, `80`, `90`, or `100` |

Example from a page already running inside the authenticated Unraid WebGUI:

```javascript
const body = new URLSearchParams({
  action: "control",
  csrf_token: String(window.csrf_token || ""),
  mode: "manual",
  speed: "30"
});

const response = await fetch(
  "/plugins/md12xx.fancontrol/include/api.php",
  {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
    },
    body: body.toString()
  }
);

const result = await response.json();
if (!response.ok) throw new Error(result.error || `Control failed: ${response.status}`);
```

Successful response:

```json
{
  "ok": true,
  "status": {
    "version": "0.4.6",
    "enabled": true,
    "mode": "manual",
    "manualSpeed": 30,
    "stale": false,
    "controller": {},
    "watchdog": {},
    "shelves": []
  }
}
```

The saved control change wakes the controller immediately. It does not bypass commissioning, competing-controller detection, serial locking, fail-safe behavior, or independent SES verification.

To return to Auto, send `action=control&mode=auto` with the current CSRF token. `speed` is ignored in Auto mode.

## Errors

| HTTP status | Meaning |
| --- | --- |
| `405` | Request method is not supported. |
| `422` | Missing, malformed, unknown, or unsupported input. |
| `500` | The requested operation was blocked or failed at runtime. |

Error body:

```json
{
  "error": "Human-readable explanation"
}
```

Callers must treat any non-2xx response, invalid JSON, stale state, or missing field as a failure. Never assume a fan command succeeded solely because a request was submitted; use the later SES-backed `writeState`, `writeMessage`, and RPM telemetry.

## WebGUI-internal actions

The Settings page also uses these actions:

| Method | Action | Purpose |
| --- | --- | --- |
| `GET` | `discover` | Read current serial, SES, disk, and pairing discovery state. |
| `GET` | `config` | Read saved configuration. |
| `GET` | `commission&id=...` | Read an Identify & test job. |
| `POST` | `refresh-discovery` | Save discovery options and start one guarded refresh. |
| `POST` | `commission` | Start guarded hardware identification and commissioning. |
| `POST` | `diagnostics` | Create a local redacted diagnostic archive. |
| `POST` | `save` | Validate and save the full Settings form. |

These are documented for transparency, not as a stable third-party API. They enforce controller state, locking, commissioning, and validation rules and should be driven through the plugin's Settings page.

## Privacy and network boundary

The API reads plugin state under `/var/run/md12xx.fancontrol` and saved plugin configuration under `/boot/config/plugins/md12xx.fancontrol`. It does not read array/share file contents, use the Docker socket, contact GitHub, or upload diagnostics.

See [SECURITY.md](../SECURITY.md) for the complete local read/write and network boundary.
