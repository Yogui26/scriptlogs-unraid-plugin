<h1><img src="source/scriptlogs.png" alt="ScriptLogs icon" width="42"> ScriptLogs for Unraid</h1>

> Fork of [jo-sobo/scriptlogs-unraid-plugin](https://github.com/jo-sobo/scriptlogs-unraid-plugin). It keeps everything from the original plugin and adds the ability to **start scripts** and **open their full log** from the dashboard tile. See [What This Fork Adds](#what-this-fork-adds).

ScriptLogs adds a compact dashboard widget for selected User Scripts. It shows script status and log output directly on the Unraid Dashboard, and lets you start a script in the background, without keeping the User Scripts page open.

## What This Fork Adds

| Addition | Where | What it does |
| --- | --- | --- |
| **Run** button | Expanded tile, below the log | Starts the script of the selected tab in the background, after a confirmation prompt. Disabled while that script is running. |
| Play control (▶) | Collapsed tile, on each idle script chip | Starts that script in the background, after the same confirmation prompt. Not shown on running scripts. |
| **Full log** button | Expanded tile, next to **Run** | Opens the whole background log of the selected script as plain text in a new browser tab. The tile itself only shows the last 100 lines. |

Scripts are always started **in the background**, exactly like the **Run In Background** button of User Scripts, so they keep running after the dashboard is closed. There is no stop button: use **Abort Script** on the User Scripts page.

Other changes:

- The plugin installs and updates from this repository instead of the upstream one.
- `build.sh` works with GNU tar (Linux) as well as bsdtar (macOS), and the package is served from `packages/` on `main`, so no GitHub release is needed.

## Screenshots

The screenshots below come from the original plugin and do not show the buttons added by this fork.

<p>
  <img src="scriptlogs_screenshot_slim.png" alt="Compact ScriptLogs dashboard widget" width="48%">
  <img src="scriptlogs_screenshot_running.png" alt="Running script in the ScriptLogs dashboard widget" width="48%">
</p>
<p>
  <img src="scriptlogs_screenshot_idle.png" alt="Idle script in the ScriptLogs dashboard widget" width="48%">
  <img src="scriptlogs_screenshot_scroll.png" alt="Scrollable ScriptLogs log output" width="48%">
</p>

## Features

- Dashboard tile for selected User Scripts.
- Color-coded script tabs for running and idle scripts.
- Collapsed dashboard state with compact status chips.
- Custom dashboard order for selected scripts.
- Scrollable log viewer with optional auto-scroll.
- Live log output for scripts running in the background.
- **Run** button to start the selected script in the background, with a confirmation prompt (this fork).
- Play control on each idle script chip when the tile is collapsed (this fork).
- **Full log** button on the expanded tile to open the whole log of the selected script in a new tab (this fork).
- Optional display of the last known background log for idle scripts.
- Configurable refresh interval.
- Configurable log font size.
- Optional removal of empty log lines.
- Light and dark theme support.
- Dedicated settings page with compact script selection and drag handles.

## Requirements

- Unraid 6.9 or newer.
- [User Scripts](https://forums.unraid.net/topic/48286-plugin-user-scripts/) by Andrew Zawadzki.

## Installation

The original plugin is available through Unraid Community Applications; this fork is installed manually. It uses the same plugin name, so it replaces the original plugin if it is installed, and existing settings are kept.

1. Open the Unraid web interface.
2. Go to **Plugins**.
3. Click **Install Plugin**.
4. Paste this URL and install:

   ```text
   https://raw.githubusercontent.com/Yogui26/scriptlogs-unraid-plugin/main/scriptlogs.plg
   ```

Later versions are picked up by **Plugins > Check for Updates**.

## Configuration

Open **Settings > User Utilities > ScriptLogs Settings** in the Unraid web interface.

Available settings:

- **Automatic refresh:** Enable or disable dashboard updates.
- **Refresh interval:** Set how often the widget checks script status and logs.
- **Idle script logs:** Show the last saved background log when a script is idle.
- **Log font size:** Choose the text size used in the dashboard log viewer.
- **Empty log lines:** Remove or preserve blank lines in log output.
- **Script selection:** Choose which User Scripts appear in the dashboard widget.
- **Dashboard order:** Drag selected scripts into the preferred tab order.

Click **Apply** to save changes. The dashboard widget updates on the next refresh.

The script selection also defines which scripts can be started and whose full log can be opened from the dashboard. The additions of this fork have no settings of their own.

## How It Works

The dashboard widget polls a local plugin API at the configured refresh interval.

Status detection uses the User Scripts runtime state:

- Foreground scripts are detected from the process list via `startScript.sh`.
- Background scripts are detected through `/tmp/user.scripts/running/`.

Log output is read from User Scripts log files in:

```text
/tmp/user.scripts/tmpScripts/<script-name>/log.txt
```

ScriptLogs tails the most recent log output, optionally removes empty lines, and displays it in the dashboard tile. It preserves the selected tab across refreshes when possible. Foreground scripts still write their live output to the User Scripts window, so ScriptLogs shows a short notice instead of duplicating that stream.

### Starting a Script (this fork)

The **Run** button and the play control on the chips ask for confirmation, then send a CSRF-protected `POST` (`action=run_script`) to the plugin API. The API starts the script the same way the **Run In Background** button of User Scripts does: it copies the script to `/tmp/user.scripts/tmpScripts/<script-name>/script` and hands it to User Scripts' `backgroundScript.sh`, which detaches it with `at`. The tile then shows the script as running and follows its log.

A launch is refused when:

- the script is not selected in the ScriptLogs settings;
- the script is already running, in the foreground or in the background;
- the script is marked `foregroundOnly`;
- the script is marked `arrayStarted` and the array is stopped;
- the script name contains shell metacharacters (such as `;`, `$`, `&`, quotes or parentheses).

Each launch is written to the syslog with the `scriptlogs` tag.

### Full Log (this fork)

The **Full log** button opens `scriptlogs_api.php?action=get_full_log&script=<script-name>` in a new browser tab, which returns the whole `log.txt` of the script as plain text. Reload the tab to see new output while the script is running.

Only scripts selected in the ScriptLogs settings are served. User Scripts writes `log.txt` for background runs only and keeps it in `/tmp`, so no log is available for a script that has only run in the foreground, or that has not run since the last boot.

## Support

For the additions of this fork (Run button, play control, Full log button), use this repository:

https://github.com/Yogui26/scriptlogs-unraid-plugin

For the original plugin, use the Unraid forum thread and the upstream repository. Please do not report problems with this fork there.

- https://forums.unraid.net/topic/192397-plugin-scriptlogs
- https://github.com/jo-sobo/scriptlogs-unraid-plugin/issues

## Acknowledgments

Thanks to jo-sobo for creating ScriptLogs, and to Andrew Zawadzki for creating and maintaining the User Scripts plugin.

## Donation

If ScriptLogs is useful to you, donations to its original author are welcome:

https://coff.ee/magnum.308

## License

This project is licensed under the GPL v3. See [LICENSE](LICENSE) for details.
