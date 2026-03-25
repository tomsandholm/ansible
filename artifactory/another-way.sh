#!/bin/bash

REPO="DCO-FW"
FOLDER_PATH="librebooking"
KEEP_COUNT=2

# 1. Use AQL to find all files in the specified path, sorted by creation date (ascending)
# We select all items, sort by 'created' and limit the results to those we want to *keep*
# The items NOT in this list are the ones to be deleted.
files_to_keep=$(jfrog rt s "$REPO/$FOLDER_PATH/*" --sort-by created --limit $KEEP_COUNT --json | jfrog rt search --spec - | jq -r '.[] | .path + "/" + .name')

# 2. Use AQL again to find ALL files and filter out the ones to keep (requires some scripting logic)
# A more robust approach would be to find all files and use scripting to determine which ones are *not* in the keep list.

# Alternative approach: Find all files and use AQL with a custom script logic. 
# This requires external scripting to manage the count logic.

# A common and practical method is to:
# a. Get all file paths and creation timestamps
# b. Sort them and determine which ones to delete
# c. Create a FileSpec for the files to delete and run jfrog rt del

# Example using AQL query directly with a bit of scripting:
# This script will find all files in the specified path and delete the oldest ones beyond the KEEP_COUNT.

echo "Finding files in $REPO/$FOLDER_PATH, keeping the $KEEP_COUNT newest..."

# Query all files, sorted by creation time descending (newest first)
aql_query="items.find({\"repo\": \"$REPO\", \"path\": \"$FOLDER_PATH\", \"type\": \"file\"}).sort({\"\$desc\" : [\"created\"]})"

# Execute AQL and capture the full file paths
all_files=$(jfrog rt curl -XPOST /api/search/aql -H "Content-Type: text/plain" -d "$aql_query" | jq -r '.results[] | .repo + "/" + .path + "/" + .name')

# Convert to an array
mapfile -t file_array <<< "$all_files"

# Check the total count
total_files=${#file_array[@]}

if [ "$total_files" -gt "$KEEP_COUNT" ]; then
    files_to_delete_count=$((total_files - KEEP_COUNT))
    echo "Total files: $total_files. Will delete $files_to_delete_count oldest files."

    # Loop through the files that need to be deleted (the oldest ones at the end of the array if we sort ascending)
    # The previous AQL was descending, so the files to delete are from index $KEEP_COUNT to the end
    for ((i=$KEEP_COUNT; i<$total_files; i++)); do
        file_path=${file_array[$i]}
        echo "Deleting: $file_path"
        # Run delete command
        # jfrog rt del "$file_path" --quiet --fail-no-op=true
    done
    echo "Deletion process complete. Rerun without echo to perform actual deletion."
else
    echo "Total files ($total_files) is not greater than $KEEP_COUNT. No files to delete."
fi

