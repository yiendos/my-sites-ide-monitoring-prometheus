# Prometheus

[Prometheus](https://prometheus.io) for [my-sites-ide](https://github.com/yiendos/my-sites-ide):
metrics, with its UI at http://localhost:9090. It scrapes any IDE container that opts in with a
label, and takes remote writes from the Alloy plugin (your apps' OpenTelemetry metrics, and every
IDE container's CPU and memory) and the Tempo plugin (service graphs and span metrics).

Written for: developers running sites in my-sites-ide who want metrics from their sites and the
IDE's services.

## Contents

- [Installation](#installation)
- [What gets scraped](#what-gets-scraped)
- [Command reference](#command-reference)
- [Configuration](#configuration)
- [What it uses from the IDE](#what-it-uses-from-the-ide)
- [Troubleshooting](#troubleshooting)
- [Known gaps](#known-gaps)

## Installation

A [my-sites-ide](https://github.com/yiendos/my-sites-ide) plugin. Add it to the `require` section
of the IDE's `composer.local.json` - or add
[`yiendos/my-sites-ide-preset-monitoring`](https://github.com/yiendos/my-sites-ide-preset-monitoring)
instead, for the whole monitoring stack:

```json
"yiendos/my-sites-ide-monitoring-prometheus": "@dev"
```

Then, from the IDE root:

```
composer update
php my-sites-ide monitoring:prometheus-start
```

Prometheus is opt-in: it doesn't autostart. Start it with `monitoring:prometheus-start`, which
writes its config first. With the
[grafana plugin](https://github.com/yiendos/my-sites-ide-monitoring-grafana) installed, run
`monitoring:grafana-start` afterwards so Grafana gets a Prometheus data source.

## What gets scraped

Scraping is opt-in, but container metrics aren't: with the
[alloy plugin](https://github.com/yiendos/my-sites-ide-monitoring-alloy) running, **every** IDE
container's CPU, memory, network and block IO arrives by remote write, labelled or not (see
[Container metrics](#container-metrics)). The labels below are for a service's own metrics - its
`/metrics` endpoint, or an exporter alongside it.

- **Prometheus itself.**
- **Any container on the IDE's network that opts in with labels.** Prometheus discovers containers
  through the Docker socket, so a plugin or site only has to label its service:

  ```yaml
  labels:
      prometheus.io/scrape: "true"
      prometheus.io/port: "9113"      # optional - the container's exposed port otherwise
      prometheus.io/path: "/metrics"  # optional
  ```

  Targets get `service_name` (the compose service) and `container` labels. The other monitoring
  plugins label themselves, so Grafana, Loki, Tempo and Alloy are scraped once they're running.

Only the IDE's own network (`<project>_my-sites-ide`, read from `docker compose config`) is
watched, so a second IDE checkout's containers aren't picked up.

### Container metrics

Alloy runs cAdvisor and sends its series with `job="integrations/cadvisor"`, labelled
`service_name` (the compose service), `container` and `compose_project`, alongside cAdvisor's `name` and `image`:

```
sum by (service_name) (rate(container_cpu_usage_seconds_total{job="integrations/cadvisor"}[1m]))
container_memory_working_set_bytes{service_name="mysql"}
```

Without the alloy plugin there are none - only the labelled containers above are scraped.

### Remote writes

Remote writes arrive on `http://prometheus:9090/api/v1/write`, and exemplars are kept, so Grafana
can jump from a Tempo span metric to its trace.

## Command reference

| Command | What it does |
|---|---|
| `monitoring:prometheus-start` | Writes `storage/plugins/prometheus/conf/prometheus.yml` from `stubs/prometheus.yml` for the IDE's network, then `docker compose up -d --build prometheus`. Restarts a running Prometheus when the config changed, and recreates one whose compose config changed (e.g. a new `PROMETHEUS_PORT`) |
| `monitoring:prometheus-stop` | `docker compose stop prometheus`, leaving the rest of the IDE running |

## Configuration

| Variable | Default | What it does |
|---|---|---|
| `PROMETHEUS_PORT` | `9090` (this plugin's `.env`) | The host port for the UI. Inside the IDE it's always `prometheus:9090` |
| `PROMETHEUS_RETENTION` | `2d` | How long metrics are kept |
| `DOCKER_SOCKET_GID` | found for you | The group that owns the Docker socket - see [Troubleshooting](#troubleshooting) |

Set any of them in the IDE's root `.env`, which wins over the plugin's defaults, then run
`monitoring:prometheus-start`.
`php my-sites-ide ide:plugin-env yiendos/my-sites-ide-monitoring-prometheus` copies them in,
commented out.

## What it uses from the IDE

| From the IDE | Used for |
|---|---|
| `NAMESPACE` (root `.env`) | the image name, `${NAMESPACE}_prometheus` |
| `IDE_ROOT` (set by the CLI and `_dev/cache/ide.env`) | finding storage |
| `docker compose config` | the IDE network's Docker name |
| `storage/plugins/prometheus/` (`"storage": true`) | the config and the metrics database |
| the `my-sites-ide` network | scraping containers, and being reached by Grafana, Alloy and Tempo |
| the Docker socket, read-only | discovering containers |

## Troubleshooting

**A labelled container isn't a target.** Check it's on the `my-sites-ide` network and has
`prometheus.io/scrape: "true"` (a string, quoted) - http://localhost:9090/service-discovery lists
everything discovered, including what was dropped.

**`permission denied` on `/var/run/docker.sock` in the logs.** Prometheus runs as `nobody` and
joins the socket's group: root (`0`) under Docker Desktop, the host's `docker` group on Linux,
which `monitoring:prometheus-start` looks up. If yours differs, set `DOCKER_SOCKET_GID` to the
socket's group id (`stat -c %g /var/run/docker.sock`) and start it again.

**A container has no CPU or memory metrics.** Those come from the alloy plugin, not from scraping -
check the alloy plugin is installed and running, and run `monitoring:alloy-start` again if Prometheus
was installed after it.

**Port 9090 is already allocated.** Move the UI with `PROMETHEUS_PORT`.

## Known gaps

- Read-only or not, access to the Docker socket is root-equivalent on the Docker host. Fine for a
  local IDE; don't run this anywhere shared.
- No exporters yet for the IDE's other services (nginx, php-fpm, MySQL, Redis), so their own
  metrics (queries, connections) aren't collected - only their CPU and memory, through Alloy. Those
  plugins can add an exporter and label it, with no change here.
- On Linux hosts, `storage/plugins/prometheus/` is created by your user while Prometheus runs as
  `nobody`, so it may not be able to write there. Docker Desktop on macOS maps ownership, so it
  isn't affected.
