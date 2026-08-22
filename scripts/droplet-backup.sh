#!/usr/bin/env bash
# TrueCrew nightly backup — database + private files (Aadhaar PDFs, photos,
# fingerprints are IN the DB; files live on the private disk).
# Keeps 7 days locally in /var/backups/truecrew; wire the S3 block for offsite.
set -euo pipefail

STAMP=$(date +%Y%m%d-%H%M)
DEST=/var/backups/truecrew
mkdir -p "$DEST"

# 1. MySQL dump (credentials from the container's env)
docker exec ams_mysql sh -c 'exec mysqldump --all-databases --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD"' \
  | gzip > "$DEST/db-$STAMP.sql.gz"

# 2. Private disk (documents, photos, proofs, payment proofs)
docker exec ams_backend tar -C /var/www/html/storage/app -czf - private \
  > "$DEST/files-$STAMP.tar.gz"

# 3. Retention: 7 days local
find "$DEST" -name '*.gz' -mtime +7 -delete

# 4. Offsite (enable at production: any S3-compatible bucket)
# s3cmd put "$DEST/db-$STAMP.sql.gz" "$DEST/files-$STAMP.tar.gz" s3://truecrew-backups/ --no-progress

echo "backup ok: $(ls -sh "$DEST"/db-$STAMP.sql.gz "$DEST"/files-$STAMP.tar.gz | tr '\n' ' ')"
