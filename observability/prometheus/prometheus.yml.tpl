global:
  scrape_interval: 5s

scrape_configs:
  - job_name: eventflow-app
    metrics_path: /api/metrics
    authorization:
      type: Bearer
      credentials: __METRICS_TOKEN__
    static_configs:
      - targets:
          - host.docker.internal:8000
