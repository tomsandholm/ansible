#! /bin/bash

# Ensure you are logged in with jf cli: jf rt lg

REPO="YOUR_REPO_NAME"
FOLDER="YOUR_FOLDER_PATH"
AQL_SPEC="files_to_delete.aql"

echo "Finding files in $REPO/$FOLDER to delete..."

# Run the AQL query to get the list of files to delete (skipping the newest two)
# The --spec file includes offset: 2
jf rt del --dry-run=true --spec="$AQL_SPEC" --quiet

# Remove the --dry-run=true flag to perform the actual deletion.
# jf rt del --spec="$AQL_SPEC" --quiet 

