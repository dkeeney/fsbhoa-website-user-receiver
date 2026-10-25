#!/bin/bash
set -euo pipefail

DEST_DIR="/mnt/shared/Automation/plugins"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="$(basename "${PLUGIN_DIR}")"
ZIP_FILE="${DEST_DIR}/${PLUGIN_NAME}.zip"

# Ensure target mount/directory exists
if [ ! -d "${DEST_DIR}" ]; then
    echo "Creating target directory: ${DEST_DIR}"
    mkdir -p "${DEST_DIR}"
fi

# Remove previous zip if it exists
if [ -f "${ZIP_FILE}" ]; then
    rm -f "${ZIP_FILE}"
fi

# CD to the parent folder so the ZIP packages cleanly with the folder root
cd "${PLUGIN_DIR}/.."

echo "Packaging ${PLUGIN_NAME} into ${ZIP_FILE}..."

zip -r "${ZIP_FILE}" "${PLUGIN_NAME}" \
    -x "${PLUGIN_NAME}/.git*" \
    -x "${PLUGIN_NAME}/*~" \
    -x "${PLUGIN_NAME}/build-zip.sh" \
    -x "${PLUGIN_NAME}/.DS_Store" \
    -x "${PLUGIN_NAME}/*.swp"

echo "Done! Created: ${ZIP_FILE}"

