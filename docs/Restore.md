# Restore from backup

1) The first step is recovering all Helm chart.
   Be careful, if you want to recover only selected items (like only one processing) - will better just reassign the user to processing again and skip this step.
    * In case if you want to restore all helm chart you need to apply `data/chart.yaml`
    * or you can edit this file and apply only part of items which you lost
2) To restore data from a saved backup, you need to run `backup/restore.sh` with the `-namespace` parameter
(the namespace in which Manticore Streams is deployed).

Backups are stored in the `backup / data` folder
* database.sql - backup of UI pod
* indexes-mkc-m{N}-manticore.tar.gz - stream's backup where stream id = `N` 


Recovery takes place gradually, stream after stream. 
The script will suspend the current Stream worker to stop new messages receiving. The next step will scale Manticore statefulset for 
correct backup uploading, and will upload itself. 

The final step will restore the UI dump, which includes processing and user settings.

In case of recovery problems, you can simply restart the script and the whole process will start over
