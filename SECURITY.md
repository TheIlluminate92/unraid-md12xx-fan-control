# Security

Report security-sensitive issues privately to the repository owner before opening a public issue.

The plugin accepts state-changing requests only through Unraid's authenticated WebGUI PHP environment, which performs session-token validation before the endpoint runs. Configuration values are validated and serial writes are restricted to explicitly configured `/dev/serial/by-id/` paths.

