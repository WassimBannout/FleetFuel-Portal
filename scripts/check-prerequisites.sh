#!/usr/bin/env bash
# Read-only checks. Run with: bash scripts/check-prerequisites.sh
set -u

missing=0
for tool in claude docker git make; do
    if command -v "$tool" >/dev/null 2>&1; then
        "$tool" --version
    else
        printf 'Missing required tool: %s\n' "$tool"
        missing=1
    fi
done

if command -v docker >/dev/null 2>&1; then
    if ! docker compose version; then
        printf 'Docker Compose is unavailable.\n'
        missing=1
    fi
    if docker info --format '{{.ServerVersion}}' >/dev/null 2>&1; then
        printf 'Docker daemon is reachable.\n'
    else
        printf 'Docker daemon is not reachable from this shell; check daemon/context/access.\n'
        missing=1
    fi
fi

for tool in php composer node; do
    if command -v "$tool" >/dev/null 2>&1; then
        printf 'Optional host tool available: %s\n' "$tool"
    else
        printf 'Optional host tool absent: %s (use the project containers).\n' "$tool"
    fi
done

printf 'This check does not install tools, authenticate accounts, pull images, or modify data.\n'
exit "$missing"
