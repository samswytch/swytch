#!/usr/bin/env bash
#
# Deploy to names.co.uk shared hosting over SSH.
#
#   ./deploy.sh            # show what would change, touch nothing
#   ./deploy.sh --live     # actually copy
#
# Two destinations, because only one of them is web-servable:
#
#   coverapp/                 the application, the brand packs, the CLI scripts
#   plan.swytch.graphics/     the front controller and the assets
#
# Never touches appdata/ — config.php, the database and the backups live there
# and are not part of the deploy.

set -euo pipefail

SSH_HOST="${COVER_SSH_HOST:-lhwp4008.webapps.net}"
SSH_PORT="${COVER_SSH_PORT:-25088}"
SSH_USER="${COVER_SSH_USER:-om44wfu4}"
HOME_DIR="/home/om44wfu4"
DOCROOT="${HOME_DIR}/plan.swytch.graphics"
APPDIR="${HOME_DIR}/coverapp"

DRY_RUN="--dry-run"
LABEL="DRY RUN — nothing will be written"
if [[ "${1:-}" == "--live" ]]; then
  DRY_RUN=""
  LABEL="LIVE — copying for real"
fi

SSH="ssh -p ${SSH_PORT}"
REMOTE="${SSH_USER}@${SSH_HOST}"

echo "${LABEL}"
echo "  host    ${REMOTE}:${SSH_PORT}"
echo "  app  -> ${APPDIR}"
echo "  web  -> ${DOCROOT}"
echo

# --- the application, above the web root ------------------------------------
rsync -az --delete ${DRY_RUN} --itemize-changes \
  -e "${SSH}" \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude 'config.php' \
  --exclude 'deploy.sh' \
  --exclude 'dev-router.php' \
  --exclude 'config.example.php' \
  --exclude 'public' \
  --exclude 'README.md' \
  --exclude 'BRIEF.md' \
  --exclude 'CLAUDE.md' \
  ./ "${REMOTE}:${APPDIR}/"

# --- the web root -----------------------------------------------------------
# No --delete here: the certificate tooling and the panel sometimes leave files
# of their own in a docroot, and removing them is not this script's business.
rsync -az ${DRY_RUN} --itemize-changes \
  -e "${SSH}" \
  public/ "${REMOTE}:${DOCROOT}/"

echo
if [[ -n "${DRY_RUN}" ]]; then
  echo "That was a dry run. Re-run with --live to copy."
else
  echo "Copied. Now, over SSH:"
  echo "  ssh -p ${SSH_PORT} ${REMOTE}"
  echo "  php ${APPDIR}/bin/setup-db.php      # first deploy only"
  echo
  echo "Then open https://plan.swytch.graphics/health and check every row reads ok."
fi
