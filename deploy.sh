#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TARGET_HOST="ssh.oshyan.com"
TARGET_PORT="18765"
TARGET_USER="u1698-inffg4l0kuxe"
TARGET_PATH="/home/customer/www/oshyan.com/public_html/tides"

# Use rs-roadtrip SSH key (same server)
SSH_KEY="/Users/oshyan/Projects/Coding/RedwoodShire/rs-roadtrip/ssh_keys/rs-roadtrip_ed25519"

DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --dry-run)
      DRY_RUN=1
      ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: $0 [--dry-run]"
      exit 1
      ;;
  esac
done

if [[ ! -f "$SSH_KEY" ]]; then
  echo "SSH key not found: $SSH_KEY"
  exit 1
fi

RSYNC_FLAGS=(-rltvz --delete)
if [[ $DRY_RUN -eq 1 ]]; then
  RSYNC_FLAGS+=(--dry-run)
  echo "=== DRY RUN ==="
fi

EXCLUDES=(
  --exclude '.git'
  --exclude '.DS_Store'
  --exclude '.fastembed_cache'
  --exclude '.ygrep'
  --exclude 'node_modules'
  --exclude 'vendor'
  --exclude 'data/'
  --exclude 'cache/'
  --exclude 'logs/'
)

echo "Deploying TideCal to $TARGET_HOST:$TARGET_PATH"

rsync "${RSYNC_FLAGS[@]}" "${EXCLUDES[@]}" \
  -e "ssh -i $SSH_KEY -p $TARGET_PORT" \
  "$ROOT_DIR/" \
  "$TARGET_USER@$TARGET_HOST:$TARGET_PATH/"

echo "Setting permissions..."
ssh -i "$SSH_KEY" -p "$TARGET_PORT" "$TARGET_USER@$TARGET_HOST" \
  "find '$TARGET_PATH' -type d -exec chmod 755 {} \; && find '$TARGET_PATH' -type f -exec chmod 644 {} \;"

echo "Deploy complete!"
