#!/bin/sh
# Creates the PRIVATE documents bucket (never public, CLAUDE.md rule 3).
set -e
mc alias set local http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"
mc mb --ignore-existing local/infernix-documents
mc anonymous set none local/infernix-documents
